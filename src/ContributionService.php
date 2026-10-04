<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  House Fund Service
 * ---------------------------------------------------------------------------
 * The shared pot.
 *
 * The apartment keeps physical cash for groceries and bills. A resident hands
 * money over (a *contribution*), the pot is spent from (an expense flagged
 * `paid_from_fund`), and everybody's share of what was bought is known. This is
 * deliberately not the settlements table: a settlement clears one person's debt
 * to another, while a contribution moves money into a pool everyone draws from.
 * Keeping them apart is what stops the pairwise settle board from inventing
 * transfers against a pot that is not a person.
 *
 * The arithmetic lives in the database, not here, so it cannot drift:
 *   vw_fund_totals  -> the pot's own balance
 *   vw_house_fund   -> per member: share owed, contributed, outstanding
 *
 * Split decisions still belong to ExpenseService; this service only ever reads
 * the resulting shares.
 */

declare(strict_types=1);

final class ContributionService
{
    public const METHODS = ['cash', 'bkash', 'nagad', 'bank', 'other'];

    /* ================================================================== */
    /*  Write                                                             */
    /* ================================================================== */

    /**
     * Record cash handed over to the pot.
     *
     * Idempotency note: there is no "undo" here beyond delete(), because
     * deleting a contribution rewinds the pot balance, which is exactly what an
     * admin needs when they mis-key an amount.
     *
     * @param array $input {
     *   from_user_id, amount, method?, contributed_on?, note?,
     *   reference?, to_user_id?, period_key?, collected_by?
     * }
     */
    public static function create(int $apartmentId, int $actorId, array $input): array
    {
        $from = (int) ($input['from_user_id'] ?? 0);
        if (!self::isActiveResident($apartmentId, $from)) {
            throw new ValidationException([
                'from_user_id' => 'Choose an active resident who handed the money over.',
            ]);
        }

        $amountCents = Money::toCents($input['amount'] ?? null);
        if ($amountCents <= 0) {
            throw new ValidationException(['amount' => 'Amount must be greater than zero.']);
        }

        $method = (string) ($input['method'] ?? 'cash');
        if (!in_array($method, self::METHODS, true)) {
            throw new ValidationException(['method' => 'Unknown payment method.']);
        }

        $on = (string) ($input['contributed_on'] ?? gmdate('Y-m-d'));
        if (!self::isValidDate($on)) {
            throw new ValidationException(['contributed_on' => 'Use a YYYY-MM-DD date.']);
        }

        // Optional custodian. NULL means "into the pot", which is the normal
        // case: the money is the household's, not the admin's personally.
        $to = $input['to_user_id'] ?? null;
        $to = $to === null || $to === '' ? null : (int) $to;
        if ($to !== null && !self::isActiveResident($apartmentId, $to)) {
            throw new ValidationException(['to_user_id' => 'That custodian is not an active resident.']);
        }
        if ($to !== null && $to === $from) {
            throw new ValidationException([
                'to_user_id' => 'The person paying in cannot also be the one receiving it.',
            ]);
        }

        $period = self::periodKey($on, $input['period_key'] ?? null);

        $id = Database::transaction(static function () use (
            $apartmentId, $actorId, $from, $to, $amountCents, $method,
            $input, $on, $period
        ) {
            $id = Database::insert('contributions', [
                'apartment_id'   => $apartmentId,
                'from_user_id'   => $from,
                'to_user_id'     => $to,
                'amount'         => Money::toAmount($amountCents),
                'method'         => $method,
                'reference'      => self::trimOrNull($input['reference'] ?? null, 120),
                'note'           => self::trimOrNull($input['note'] ?? null, 500),
                'period_key'     => $period,
                'collected_by'   => $input['collected_by'] ?? null,
                'contributed_on' => $on,
                'created_by'     => $actorId,
            ]);

            $who = (string) Database::value(
                'SELECT full_name FROM users WHERE id = :id',
                ['id' => $from]
            );

            ActivityLog::record(
                'contribution.created', 'contribution', $id,
                sprintf('%s put %s into the house fund', $who, money($amountCents / 100)),
                [
                    'from_user_id' => $from,
                    'amount_cents' => $amountCents,
                    'method'       => $method,
                    'period_key'   => $period,
                ]
            );

            return $id;
        });

        return self::find($id, $apartmentId);
    }

    /**
     * Hard delete. A contribution is a one-line receipt, not a ledger entry
     * other rows depend on, so removing it is safe and is the only way to
     * rewind a mis-keyed amount.
     */
    public static function delete(int $contributionId, int $actorId): void
    {
        $row = Database::one(
            'SELECT c.id, c.amount, c.from_user_id, c.created_by, u.full_name
               FROM contributions c
               JOIN users u ON u.id = c.from_user_id
              WHERE c.id = :id',
            ['id' => $contributionId]
        );

        if ($row === null) {
            throw new RuntimeException('That contribution no longer exists.');
        }

        // Admins may correct anything. Otherwise only the person who logged it.
        if (!Auth::isAdmin() && (int) $row['created_by'] !== $actorId) {
            throw new RuntimeException('You can only remove money you added yourself.');
        }

        Database::transaction(static function () use ($row, $contributionId, $actorId) {
            Database::delete('contributions', 'id', $contributionId);

            ActivityLog::record(
                'contribution.deleted', 'contribution', $contributionId,
                sprintf(
                    '%s removed a %s contribution',
                    (string) $row['full_name'],
                    money((float) $row['amount'])
                ),
                ['from_user_id' => (int) $row['from_user_id'], 'actor_id' => $actorId]
            );
        });
    }

    /* ================================================================== */
    /*  Read                                                              */
    /* ================================================================== */

    /**
     * The pot, plus what each member still owes it.
     *
     * Outstanding is deliberately independent of the pairwise settle board:
     * somebody can be square with their flatmates while still owing the pot for
     * groceries, and collapsing the two is what made the old numbers confusing.
     */
    public static function summary(int $apartmentId): array
    {
        $totals = Database::one(
            'SELECT total_contributed, total_spent, balance
               FROM vw_fund_totals WHERE apartment_id = :a',
            ['a' => $apartmentId]
        ) ?? ['total_contributed' => 0, 'total_spent' => 0, 'balance' => 0];

        $members = Database::all(
            'SELECT user_id, full_name, participant_code, room_code,
                    share_owed, contributed, outstanding
               FROM vw_house_fund
              WHERE apartment_id = :a
              ORDER BY outstanding DESC, full_name',
            ['a' => $apartmentId]
        );

        $rows = array_map(static fn (array $m): array => [
            'user_id'          => (int) $m['user_id'],
            'full_name'        => (string) $m['full_name'],
            'participant_code' => (string) $m['participant_code'],
            'room_code'        => $m['room_code'] === null ? null : (string) $m['room_code'],
            'share_owed_cents' => self::toCents($m['share_owed']),
            'contributed_cents'=> self::toCents($m['contributed']),
            'outstanding_cents'=> self::toCents($m['outstanding']),
        ], $members);

        return [
            'total_contributed_cents' => self::toCents($totals['total_contributed']),
            'total_spent_cents'       => self::toCents($totals['total_spent']),
            'balance_cents'           => self::toCents($totals['balance']),
            'members'                 => $rows,
            // Everyone the pot is owed by, largest first: this is the list you
            // actually work down when you go round collecting.
            'collectors'             => array_values(array_filter(
                $rows,
                static fn (array $m): bool => $m['outstanding_cents'] > 0
            )),
            'total_outstanding_cents' => array_sum(array_column(
                array_filter($rows, static fn (array $m): bool => $m['outstanding_cents'] > 0),
                'outstanding_cents'
            )),
        ];
    }

    /** @return array<int,array> */
    public static function listFor(int $apartmentId, int $limit = 50): array
    {
        $rows = Database::all(
            'SELECT c.id, c.amount, c.method, c.reference, c.note, c.period_key,
                    c.contributed_on, c.created_at,
                    c.from_user_id, c.to_user_id,
                    uf.full_name AS from_name, ut.full_name AS to_name
               FROM contributions c
               JOIN users uf ON uf.id = c.from_user_id
          LEFT JOIN users ut ON ut.id = c.to_user_id
              WHERE c.apartment_id = :a
              ORDER BY c.contributed_on DESC, c.id DESC
              LIMIT ' . max(1, $limit),
            ['a' => $apartmentId]
        );

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'amount_cents'  => self::toCents($r['amount']),
            'method'        => (string) $r['method'],
            'reference'     => $r['reference'],
            'note'          => $r['note'],
            'period_key'    => $r['period_key'],
            'from_user_id'  => (int) $r['from_user_id'],
            'from_name'     => (string) $r['from_name'],
            'to_user_id'    => $r['to_user_id'] === null ? null : (int) $r['to_user_id'],
            'to_name'       => $r['to_name'],
            'contributed_on'=> (string) $r['contributed_on'],
            'created_at'    => (string) $r['created_at'],
        ], $rows);
    }

    public static function find(int $id, int $apartmentId): array
    {
        $row = Database::one(
            'SELECT c.*, uf.full_name AS from_name
               FROM contributions c
               JOIN users uf ON uf.id = c.from_user_id
              WHERE c.id = :id AND c.apartment_id = :a',
            ['id' => $id, 'a' => $apartmentId]
        );

        if ($row === null) {
            throw new RuntimeException('That contribution no longer exists.');
        }

        return [
            'id'             => (int) $row['id'],
            'amount_cents'   => self::toCents($row['amount']),
            'method'         => (string) $row['method'],
            'reference'      => $row['reference'],
            'note'           => $row['note'],
            'period_key'     => $row['period_key'],
            'from_user_id'   => (int) $row['from_user_id'],
            'from_name'      => (string) $row['from_name'],
            'to_user_id'     => $row['to_user_id'] === null ? null : (int) $row['to_user_id'],
            'contributed_on' => (string) $row['contributed_on'],
            'created_at'     => (string) $row['created_at'],
        ];
    }

    /* ================================================================== */
    /*  Internals                                                         */
    /* ================================================================== */

    private static function isActiveResident(int $apartmentId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return Database::value(
            "SELECT id FROM users
              WHERE id = :id AND apartment_id = :a AND status = 'active'",
            ['id' => $userId, 'a' => $apartmentId]
        ) !== null;
    }

    /**
     * Views return DECIMAL, which arrives as a string. Coerce via the money
     * helper rather than (int) so '9.99' becomes 999 and not 9.
     */
    private static function toCents(mixed $decimal): int
    {
        return Money::toCents($decimal === null ? 0 : $decimal);
    }

    /**
     * A collection belongs to the month the cash arrived, unless the caller
     * overrides it -- collecting last month's arrears in this month is normal.
     */
    private static function periodKey(string $on, mixed $override): ?string
    {
        $key = is_string($override) && preg_match('/^\d{4}-\d{2}$/', trim($override))
            ? trim($override)
            : substr($on, 0, 7);

        return $key !== '' ? $key : null;
    }

    private static function trimOrNull(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $v = trim($value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
