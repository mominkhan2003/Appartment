<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Runtime house-fund tests
 * ---------------------------------------------------------------------------
 * The shared pot is the one place in the app where the SAME grocery run is
 * visible through two different ledgers, so the invariants are asserted rather
 * than assumed:
 *
 *   1. A contribution raises the pot and lowers that member's outstanding.
 *   2. An expense flagged paid_from_fund draws the pot down and records each
 *      eater's share.
 *   3. Crucially, a fund-paid expense does NOT touch vw_balance_sheet. The
 *      member who physically shops must not appear as a creditor for household
 *      money, and members must not be charged for it on the pairwise board.
 *   4. vw_balance_sheet still sums to zero, which is what DebtSimplifier needs
 *      before it will produce a settle plan (otherwise the expenses page
 *      answers HTTP 400).
 *   5. Direct (non-fund) expenses are untouched by any of this.
 *
 *     php tests/runtime_fund.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/support/mysql_to_sqlite.php';
require_once __DIR__ . '/support/TestCase.php';

/* -------------------------------------------------------------------------- */
/*  Harness                                                                    */
/* -------------------------------------------------------------------------- */

final class FundHarness
{
    public PDO $pdo;
    public int $apartmentId = 0;
    public int $otherApartmentId = 0;
    public int $adminId = 0;
    public int $aId = 0;
    public int $bId = 0;
    public int $cId = 0;
    public int $outsiderId = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        sqlite_shims($this->pdo);

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec(sqlite_schema_sql());

        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue(null, $this->pdo);

        $this->seed();
    }

    private function seed(): void
    {
        $this->apartmentId = $this->apartment('Test Flat');
        $this->adminId     = $this->user('Admin', 'admin@example.test', 'admin');
        $this->aId         = $this->user('Ayesha', 'a@example.test', 'resident');
        $this->bId         = $this->user('Bilal', 'b@example.test', 'resident');
        $this->cId         = $this->user('Chen', 'c@example.test', 'resident');

        // A second household, to prove the fund never leaks across apartments.
        $this->otherApartmentId = $this->apartment('Other Flat');
        $this->outsiderId       = $this->user('Outsider', 'out@example.test', 'resident', $this->otherApartmentId);
    }

    private function apartment(string $name): int
    {
        return Database::insert('apartments', [
            'name'             => $name,
            'currency_code'    => 'BDT',
            'currency_symbol'  => '৳',
            'week_starts_on'   => 1,
            'meal_deadline_hr' => 10,
        ]);
    }

    private function user(string $name, string $email, string $role, ?int $apartment = null): int
    {
        return Database::insert('users', [
            'apartment_id'    => $apartment ?? $this->apartmentId,
            'participant_code'=> 'FM-' . strtoupper(substr(md5($email), 0, 5)),
            'full_name'       => $name,
            'email'           => $email,
            'password_hash'   => password_hash('secret123', PASSWORD_BCRYPT),
            'role'            => $role,
            'status'          => 'active',
        ]);
    }

    public function actAs(int $userId): void
    {
        $row = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($row === null) {
            throw new RuntimeException('no such user ' . $userId);
        }
        $row = Auth::sanitiseUser($row);

        foreach (['user' => $row, 'resolved' => true] as $prop => $value) {
            $p = new ReflectionProperty(Auth::class, $prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    /**
     * A grocery run bought by $shopperId and shared equally between $eaters.
     * Mirrors ExpenseService::create()'s persistence without duplicating its
     * split engine, which has its own coverage.
     */
    public function fundExpense(
        int $shopperId,
        array $eaters,
        string $amount,
        bool $paidFromFund,
        string $title = 'Groceries'
    ): int {
        $cents = Money::toCents($amount);
        $allocated = Money::allocate($cents, count($eaters));

        return Database::transaction(static function () use (
            $shopperId, $eaters, $amount, $allocated, $paidFromFund, $title, $cents
        ) {
            $id = Database::insert('expenses', [
                'apartment_id'    => $GLOBALS['h_apartment'],
                'title'           => $title,
                'amount'          => $amount,
                'currency_code'   => 'BDT',
                'paid_by_user_id' => $shopperId,
                'split_type'      => 'selective',
                'expense_date'    => gmdate('Y-m-d'),
                'paid_from_fund'  => $paidFromFund ? 1 : 0,
                'created_by'      => $shopperId,
            ]);

            foreach (array_values($eaters) as $i => $uid) {
                Database::insert('expense_splits', [
                    'expense_id'   => $id,
                    'user_id'      => $uid,
                    'share_amount' => Money::toAmount($allocated[$i]),
                ]);
            }

            return $id;
        });
    }

    public function fundBalance(): int
    {
        return Money::toCents(Database::value(
            'SELECT balance FROM vw_fund_totals WHERE apartment_id = :a',
            ['a' => $this->apartmentId]
        ) ?? 0);
    }

    /** @return array<int,int> user_id => outstanding cents */
    public function outstanding(): array
    {
        $rows = Database::all(
            'SELECT user_id, outstanding FROM vw_house_fund WHERE apartment_id = :a',
            ['a' => $this->apartmentId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = Money::toCents($r['outstanding']);
        }

        return $out;
    }

    /** @return array<int,int> user_id => net balance cents from the pairwise view */
    public function pairwise(): array
    {
        $rows = Database::all(
            'SELECT user_id, net_balance FROM vw_balance_sheet WHERE apartment_id = :a',
            ['a' => $this->apartmentId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = Money::toCents($r['net_balance']);
        }

        return $out;
    }

    public function pairwiseSum(): int
    {
        return array_sum($this->pairwise());
    }
}

/* -------------------------------------------------------------------------- */
/*  Tests                                                                      */
/* -------------------------------------------------------------------------- */

$h = new FundHarness();
$GLOBALS['h_apartment'] = $h->apartmentId;
$t = new TestCase();

/*
 * Sign convention, asserted once so the numbers below are unambiguous:
 *
 *   outstanding = share_owed - contributed
 *
 *   > 0  the member OWES the pot      -> this is who you go and collect from
 *   < 0  the pot OWES the member      -> they overpaid, shown as a credit
 *   = 0  square
 *
 * The fund balance is a separate figure: it is cash on hand, so paying money
 * in RAISES it and buying groceries LOWERS it, regardless of who is owed what.
 * A member overpaying therefore leaves the pot richer and themselves in credit.
 */

/* ---- 1. contribution raises the pot -------------------------------------- */
$t->group('contributions');

$h->actAs($h->adminId);
$row = ContributionService::create($h->apartmentId, $h->adminId, [
    'from_user_id'   => $h->aId,
    'amount'         => '300.00',
    'method'         => 'cash',
    'contributed_on' => gmdate('Y-m-d'),
]);

$t->same(30000, $row['amount_cents'], 'contribution stores 300.00 as 30000 cents');
$t->same(30000, $h->fundBalance(), 'pot balance rises by the contribution');
$t->same(-30000, $h->outstanding()[$h->aId], 'paying in with nothing bought is a credit, not a debt');

$t->throws(
    static fn () => ContributionService::create($h->apartmentId, $h->adminId, [
        'from_user_id' => $h->aId, 'amount' => '0',
    ]),
    'greater than zero',
    'zero amount rejected'
);

$t->throws(
    static fn () => ContributionService::create($h->apartmentId, $h->adminId, [
        'from_user_id' => $h->outsiderId, 'amount' => '50',
    ]),
    'active resident',
    'cannot take money from a resident of another apartment'
);

$t->throws(
    static fn () => ContributionService::create($h->apartmentId, $h->adminId, [
        'from_user_id' => $h->aId, 'amount' => '50', 'method' => 'barter',
    ]),
    'Unknown payment method',
    'unknown payment method rejected'
);

/* ---- 2. a fund-paid grocery run ------------------------------------------ */
$t->group('fund-paid expenses');

// 300 in, then a 90 grocery shared 30/30/30 by the admin out of the pot.
$h->fundExpense($h->adminId, [$h->aId, $h->bId, $h->cId], '90.00', true);

$t->same(21000, $h->fundBalance(), 'pot draws down: 300 in - 90 out');
$t->same(-27000, $h->outstanding()[$h->aId], 'Ayesha: 30 owed, 300 paid in -> 270 in credit');
$t->same(3000, $h->outstanding()[$h->bId], 'Bilal owes his 30 share');
$t->same(3000, $h->outstanding()[$h->cId], 'Chen owes his 30 share');

/* ---- 3. the pairwise board must not see any of it ------------------------ */
$t->group('isolation from the pairwise board');

$pw = $h->pairwise();
$t->same(0, $pw[$h->adminId], 'the shopper is NOT credited for household money');
$t->same(0, $pw[$h->aId], 'fund-paid share is not charged on the settle board');
$t->same(0, $pw[$h->bId], 'Bilal is square on the board despite owing the pot');
$t->same(0, $pw[$h->cId], 'Chen is square on the board despite owing the pot');
$t->same(0, $h->pairwiseSum(), 'net balances still sum to zero, so DebtSimplifier accepts the ledger');

/* ---- 4. direct expenses are unaffected ----------------------------------- */
$t->group('direct expenses');

// Bilal pays 50 for a taxi he shares with Ayesha, out of his own pocket.
$h->fundExpense($h->bId, [$h->aId, $h->bId], '50.00', false, 'Shared taxi');

$pw = $h->pairwise();
$t->same(2500, $pw[$h->bId], 'a direct expense credits its payer net of their own share: +50 -25');
$t->same(-2500, $pw[$h->aId], 'a direct expense still charges its sharers');
$t->same(0, $pw[$h->adminId], 'the admin stays out of a flatmate-to-flatmate expense');
$t->same(0, $h->pairwiseSum(), 'invariant holds with a mixed ledger');

/* ---- 5. collecting ------------------------------------------------------- */
$t->group('collecting');

$h->actAs($h->adminId);
ContributionService::create($h->apartmentId, $h->adminId, [
    'from_user_id' => $h->bId,
    'amount'       => '30.00',
    'note'         => 'March grocery share',
]);

$t->same(24000, $h->fundBalance(), 'collection puts 30 back into the pot');
$t->same(0, $h->outstanding()[$h->bId], 'Bilal is square once he has paid his share');
$t->same(-27000, $h->outstanding()[$h->aId], 'Ayesha still carries her 270 credit');

$s = ContributionService::summary($h->apartmentId);
$t->same(24000, $s['balance_cents'], 'summary balance matches the view');
$t->same(1, count($s['collectors']), 'exactly one member left to chase');
$t->same($h->cId, $s['collectors'][0]['user_id'], 'the collector is Chen, not Ayesha');
$t->same(3000, $s['total_outstanding_cents'], 'only positive balances are collected');

/* ---- 6. overpayment shows up as a credit, not a negative collect ---------- */
$h->actAs($h->adminId);
ContributionService::create($h->apartmentId, $h->adminId, [
    'from_user_id' => $h->cId,
    'amount'       => '45.00',
]);

$t->same(28500, $h->fundBalance(), 'overpayment lands in the pot');
$t->same(-1500, $h->outstanding()[$h->cId], 'Chen paid 45 against 30 owed -> 15 in credit');
$t->same(0, $h->pairwiseSum(), 'overpaying the pot never unbalances the pairwise board');

/* ---- 7. cross-apartment isolation ---------------------------------------- */
$t->group('cross-apartment isolation');

$h->actAs($h->adminId);
ContributionService::create($h->otherApartmentId, $h->adminId, [
    'from_user_id' => $h->outsiderId,
    'amount'       => '999.00',
]);

$otherSummary = ContributionService::summary($h->otherApartmentId);
$t->same(99900, $otherSummary['balance_cents'], 'the other flat has its own pot');

$mine = ContributionService::listFor($h->apartmentId);
$t->same(3, count($mine), 'my ledger does not list the other flat\'s contribution');
$t->same(
    [],
    array_values(array_filter($mine, static fn (array $c): bool => $c['from_user_id'] === $h->outsiderId)),
    'no foreign contributor leaks into the list'
);

$out = $h->outstanding();
$t->ok(!array_key_exists($h->outsiderId, $out), 'the other flat\'s resident has no row in my fund view');

/* ---- 8. correction removes the row and rewinds the pot ------------------- */
$t->group('corrections');

$before = $h->fundBalance();
$list   = ContributionService::listFor($h->apartmentId);
$target = $list[0]['id'];

ContributionService::delete($target, $h->adminId);
$t->same($before - $list[0]['amount_cents'], $h->fundBalance(), 'deleting a contribution rewinds the pot');

$t->throws(
    static fn () => ContributionService::delete($target, $h->adminId),
    'no longer exists',
    'deleting twice is refused'
);

$t->throws(
    static fn () => ContributionService::find($target, $h->apartmentId),
    'no longer exists',
    'a removed contribution is gone from find()'
);

/* ---- 9. authorization ---------------------------------------------------- */
$t->group('authorization');

$h->actAs($h->aId);
$mineId = ContributionService::create($h->apartmentId, $h->aId, [
    'from_user_id' => $h->aId,
    'amount'       => '10.00',
])['id'];

$t->throws(
    static fn () => ContributionService::delete($mineId, $h->bId),
    'only remove money you added',
    'a resident cannot delete another resident\'s contribution'
);

ContributionService::delete($mineId, $h->aId);
$t->ok(true, 'the person who logged it can remove their own contribution');

$adminList = ContributionService::listFor($h->apartmentId);
$t->ok(count($adminList) >= 2, 'remaining contributions are intact');

/* ---- 10. the real write path -------------------------------------------- */
$t->group('through ExpenseService::create');

$svc = new FundHarness();
$GLOBALS['h_apartment'] = $svc->apartmentId;

$svc->actAs($svc->adminId);
$in = ContributionService::create($svc->apartmentId, $svc->adminId, [
    'from_user_id' => $svc->aId,
    'amount'       => '60.00',
]);
$t->same(6000, $in['amount_cents'], 'contribution via the service');
$t->same(6000, $svc->fundBalance(), 'pot holds 60');

// The form posts paid_from_fund; ExpenseService must persist it.
$viaService = ExpenseService::create($svc->apartmentId, $svc->adminId, [
    'title'           => 'Weekly groceries',
    'amount'          => '30.00',
    'paid_by_user_id' => $svc->adminId,
    'split_type'      => 'selective',
    'user_ids'        => [$svc->aId, $svc->bId],
    'expense_date'    => gmdate('Y-m-d'),
    'paid_from_fund'  => 1,
]);

$t->same(1, $viaService['paid_from_fund'], 'paid_from_fund survives the real create() path');
$t->same('integer', gettype($viaService['paid_from_fund']), 'it reaches the client as an int, not "1"');
$t->same(3000, $svc->fundBalance(), 'a 30 grocery drawn from 60 leaves 30 in the pot');
$t->same(1500, $svc->outstanding()[$svc->bId], 'Bilal owes his 15 share of the service-created expense');

$t->same(0, $svc->pairwise()[$svc->adminId], 'the admin who created it is still not its creditor');
$t->same(0, $svc->pairwiseSum(), 'invariant holds for a service-created fund expense');

// Omitting the flag entirely must mean "own pocket", not "fund".
$direct = ExpenseService::create($svc->apartmentId, $svc->adminId, [

    'title'           => 'Bulb for the hall',
    'amount'          => '20.00',
    'paid_by_user_id' => $svc->adminId,
    'split_type'      => 'selective',
    'user_ids'        => [$svc->aId, $svc->bId],
    'expense_date'    => gmdate('Y-m-d'),
]);

$t->same(0, $direct['paid_from_fund'], 'an expense with no flag is not a fund expense');
$t->same(3000, $svc->fundBalance(), 'a direct purchase leaves the pot alone');

$t->same(2000, $svc->pairwise()[$svc->adminId], 'but the admin is credited for it as before');
$t->same(0, $svc->pairwiseSum(), 'invariant holds for mixed fund and direct service expenses');

$list = $svc->pairwise();
$t->same(0, $list[$svc->adminId] - 2000, 'only the direct expense moved the admin');

/* ---- 11. an empty pot is not an error ------------------------------------ */
$t->group('empty state');

$empty = new FundHarness();
$emptyApartment = $GLOBALS['h_apartment'];
$GLOBALS['h_apartment'] = $empty->apartmentId;

$s = ContributionService::summary($empty->apartmentId);
$t->same(0, $s['balance_cents'], 'a fresh apartment reports a zero pot, not null');
$t->same(0, $s['total_outstanding_cents'], 'nothing to collect');
$t->same([], $s['collectors'], 'no collectors on an empty pot');
$t->same([], ContributionService::listFor($empty->apartmentId), 'empty contribution list');

$GLOBALS['h_apartment'] = $emptyApartment;

$t->summary();
