<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Expense Service
 * ---------------------------------------------------------------------------
 * Write path for the shared ledger.
 *
 * The important part is split computation. `expenses` stores the header and
 * `expense_splits` stores one materialised row per person, so:
 *   * a person's history is a single indexed lookup, not a join across the
 *     whole ledger;
 *   * the amounts that were agreed at the time of the expense are frozen even
 *     if the split rules change later;
 *   * SUM(share_amount) can be asserted to equal expenses.amount exactly.
 *
 * Money is allocated in integer cents (see Money::allocate / Money::weighted)
 * so the invariant holds with no rounding drift, ever.
 */

declare(strict_types=1);

final class ExpenseService
{
    public const SPLIT_TYPES = ['equal', 'selective', 'shares', 'meal_based'];

    /* ================================================================== */
    /*  Create                                                            */
    /* ================================================================== */

    /**
     * Log an expense and materialise its splits in one transaction.
     *
     * @param array $input {
     *   title, amount, paid_by_user_id, category_id, split_type,
     *   expense_date, description, user_ids?, weights?, meal_scope?
     * }
     */
    public static function create(int $apartmentId, int $actorId, array $input): array
    {
        $activeIds = self::activeResidentIds($apartmentId);
        if ($activeIds === []) {
            throw new RuntimeException('There are no active residents to split between.');
        }

        $amountCents = Money::toCents($input['amount']);
        if ($amountCents <= 0) {
            throw new ValidationException(['amount' => 'Amount must be greater than zero.']);
        }

        $payer = (int) $input['paid_by_user_id'];
        if (!in_array($payer, $activeIds, true)) {
            throw new ValidationException(['paid_by_user_id' => 'The payer must be an active resident.']);
        }

        $splitType = (string) ($input['split_type'] ?? 'equal');
        if (!in_array($splitType, self::SPLIT_TYPES, true)) {
            throw new ValidationException(['split_type' => 'Unknown split type.']);
        }

        $date = (string) ($input['expense_date'] ?? gmdate('Y-m-d'));
        if (!self::isValidDate($date)) {
            throw new ValidationException(['expense_date' => 'Use a YYYY-MM-DD date.']);
        }

        $categoryId = isset($input['category_id']) && (int) $input['category_id'] > 0
            ? (int) $input['category_id'] : null;

        $isMealRelated = false;
        if ($categoryId !== null) {
            $isMealRelated = (int) Database::value(
                'SELECT is_meal_related FROM expense_categories WHERE id = :id AND apartment_id = :a',
                ['id' => $categoryId, 'a' => $apartmentId]
            ) === 1;
        }
        if ($splitType === 'meal_based') {
            $isMealRelated = true;
        }

        // ---- resolve the split plan ---------------------------------------
        [$shares, $meta] = self::resolveSplit(
            $splitType,
            $amountCents,
            $activeIds,
            $apartmentId,
            $input
        );

        if ($shares === []) {
            throw new ValidationException(['split' => 'No one was selected to share this expense.']);
        }

        // ---- persist -------------------------------------------------------
        return Database::transaction(static function () use (
            $apartmentId, $actorId, $input, $amountCents, $payer, $categoryId,
            $splitType, $meta, $shares, $date, $isMealRelated, $activeIds
        ) {
            $expenseId = Database::insert('expenses', [
                'apartment_id'    => $apartmentId,
                'reference_no'    => self::nextReference($apartmentId),
                'title'           => trim((string) $input['title']),
                'description'     => $input['description'] ?? null,
                'amount'          => Money::toAmount($amountCents),
                'currency_code'   => (string) config('app.currency_code', 'EUR'),
                'paid_by_user_id' => $payer,
                'category_id'     => $categoryId,
                'split_type'      => $splitType,
                'split_meta'      => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'expense_date'    => $date,
                'is_meal_related' => $isMealRelated ? 1 : 0,
                'created_by'      => $actorId,
            ]);

            foreach ($shares as $userId => $cents) {
                Database::insert('expense_splits', [
                    'expense_id'   => $expenseId,
                    'user_id'      => $userId,
                    'share_amount' => Money::toAmount($cents),
                    'weight'       => $splitType === 'shares'
                        ? (float) (self::sanitiseWeights($input['weights'] ?? [], $activeIds)[$userId] ?? 0)
                        : 1.0,
                ]);
            }

            // Hard invariant: splits must reconstruct the amount exactly.
            $sum = array_sum($shares);
            if ($sum !== $amountCents) {
                throw new RuntimeException(sprintf(
                    'Split drift detected: %d cents allocated vs %d expected.',
                    $sum,
                    $amountCents
                ));
            }

            $payerName = (string) Database::value('SELECT full_name FROM users WHERE id = :id', ['id' => $payer]);
            $label     = match ($splitType) {
                'equal'       => 'split equally between ' . count($shares) . ' people',
                'selective'   => 'split between ' . count($shares) . ' people',
                'shares'      => 'split by shares',
                'meal_based'  => 'split between ' . count($shares) . ' people who ate',
                default       => 'split',
            };
            ActivityLog::record(
                'expense.created', 'expense', $expenseId,
                sprintf('%s logged "%s" — %s %s', $payerName, $input['title'], money($shares ? $amountCents / 100 : 0), $label),
                ['split_type' => $splitType, 'heads' => count($shares), 'amount_cents' => $amountCents]
            );

            return self::find($expenseId);
        });
    }

    /**
     * Turn a split rule into concrete per-person cent amounts.
     *
     * @return array{0:array<int,int>, 1:?array}
     */
    private static function resolveSplit(
        string $splitType,
        int $amountCents,
        array $activeIds,
        int $apartmentId,
        array $input
    ): array {
        switch ($splitType) {

            // -------- 1. everyone, equal heads ----------------------------
            case 'equal':
                $allocated = Money::allocate($amountCents, count($activeIds));
                $shares = [];
                foreach (array_values($activeIds) as $i => $uid) {
                    $shares[$uid] = $allocated[$i];
                }
                return [$shares, ['heads' => count($activeIds), 'rule' => 'equal']];

            // -------- 2. an explicit subset, equal heads -------------------
            case 'selective':
                $ids = self::sanitiseIds($input['user_ids'] ?? [], $activeIds);
                if ($ids === []) {
                    throw new ValidationException(['user_ids' => 'Pick at least one person to share this with.']);
                }
                $allocated = Money::allocate($amountCents, count($ids));
                $shares = [];
                foreach (array_values($ids) as $i => $uid) {
                    $shares[$uid] = $allocated[$i];
                }
                return [$shares, ['selected' => array_values($ids), 'rule' => 'selective']];

            // -------- 3. weighted shares (2/8, 1/8, …) ---------------------
            case 'shares':
                $weights = self::sanitiseWeights($input['weights'] ?? [], $activeIds);
                if ($weights === []) {
                    throw new ValidationException(['weights' => 'Give at least one resident a share.']);
                }
                $uids   = array_keys($weights);
                $wts    = array_values($weights);
                $result = Money::weighted($amountCents, $wts);
                $shares = [];
                foreach (array_values($uids) as $i => $uid) {
                    $shares[$uid] = $result[$i];
                }
                return [$shares, ['shares' => $weights, 'rule' => 'shares']];

            // -------- 4. only who actually ate -----------------------------
            case 'meal_based':
                $eaters = self::eatersFor($apartmentId, $input, $activeIds);
                if ($eaters === []) {
                    throw new ValidationException([
                        'split' => 'Nobody has opted in as eating for that window. '
                                 . 'Use an equal or selective split instead.',
                    ]);
                }
                $allocated = Money::allocate($amountCents, count($eaters));
                $shares = [];
                foreach (array_values($eaters) as $i => $uid) {
                    $shares[$uid] = $allocated[$i];
                }
                return [$shares, [
                    'rule'     => 'meal_based',
                    'source'   => $input['meal_scope'] ?? 'optin',
                    'eaters'   => array_values($eaters),
                    'excluded' => array_values(array_diff($activeIds, $eaters)),
                ]];
        }

        throw new ValidationException(['split_type' => 'Unknown split type.']);
    }

    /**
     * Who counts as an eater for the configured window.
     *
     * meal_scope:
     *   'week' | 'current_week' -> next 7 days of meal slots
     *   'explicit'             -> use input.user_ids as the eater list
     *   default                -> everyone who has NOT opted out of anything
     *                            in the current week
     */
    private static function eatersFor(int $apartmentId, array $input, array $activeIds): array
    {
        $scope = (string) ($input['meal_scope'] ?? 'current_week');

        if ($scope === 'explicit') {
            return self::sanitiseIds($input['user_ids'] ?? [], $activeIds);
        }

        $weekStart = DutyScheduler::weekStart((string) ($input['expense_date'] ?? gmdate('Y-m-d')));
        $weekEnd   = gmdate('Y-m-d', strtotime($weekStart . ' +6 days'));

        $rows = Database::all(
            "SELECT mp.user_id
               FROM meal_participants mp
               JOIN meals m       ON m.id = mp.meal_id
               JOIN meal_plans p  ON p.id = m.meal_plan_id
              WHERE p.apartment_id = :a
                AND m.day_of_week  = DAYOFWEEK(CONCAT(:ws, ' 00:00:00')) % 7
                AND mp.status = 'eating'",
            ['a' => $apartmentId, 'ws' => $weekStart]
        );

        if ($rows !== []) {
            $ids = array_map('intval', array_column($rows, 'user_id'));
            return array_values(array_intersect($activeIds, $ids));
        }

        // Fallback: anyone who has not explicitly opted out this week.
        $optedOut = array_map('intval', array_column(
            Database::all(
                "SELECT DISTINCT mp.user_id
                   FROM meal_participants mp
                   JOIN meals m      ON m.id = mp.meal_id
                   JOIN meal_plans p ON p.id = m.meal_plan_id
                  WHERE p.apartment_id = :a
                    AND p.week_start BETWEEN :from AND :to
                    AND mp.status = 'opting_out'",
                ['a' => $apartmentId, 'from' => $weekStart, 'to' => $weekEnd]
            ),
            'user_id'
        ));

        return array_values(array_diff($activeIds, $optedOut));
    }

    /* ================================================================== */
    /*  Settle                                                             */
    /* ================================================================== */

    /**
     * Log a payout. This reduces the payer's net debt and raises the payee's
     * net credit, which is exactly what moving real money does.
     */
    public static function settle(int $apartmentId, int $actorId, array $input): array
    {
        $from = (int) $input['from_user_id'];
        $to   = (int) $input['to_user_id'];
        $cents = Money::toCents($input['amount']);

        if ($from === $to) {
            throw new ValidationException(['to_user_id' => 'Someone cannot settle with themselves.']);
        }
        if ($cents <= 0) {
            throw new ValidationException(['amount' => 'Settlement amount must be greater than zero.']);
        }

        $members = BalanceEngine::memberBalances($apartmentId);
        if (!isset($members[$from]) || !isset($members[$to])) {
            throw new ValidationException(['to_user_id' => 'Both people must belong to this apartment.']);
        }
        if ($members[$from]['net_cents'] >= 0) {
            throw new ValidationException([
                'from_user_id' => $members[$from]['full_name'] . ' is not currently in debt, so there is nothing to settle.',
            ]);
        }
        if ($members[$to]['net_cents'] <= 0) {
            throw new ValidationException([
                'to_user_id' => $members[$to]['full_name'] . ' is not owed money, so there is nothing to collect.',
            ]);
        }
        if ($cents > abs($members[$from]['net_cents'])) {
            throw new ValidationException([
                'amount' => sprintf(
                    'That is more than %s owes (%s). Enter %s or less.',
                    $members[$from]['full_name'],
                    Money::format(abs($members[$from]['net_cents'])),
                    Money::format(abs($members[$from]['net_cents']))
                ),
            ]);
        }

        $method = (string) ($input['method'] ?? 'cash');
        if (!in_array($method, ['cash', 'bkash', 'nagad', 'bank', 'other'], true)) {
            $method = 'cash';
        }

        $settlementId = Database::insert('settlements', [
            'apartment_id' => $apartmentId,
            'from_user_id' => $from,
            'to_user_id'   => $to,
            'amount'       => Money::toAmount($cents),
            'method'       => $method,
            'reference'    => $input['reference'] ?? null,
            'note'         => $input['note'] ?? null,
            'settled_at'   => (string) ($input['settled_at'] ?? gmdate('Y-m-d')),
            'created_by'   => $actorId,
        ]);

        // Flag the underlying splits as settled once the payer is square.
        $stillOwes = abs($members[$from]['net_cents']) - $cents;
        if ($stillOwes <= 0) {
            Database::query(
                'UPDATE expense_splits es
                   JOIN expenses e ON e.id = es.expense_id
                  SET es.is_settled = 1, es.settled_at = UTC_TIMESTAMP()
                WHERE es.user_id = :u1 AND e.apartment_id = :a AND e.is_deleted = 0 AND es.is_settled = 0',
                ['u1' => $from, 'a' => $apartmentId]
            );
        }

        ActivityLog::record(
            'settle.paid', 'settlement', $settlementId,
            sprintf('%s paid %s %s via %s',
                $members[$from]['full_name'],
                $members[$to]['full_name'],
                Money::format($cents),
                $method),
            ['method' => $method, 'amount_cents' => $cents]
        );

        return self::settlement($settlementId);
    }

    public static function delete(int $expenseId, int $actorId): void
    {
        $expense = Database::one(
            'SELECT id, apartment_id, paid_by_user_id, created_by, title FROM expenses WHERE id = :id',
            ['id' => $expenseId]
        );
        if ($expense === null) {
            throw new RuntimeException('That expense no longer exists.');
        }
        $isAdmin = Auth::isAdmin();
        if (!$isAdmin && (int) $expense['paid_by_user_id'] !== $actorId && (int) $expense['created_by'] !== $actorId) {
            throw new RuntimeException('You can only remove expenses you logged.');
        }

        // Soft delete: keeps the audit trail intact.
        Database::update('expenses', ['is_deleted' => 1], 'id', $expenseId);
        ActivityLog::record('expense.deleted', 'expense', $expenseId, (string) $expense['title']);
    }

    public static function flagDispute(int $expenseId, int $actorId, ?string $note, bool $isAdmin): array
    {
        $expense = Database::one('SELECT * FROM expenses WHERE id = :id AND is_deleted = 0', ['id' => $expenseId]);
        if ($expense === null) {
            throw new RuntimeException('That expense no longer exists.');
        }
        $isParty = Database::value(
            'SELECT 1 FROM expense_splits WHERE expense_id = :e AND user_id = :u1',
            ['e' => $expenseId, 'u1' => $actorId]
        ) !== null;

        if (!$isAdmin && !$isParty) {
            throw new RuntimeException('Only someone who shared this expense can raise a dispute.');
        }

        Database::update('expenses', [
            'is_disputed'  => $note === null ? 0 : 1,
            'dispute_note' => $note,
        ], 'id', $expenseId);

        ActivityLog::record('expense.disputed', 'expense', $expenseId, $note);

        if ($note !== null) {
            $apartmentId = (int) $expense['apartment_id'];
            foreach (array_column(
                Database::all("SELECT id FROM users WHERE apartment_id = :a AND role = 'admin' AND id <> :u1",
                    ['a' => $apartmentId, 'u1' => $actorId]),
                'id'
            ) as $adminId) {
                Reminder::push($apartmentId, (int) $adminId, 'balance',
                    'Dispute on "' . $expense['title'] . '"',
                    mb_substr((string) $note, 0, 300), 'warning', 'expenses', $expenseId);
            }
        }

        return self::find($expenseId);
    }

    /* ================================================================== */
    /*  Read                                                               */
    /* ================================================================== */

    public static function find(int $expenseId): array
    {
        $row = Database::one(
            'SELECT e.*,
                    c.name AS category_name, c.icon AS category_icon, c.is_meal_related AS cat_meal,
                    p.full_name AS paid_by_name, p.avatar_color AS paid_by_avatar,
                    p.participant_code AS paid_by_code,
                    cr.full_name AS created_by_name
               FROM expenses e
               LEFT JOIN expense_categories c ON c.id = e.category_id
               LEFT JOIN users p  ON p.id = e.paid_by_user_id
               LEFT JOIN users cr ON cr.id = e.created_by
              WHERE e.id = :id',
            ['id' => $expenseId]
        );
        if ($row === null) {
            return [];
        }

        $row['splits'] = Database::all(
            'SELECT es.user_id, es.share_amount, es.weight, es.is_settled,
                    u.full_name, u.avatar_color, u.participant_code
               FROM expense_splits es
               JOIN users u ON u.id = es.user_id
              WHERE es.expense_id = :e
              ORDER BY es.share_amount DESC, u.full_name',
            ['e' => $expenseId]
        );
        $row['split_total'] = Money::toAmount(
            (int) round(array_sum(array_map(
                static fn(array $s): float => (float) $s['share_amount'],
                $row['splits']
            )) * 100)
        );
        $row['is_balanced'] = abs($row['split_total'] - (float) $row['amount']) < 0.005;
        $row['head_count']  = count($row['splits']);
        $row['split_meta']  = $row['split_meta'] ? json_decode((string) $row['split_meta'], true) : null;
        $row['split_label'] = self::splitLabel($row);
        return $row;
    }

    /**
     * Translate UI filters into a WHERE fragment plus its bound parameters.
     *
     * Shared by listFor() and countFor() so the row count can never drift
     * out of step with the rows actually returned.
     *
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private static function filterClause(int $apartmentId, array $filters): array
    {
        $where  = ['e.apartment_id = :a', 'e.is_deleted = 0'];
        $params = ['a' => $apartmentId];

        if (!empty($filters['user_id'])) {
            // Expenses this person paid OR shared in. Two distinct placeholders:
            // native prepared statements do not allow reusing one named
            // parameter, so :u1 and :u2 are deliberate.
            $where[] = '(e.paid_by_user_id = :u1 OR EXISTS (
                            SELECT 1 FROM expense_splits es
                             WHERE es.expense_id = e.id AND es.user_id = :u2))';
            $params['u1'] = (int) $filters['user_id'];
            $params['u2'] = (int) $filters['user_id'];
        }
        if (!empty($filters['payer_id'])) {
            // Server-side counterpart to the "Paid by me" scope toggle. Doing
            // this in the browser would filter after paging, so the row count
            // and the visible page would disagree.
            $where[] = 'e.paid_by_user_id = :payer';
            $params['payer'] = (int) $filters['payer_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'e.category_id = :cat';
            $params['cat'] = (int) $filters['category_id'];
        }
        if (!empty($filters['split_type'])) {
            $where[] = 'e.split_type = :st';
            $params['st'] = (string) $filters['split_type'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'e.expense_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'e.expense_date <= :to';
            $params['to'] = (string) $filters['to'];
        }
        if (!empty($filters['meal_only'])) {
            $where[] = 'e.is_meal_related = 1';
        }
        if (!empty($filters['disputed'])) {
            $where[] = 'e.is_disputed = 1';
        }
        if (!empty($filters['q'])) {
            // Three occurrences, so three placeholders. Native prepared statements
            // cannot reuse one name: PDO emits one positional marker per
            // occurrence and binding `:q` once then leaves two unbound, which is
            // SQLSTATE[HY093]. It only bites when someone actually searches, so
            // the filter looked fine until then.
            $where[] = '(e.title LIKE :q1 OR e.description LIKE :q2 OR e.reference_no LIKE :q3)';
            $needle  = '%' . $filters['q'] . '%';
            $params['q1'] = $needle;
            $params['q2'] = $needle;
            $params['q3'] = $needle;
        }

        return [$where, $params];
    }

    /**
     * How many rows listFor() would return for the same filters. Drives the
     * pager in the client.
     */
    public static function countFor(int $apartmentId, array $filters = []): int
    {
        [$where, $params] = self::filterClause($apartmentId, $filters);

        // No :mine here -- PDO rejects parameters the statement never declares
        // (SQLSTATE[HY093]), and the count query has no my_share subquery.
        return (int) Database::value(
            'SELECT COUNT(*)
               FROM expenses e
               LEFT JOIN expense_categories c ON c.id = e.category_id
              WHERE ' . implode(' AND ', $where),
            $params
        );
    }

    /** Filterable, paginated expense list. */
    public static function listFor(
        int $apartmentId,
        array $filters = [],
        int $limit = 25,
        int $offset = 0
    ): array {
        [$where, $params] = self::filterClause($apartmentId, $filters);

        $sql = 'SELECT e.id, e.reference_no, e.title, e.description, e.amount, e.expense_date,
                       e.split_type, e.is_meal_related, e.is_disputed, e.dispute_note,
                       e.paid_by_user_id, c.name AS category_name, c.icon AS category_icon,
                       p.full_name AS paid_by_name, p.avatar_color AS paid_by_avatar,
                       (SELECT COUNT(*) FROM expense_splits es WHERE es.expense_id = e.id) AS head_count,
                       (SELECT es.share_amount FROM expense_splits es
                         WHERE es.expense_id = e.id AND es.user_id = :mine LIMIT 1) AS my_share
                  FROM expenses e
                  LEFT JOIN expense_categories c ON c.id = e.category_id
                  LEFT JOIN users p ON p.id = e.paid_by_user_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY e.expense_date DESC, e.id DESC
                 LIMIT :lim OFFSET :off';

        $params['mine'] = (int) ($filters['viewer_id'] ?? 0);
        $params['lim']  = max(1, min(200, $limit));
        $params['off']  = max(0, $offset);

        $rows = Database::all($sql, $params);

        return array_map(static function (array $r): array {
            $r['amount']    = (float) $r['amount'];
            $r['my_share']  = $r['my_share'] === null ? null : (float) $r['my_share'];
            $r['is_mine']   = $r['my_share'] !== null;
            $r['split_label'] = self::shortSplitLabel((string) $r['split_type'], (int) $r['head_count']);
            $r['date_ago']  = ActivityLog::ago($r['expense_date'] . ' 12:00:00');
            return $r;
        }, $rows);
    }

    public static function settlement(int $id): array
    {
        $row = Database::one(
            'SELECT s.*, f.full_name AS from_name, f.avatar_color AS from_avatar,
                    t.full_name AS to_name, t.avatar_color AS to_avatar
               FROM settlements s
               JOIN users f ON f.id = s.from_user_id
               JOIN users t ON t.id = s.to_user_id
              WHERE s.id = :id',
            ['id' => $id]
        );
        if ($row !== null) {
            $row['amount'] = (float) $row['amount'];
            $row['label']  = $row['from_name'] . ' paid ' . $row['to_name'] . ' ' . money($row['amount']);
        }
        return $row ?? [];
    }

    public static function settlementsFor(int $apartmentId, int $limit = 40): array
    {
        return array_map(
            [self::class, 'settlement'],
            array_map('intval', array_column(
                Database::all(
                    'SELECT id FROM settlements WHERE apartment_id = :a
                      ORDER BY settled_at DESC, id DESC LIMIT :lim',
                    ['a' => $apartmentId, 'lim' => $limit]
                ),
                'id'
            ))
        );
    }


    /* ================================================================== */
    /*  Helpers                                                            */
    /* ================================================================== */

    /** @return int[] */
    public static function activeResidentIds(int $apartmentId): array
    {
        return array_map('intval', array_column(
            Database::all(
                "SELECT id FROM users WHERE apartment_id = :a AND status = 'active' ORDER BY id",
                ['a' => $apartmentId]
            ),
            'id'
        ));
    }

    /** @return array<int,int> */
    public static function residentsWithMeta(int $apartmentId): array
    {
        return Database::all(
            "SELECT u.id, u.full_name, u.participant_code, u.avatar_color,
                    r.code AS room_code, g.name AS duty_group
               FROM users u
               LEFT JOIN rooms r ON r.id = u.room_id
               LEFT JOIN duty_groups g ON g.id = u.duty_group_id
              WHERE u.apartment_id = :a AND u.status = 'active'
              ORDER BY u.full_name",
            ['a' => $apartmentId]
        );
    }

    public static function categories(int $apartmentId): array
    {
        return Database::all(
            'SELECT id, name, slug, icon, is_meal_related
               FROM expense_categories
              WHERE apartment_id = :a AND is_active = 1
              ORDER BY sort_order, name',
            ['a' => $apartmentId]
        );
    }

    /** EX-2026-000123 */
    private static function nextReference(int $apartmentId): string
    {
        $year = (int) gmdate('Y');
        $n    = (int) Database::value(
            'SELECT COUNT(*) FROM expenses WHERE apartment_id = :a AND YEAR(expense_date) = :y',
            ['a' => $apartmentId, 'y' => $year]
        ) + 1;
        return sprintf('EX-%d-%06d', $year, $n);
    }

    private static function sanitiseIds(mixed $raw, array $allowed): array
    {
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), static fn($v) => trim($v) !== '');
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = array_map('intval', $raw);
        return array_values(array_intersect($allowed, array_unique(array_filter($ids))));
    }

    /** @return array<int,float> */
    private static function sanitiseWeights(mixed $raw, array $allowed): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $uid => $w) {
            $uid = (int) $uid;
            if (in_array($uid, $allowed, true) && (float) $w > 0) {
                $out[$uid] = round((float) $w, 4);
            }
        }
        return $out;
    }

    private static function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private static function splitLabel(array $expense): string
    {
        return match ($expense['split_type']) {
            'equal'      => 'Split equally between ' . $expense['head_count'] . ' people',
            'selective'  => 'Split between ' . $expense['head_count'] . ' selected people',
            'shares'     => 'Split by shares (' . $expense['head_count'] . ' people)',
            'meal_based' => 'Split between ' . $expense['head_count'] . ' people who ate',
            default      => 'Split',
        };
    }

    private static function shortSplitLabel(string $type, int $heads): string
    {
        return match ($type) {
            'equal'      => 'Equal · ' . $heads,
            'selective'  => 'Selected · ' . $heads,
            'shares'     => 'Shares · ' . $heads,
            'meal_based' => 'Eaters only · ' . $heads,
            default      => (string) $heads,
        };
    }
}
