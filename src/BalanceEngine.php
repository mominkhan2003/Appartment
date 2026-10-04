<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Balance Engine
 * ---------------------------------------------------------------------------
 * Single source of truth for the Splitwise-style ledger.
 *
 *   Net Balance  =  Total Paid
 *                -  Total Share Owed
 *                -  Total Settled Out
 *                +  Total Settled In
 *
 * Every figure is computed from integer cents, so the ledger is exact and
 * SUM(net) across all residents is always 0.
 *
 * The read path is a single aggregate query; the write path (ExpenseService)
 * materialises expense_splits so per-person history stays O(1) and auditable.
 */

declare(strict_types=1);

final class BalanceEngine
{
    /* ================================================================== */
    /*  Read paths                                                        */
    /* ================================================================== */

    /**
     * Net balance for every member of the apartment.
     *
     * @return array<int,array<string,mixed>>  keyed by user_id
     */
    public static function memberBalances(int $apartmentId, bool $includeSettled = true): array
    {
        $rows = Database::all(
            'SELECT user_id, full_name, participant_code, status,
                    room_code, duty_group,
                    total_paid, total_owed, net_balance
               FROM vw_balance_sheet
              WHERE apartment_id = :a
              ORDER BY net_balance DESC, full_name',
            ['a' => $apartmentId]
        );

        $out = [];
        foreach ($rows as $r) {
            $net = Money::toCents($r['net_balance']);
            if (!$includeSettled && $net === 0) {
                continue;
            }
            $out[(int) $r['user_id']] = [
                'user_id'         => (int) $r['user_id'],
                'full_name'       => $r['full_name'],
                'participant_code'=> $r['participant_code'],
                'status'          => $r['status'],
                'room_code'       => $r['room_code'],
                'duty_group'      => $r['duty_group'],
                'total_paid'      => Money::toCents($r['total_paid']),
                'total_owed'      => Money::toCents($r['total_owed']),
                'net_cents'       => $net,
                'net'             => Money::toAmount($net),
                'direction'       => Money::direction($net),
            ];
        }
        return $out;
    }

    /**
     * The "who owes whom" board.
     *
     * @return array{balances:array,transfers:array,creditors:array,debtors:array,settled:array,summary:array}
     */
    public static function ledger(int $apartmentId, string $strategy = 'auto'): array
    {
        $members   = self::memberBalances($apartmentId);
        $balances  = array_map(static fn(array $m): int => $m['net_cents'], $members);
        $transfers = DebtSimplifier::simplify($balances, $strategy);
        $byUser    = DebtSimplifier::summarise($transfers);

        // Attach names to every transfer and per-member row.
        $name = static fn(int $id): string => $members[$id]['full_name'] ?? ('User #' . $id);

        $transfersOut = array_map(static function (array $t) use ($name, $members): array {
            $t['from_name'] = $name($t['from_user_id']);
            $t['to_name']   = $name($t['to_user_id']);
            $t['display']     = sprintf(
                '%s pays %s %s',
                $t['from_name'],
                $t['to_name'],
                Money::format($t['amount_cents'])
            );
            return $t;
        }, $transfers);

        $creditors = [];
        $debtors   = [];
        $settled   = [];
        foreach ($members as $id => $m) {
            $row = $m + ($byUser[$id] ?? ['net_cents' => 0, 'amount' => 0.0, 'direction' => 'settled', 'counterparties' => []]);
            $row['settles'] = array_values(array_filter(
                $transfersOut,
                static fn(array $t): bool => $t['from_user_id'] === $id || $t['to_user_id'] === $id
            ));
            match ($m['direction']) {
                'credit'  => $creditors[] = $row,
                'debit'   => $debtors[]   = $row,
                default   => $settled[]   = $row,
            };
        }

        return [
            'balances'  => array_values($members),
            'transfers' => $transfersOut,
            'creditors' => $creditors,
            'debtors'   => $debtors,
            'settled'   => $settled,
            'summary'   => self::summary($apartmentId),

    public static function report(int $apartmentId): array
    {
        $balances = self::memberBalances($apartmentId);
        $totalExpenses = (float) Database::value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE apartment_id=:a AND is_deleted=0', ['a'=>$apartmentId]);
        $totalSettlements = (float) Database::value('SELECT COALESCE(SUM(amount),0) FROM settlements WHERE apartment_id=:a', ['a'=>$apartmentId]);
        $recent = Database::all('SELECT * FROM activity_log WHERE apartment_id=:a ORDER BY created_at DESC LIMIT 10', ['a'=>$apartmentId]);
        $creditors = array_values(array_filter($balances, fn($b)=>$b['direction']==='credit'));
        $debtors = array_values(array_filter($balances, fn($b)=>$b['direction']==='debit'));
        return ['balances'=>array_values($balances), 'total_expenses'=>$totalExpenses, 'total_settlements'=>$totalSettlements, 'creditors'=>$creditors, 'debtors'=>$debtors, 'recent'=>$recent];
    }
        ];
    }

    /**
     * Apartment-level totals for the dashboard header.
     */
    public static function summary(int $apartmentId, ?string $month = null): array
    {
        $params = ['a' => $apartmentId];
        $dateFilter = '';
        if ($month !== null) {
            $dateFilter   = ' AND expense_date >= :from AND expense_date < :to';
            $params['from'] = $month . '-01';
            $params['to']   = gmdate('Y-m-01', strtotime($month . '-01 +1 month'));
        }

        $totals = Database::one(
            "SELECT COUNT(*)              AS expense_count,
                    COALESCE(SUM(amount),0) AS total_spend
               FROM expenses
              WHERE apartment_id = :a AND is_deleted = 0" . $dateFilter,
            $params
        ) ?? ['expense_count' => 0, 'total_spend' => 0];

        $activeHeads = (int) Database::value(
            "SELECT COUNT(*) FROM users WHERE apartment_id = :a AND status = 'active'",
            ['a' => $apartmentId]
        );

        $totalCents  = Money::toCents($totals['total_spend']);
        $perHead     = $activeHeads > 0 ? intdiv($totalCents, $activeHeads) : 0;

        // Outstanding (unsettled) movement
        $openCents = 0;
        foreach (self::memberBalances($apartmentId) as $m) {
            $openCents += abs($m['net_cents']);
        }

        return [
            'expense_count'   => (int) $totals['expense_count'],
            'total_spend'     => Money::toAmount($totalCents),
            'total_spend_cents' => $totalCents,
            'active_heads'    => $activeHeads,
            'per_head'        => Money::toAmount($perHead),
            'per_head_cents'  => $perHead,
            'outstanding'     => Money::toAmount($openCents),
            'outstanding_cents' => $openCents,
        ];
    }

    /**
     * One person's full statement: what they paid, what they owe, and to whom.
     */
    public static function statementFor(int $apartmentId, int $userId): array
    {
        $balances = self::memberBalances($apartmentId);
        $me       = $balances[$userId] ?? null;
        if ($me === null) {
            return [];
        }

        $balancesOnly = array_map(static fn(array $m): int => $m['net_cents'], $balances);
        $transfers    = DebtSimplifier::simplify($balancesOnly);
        $name         = static fn(int $id): string => $balances[$id]['full_name'] ?? ('User #' . $id);

        $outgoing = [];
        $incoming = [];
        foreach ($transfers as $t) {
            $t['counterparty'] = $t['from_user_id'] === $userId ? $t['to_name'] = $name($t['to_user_id'])
                                                                 : $name($t['from_user_id']);
            $t['direction'] = $t['from_user_id'] === $userId ? 'pay' : 'receive';
            if ($t['from_user_id'] === $userId) {
                $outgoing[] = $t;
            } else {
                $incoming[] = $t;
            }
        }

        // Expense activity
        $paid = Database::all(
            'SELECT e.id, e.reference_no, e.title, e.amount, e.expense_date, e.split_type,
                    c.name AS category, c.icon AS category_icon
               FROM expenses e
               LEFT JOIN expense_categories c ON c.id = e.category_id
              WHERE e.apartment_id = :a AND e.paid_by_user_id = :u1 AND e.is_deleted = 0
              ORDER BY e.expense_date DESC, e.id DESC
              LIMIT 50',
            ['a' => $apartmentId, 'u1' => $userId]
        );

        $owed = Database::all(
            'SELECT e.id, e.reference_no, e.title, e.amount, e.expense_date, e.split_type,
                    es.share_amount, es.is_settled,
                    c.name AS category, c.icon AS category_icon,
                    p.full_name AS paid_by_name
               FROM expense_splits es
               JOIN expenses e ON e.id = es.expense_id AND e.is_deleted = 0
               LEFT JOIN expense_categories c ON c.id = e.category_id
               LEFT JOIN users p ON p.id = e.paid_by_user_id
              WHERE e.apartment_id = :a AND es.user_id = :u1
              ORDER BY e.expense_date DESC, e.id DESC
              LIMIT 50',
            ['a' => $apartmentId, 'u1' => $userId]
        );

        $settlements = Database::all(
            'SELECT s.id, s.amount, s.method, s.note, s.settled_at, s.reference,
                    f.full_name AS from_name, t.full_name AS to_name
               FROM settlements s
               JOIN users f ON f.id = s.from_user_id
               JOIN users t ON t.id = s.to_user_id
              WHERE s.apartment_id = :a AND (s.from_user_id = :u1 OR s.to_user_id = :u2)
              ORDER BY s.settled_at DESC, s.id DESC
              LIMIT 50',
            ['a' => $apartmentId, 'u1' => $userId, 'u2' => $userId]
        );

        return [
            'member'       => $me,
            'outgoing'     => $outgoing,
            'incoming'     => $incoming,
            'paid_expenses'=> $paid,
            'owed_expenses'=> $owed,
            'settlements'  => $settlements,
            'summary'      => self::summary($apartmentId),
        ];
    }

    /**
     * Where the money goes this month, by category.
     */
    public static function categoryBreakdown(int $apartmentId, ?string $month = null): array
    {
        $params = ['a' => $apartmentId];
        $filter = '';
        if ($month !== null) {
            $filter      = ' AND e.expense_date >= :from AND e.expense_date < :to';
            $params['from'] = $month . '-01';
            $params['to']   = gmdate('Y-m-01', strtotime($month . '-01 +1 month'));
        }

        $rows = Database::all(
            'SELECT COALESCE(c.name, "Uncategorised") AS category,
                    COALESCE(c.icon, "bi-question-circle") AS icon,
                    COUNT(*) AS n,
                    SUM(e.amount) AS amount
               FROM expenses e
               LEFT JOIN expense_categories c ON c.id = e.category_id
              WHERE e.apartment_id = :a AND e.is_deleted = 0' . $filter . '
              GROUP BY c.name, c.icon
              ORDER BY amount DESC',
            $params
        );

        $total = array_sum(array_map(static fn(array $r): float => (float) $r['amount'], $rows));

        return array_map(static function (array $r) use ($total): array {
            $amount = (float) $r['amount'];
            return [
                'category'  => $r['category'],
                'icon'      => $r['icon'],
                'count'     => (int) $r['n'],
                'amount'    => $amount,
                'percent'   => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /* ================================================================== */
    /*  Integrity                                                          */
    /* ================================================================== */

    /**
     * Assert the ledger's core invariants. Surfaces ledger drift loudly
     * instead of letting it accumulate.
     *
     * @return array{ok:bool,checks:array<string,array{ok:bool,detail:string}>}
     */
    public static function audit(int $apartmentId): array
    {
        // 1. split rows must reconstruct the expense total exactly
        $drift = Database::all(
            'SELECT e.id, e.reference_no, e.title, e.amount,
                    SUM(es.share_amount) AS split_total,
                    ROUND(SUM(es.share_amount) - e.amount, 2) AS delta
               FROM expenses e
               JOIN expense_splits es ON es.expense_id = e.id
              WHERE e.apartment_id = :a AND e.is_deleted = 0
              GROUP BY e.id, e.reference_no, e.title, e.amount
             HAVING ABS(SUM(es.share_amount) - e.amount) > 0.001',
            ['a' => $apartmentId]
        );

        // 2. every expense needs at least one split
        $orphans = Database::all(
            'SELECT e.id, e.reference_no, e.title
               FROM expenses e
              WHERE e.apartment_id = :a AND e.is_deleted = 0
                AND NOT EXISTS (SELECT 1 FROM expense_splits es WHERE es.expense_id = e.id)',
            ['a' => $apartmentId]
        );

        // 3. net balances must sum to zero across the apartment
        $sum = 0;
        foreach (self::memberBalances($apartmentId) as $m) {
            $sum += $m['net_cents'];
        }

        // 4. no split may be negative
        $negative = Database::value(
            'SELECT COUNT(*) FROM expense_splits es
               JOIN expenses e ON e.id = es.expense_id
              WHERE e.apartment_id = :a AND es.share_amount < 0',
            ['a' => $apartmentId]
        );

        $checks = [
            'splits_reconstruct_expense' => [
                'ok'     => $drift === [],
                'detail' => $drift === []
                    ? 'Every expense split total matches its amount exactly.'
                    : sprintf('%d expense(s) drifted: %s', count($drift), implode(', ',
                        array_map(static fn(array $r): string => $r['reference_no'] . ' (' . $r['delta'] . ')', $drift))),
            ],
            'no_orphan_expenses' => [
                'ok'     => $orphans === [],
                'detail' => $orphans === []
                    ? 'Every expense has at least one split row.'
                    : sprintf('%d expense(s) with no splits: %s', count($orphans), implode(', ',
                        array_map(static fn(array $r): string => (string) $r['reference_no'], $orphans))),
            ],
            'balances_sum_to_zero' => [
                'ok'     => $sum === 0,
                'detail' => $sum === 0
                    ? 'Net balances across all residents cancel out to 0.'
                    : 'Net balances are off by ' . Money::format($sum) . '.',
            ],
            'no_negative_shares' => [
                'ok'     => (int) $negative === 0,
                'detail' => (int) $negative === 0
                    ? 'No negative shares.'
                    : $negative . ' negative share row(s).',
            ],
        ];

        return [
            'ok'     => !in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }
}
