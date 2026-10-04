<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Runtime tests for the selective data reset
 * ---------------------------------------------------------------------------
 * DataResetService is the most destructive code in the project, and the one an
 * admin triggers by literally typing DELETE. These tests run it against a real
 * database engine using sql/schema.sql translated to SQLite, so the guards are
 * verified rather than assumed.
 *
 *     php tests/runtime_reset.php
 *
 * Two flats are seeded. The second one exists purely to prove that a reset of
 * flat A cannot reach flat B -- the single most important property here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/support/mysql_to_sqlite.php';
require_once __DIR__ . '/support/TestCase.php';

$t = new TestCase();

/* -------------------------------------------------------------------------- */
/*  Two flats                                                                  */
/* -------------------------------------------------------------------------- */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
    sqlite_shims($pdo);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(sqlite_schema_sql());

$pdoProp = new ReflectionProperty(Database::class, 'pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue(null, $pdo);

function seedFlat(PDO $pdo, string $label): array
{
    $apartmentId = Database::insert('apartments', [
        'name'            => $label,
        'currency_code'   => 'BDT',
        'currency_symbol' => '৳',
    ]);

    $mk = static function (string $name, string $role, string $status = 'active') use ($apartmentId, $label): int {
        return Database::insert('users', [
            'apartment_id'     => $apartmentId,
            'participant_code' => 'FM-' . strtoupper(bin2hex(random_bytes(3))),
            'full_name'        => $name,
            // users.email is globally UNIQUE, not per-apartment, so the flat
            // label has to be part of it.
            'email'            => strtolower(str_replace(' ', '', $label) . '-' . $name) . '@example.test',
            'password_hash'    => password_hash('secret123', PASSWORD_BCRYPT),
            'role'             => $role,
            'status'           => $status,
        ]);
    };

    return [
        'apartment_id' => $apartmentId,
        'admin'        => $mk('Admin', 'admin'),
        'suspended'    => $mk('Suspendedboss', 'admin', 'suspended'),
        'payer'        => $mk('Payer', 'resident'),
        'creator'      => $mk('Creator', 'resident'),
        'offboarded'   => $mk('Leaver', 'resident', 'offboarded'),
    ];
}

$a = seedFlat($pdo, 'Flat A');
$b = seedFlat($pdo, 'Flat B');

function actAs(int $userId): void
{
    $row = Auth::sanitiseUser((array) Database::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]));
    $u = new ReflectionProperty(Auth::class, 'user');
    $u->setAccessible(true);
    $u->setValue(null, $row);
    $r = new ReflectionProperty(Auth::class, 'resolved');
    $r->setAccessible(true);
    $r->setValue(null, true);
}

function addExpense(int $apartmentId, int $payerId, int $createdBy, string $title): int
{
    $id = Database::insert('expenses', [
        'apartment_id'    => $apartmentId,
        'reference_no'    => 'EX-' . strtoupper(bin2hex(random_bytes(3))),
        'title'           => $title,
        'amount'          => 25.00,
        'paid_by_user_id' => $payerId,
        'created_by'      => $createdBy,
        'split_type'      => 'equal',
        'expense_date'    => gmdate('Y-m-d'),
    ]);
    Database::insert('expense_splits', ['expense_id' => $id, 'user_id' => $payerId, 'share_amount' => 25.00]);
    return $id;
}

function addNotice(int $apartmentId, int $authorId, string $title): int
{
    return Database::insert('announcements', [
        'apartment_id' => $apartmentId,
        'user_id'      => $authorId,
        'title'        => $title,
    ]);
}

// Flat A gets one of everything the registry can wipe.
$aExpense  = addExpense($a['apartment_id'], $a['payer'], $a['creator'], 'Flat A groceries');
$aContribution = Database::insert('contributions', [
    'apartment_id'   => $a['apartment_id'],
    'from_user_id'   => $a['payer'],
    'amount'         => '250.00',
    'method'         => 'cash',
    'contributed_on' => gmdate('Y-m-d'),
    'created_by'     => $a['admin'],
]);
$aNotice   = addNotice($a['apartment_id'], $a['admin'], 'Flat A notice');
$aArea     = Database::insert('chore_areas', [
    'apartment_id' => $a['apartment_id'],
    'name'         => 'Kitchen',
    'slug'         => 'kitchen-a',
    'frequency'    => 'daily',
]);
Database::insert('chore_tasks', [
    'chore_area_id'    => $aArea,
    'assigned_user_id' => $a['payer'],
    'task_date'        => gmdate('Y-m-d'),
    'status'           => 'pending',
]);
Database::insert('invites', [
    'apartment_id' => $a['apartment_id'],
    'email'        => 'newcomer@example.test',
    'token_hash'   => hash('sha256', 'flat-a-token'),
    'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400),
]);
Database::insert('reminders', [
    'apartment_id' => $a['apartment_id'],
    'user_id'      => $a['payer'],
    'type'         => 'system',
    'title'        => 'Flat A reminder',
]);
Database::insert('sessions', [
    'user_id'    => $a['payer'],
    'token_hash' => hash('sha256', 'flat-a-session'),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
]);
Database::insert('magic_links', [
    'user_id'    => $a['payer'],
    'token_hash' => hash('sha256', 'flat-a-magic'),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);

// Flat B gets a parallel set that must survive untouched.
$bExpense = addExpense($b['apartment_id'], $b['payer'], $b['payer'], 'Flat B groceries');
$bContribution = Database::insert('contributions', [
    'apartment_id'   => $b['apartment_id'],
    'from_user_id'   => $b['payer'],
    'amount'         => '99.00',
    'method'         => 'cash',
    'contributed_on' => gmdate('Y-m-d'),
    'created_by'     => $b['admin'],
]);
$bNotice  = addNotice($b['apartment_id'], $b['admin'], 'Flat B notice');
Database::insert('invites', [
    'apartment_id' => $b['apartment_id'],
    'email'        => 'flat-b@example.test',
    'token_hash'   => hash('sha256', 'flat-b-token'),
    'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400),
]);

actAs($a['admin']);
$confirm = DataResetService::CONFIRM_PHRASE;

/* -------------------------------------------------------------------------- */

$t->group('The confirmation phrase is enforced');

$snapshot = fn(): array => [
    'expenses'  => (int) Database::value('SELECT COUNT(*) FROM expenses WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
    'notices'   => (int) Database::value('SELECT COUNT(*) FROM announcements WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
    'users'     => (int) Database::value('SELECT COUNT(*) FROM users WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
];
$before = $snapshot();

foreach (['', 'delete', 'Delete', 'DELETE!', 'REMOVE', 'DEL ETE'] as $typed) {
    $t->throws(
        static fn() => DataResetService::purge($a['apartment_id'], $a['admin'], ['notices'], $typed),
        'Type DELETE exactly',
        'a near-miss phrase "' . $typed . '" is refused'
    );
}
$t->same($before, $snapshot(), 'nothing was deleted by any refused attempt');

// Surrounding whitespace is trimmed on purpose: someone who types "DELETE " has
// still typed DELETE, and being pedantic about the space bar helps nobody.
$t->ok(
    DataResetService::preview($a['apartment_id'], $a['admin'], ['notices'])['ok'],
    'preview still passes, so the next assertions have data to work on'
);

$t->throws(
    static fn() => DataResetService::purge($a['apartment_id'], $a['admin'], [], $confirm),
    'Tick at least one',
    'an empty selection is refused'
);

$t->group('Scope dependencies');

// Matched on 'Expenses' rather than the scope's full label: the wording is UI
// copy that legitimately changes, while the rule under test -- wiping residents
// without the ledger that points at them is refused -- does not.
$t->throws(
    static fn() => DataResetService::purge($a['apartment_id'], $a['admin'], ['residents'], $confirm),
    'Expenses',
    '"residents" without "expense_ledger" is refused'
);
$t->same($before, $snapshot(), 'the refused dependency check deleted nothing');

$check = DataResetService::preview($a['apartment_id'], $a['admin'], ['residents', 'expense_ledger']);
$t->same(true, $check['ok'], 'preview accepts residents once the ledger is included');

$check = DataResetService::preview($a['apartment_id'], $a['admin'], ['notices']);
$t->same(true, $check['ok'], 'preview accepts a self-contained scope');
$t->ok($check['row_count'] >= 1, 'preview reports a row count');

$t->group('Preview matches reality');

$cat = [];
foreach (DataResetService::catalogue($a['apartment_id'], $a['admin']) as $entry) {
    $cat[$entry['key']] = $entry;
}
$t->same(
    (int) Database::value('SELECT COUNT(*) FROM announcements WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
    $cat['notices']['total'],
    'the notices tile shows the real row count'
);
$t->same(2, $cat['residents']['total'], 'the residents tile counts only removable residents');
$t->same(false, $cat['expense_categories']['has_data'], 'an empty tile is not offered as "has data"');

$t->group('Unknown scope keys are dropped');

$t->same(
    ['notices', 'reminders'],
    DataResetService::normaliseScopes(['reminders', 'notices', 'drop_table', 'reminders', '']),
    'unknown keys, blanks and duplicates are removed, registry order is kept'
);

$t->same(
    ['activity_log'],
    DataResetService::normaliseScopes(['users', 'activity_log']),
    '"users" is not a scope and is dropped; activity_log is'
);

/* -------------------------------------------------------------------------- */

$t->group('A purge wipes only what was ticked');

$out = DataResetService::purge($a['apartment_id'], $a['admin'], ['notices'], $confirm);

$t->same(['notices'], $out['scopes'], 'the report names the scope that ran');
$t->same(1, $out['removed']['notices'], 'the removed count matches the rows that existed');
$t->same(
    0,
    (int) Database::value('SELECT COUNT(*) FROM announcements WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
    'flat A notices are gone'
);
$t->same(
    0,
    (int) Database::value('SELECT COUNT(*) FROM announcement_reads WHERE announcement_id = :i', ['i' => $aNotice]),
    'read receipts went with them'
);
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM expenses WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
    'the unticked ledger is untouched');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM chore_tasks WHERE chore_area_id = :c', ['c' => $aArea]),
    'the unticked chores are untouched');

$t->group('Flat B is never reached');

$t->same(1, (int) Database::value('SELECT COUNT(*) FROM announcements WHERE id = :id', ['id' => $bNotice]),
    'flat B keeps its notice');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM expenses WHERE id = :id', ['id' => $bExpense]),
    'flat B keeps its expense');
$t->same(1, (int) Database::value(
    'SELECT COUNT(*) FROM invites WHERE token_hash = :h',
    ['h' => hash('sha256', 'flat-b-token')]
), 'flat B keeps its invite');

$t->group('Everyone is protected, not just the signed-in admin');

/*
 * The house fund is part of the ledger scope in its own right. This has to be
 * checked with expense_ledger ALONE: if residents are purged in the same call,
 * the contributions disappear via ON DELETE CASCADE from users and the scope
 * list is never actually exercised. Purging only the ledger leaves every
 * resident standing, so the row can only be removed by the scope naming it.
 */
$t->same(1, (int) Database::value(
    'SELECT COUNT(*) FROM contributions WHERE apartment_id = :a',
    ['a' => $a['apartment_id']]
), 'flat A starts with one contribution');

DataResetService::purge($a['apartment_id'], $a['admin'], ['expense_ledger'], $confirm);

$t->same(0, (int) Database::value(
    'SELECT COUNT(*) FROM contributions WHERE apartment_id = :a',
    ['a' => $a['apartment_id']]
), 'the ledger scope removes contributions without touching residents');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $a['payer']]),
    'the resident who paid in is untouched by a ledger-only purge');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM contributions WHERE id = :id', ['id' => $bContribution]),
    'flat B keeps its contribution');

DataResetService::purge(
    $a['apartment_id'],
    $a['admin'],
    ['residents', 'expense_ledger'],
    $confirm
);

$t->ok(Database::one('SELECT id FROM users WHERE id = :id', ['id' => $a['admin']]) !== null,
    'the signed-in admin survives');
$t->ok(Database::one('SELECT id FROM users WHERE id = :id', ['id' => $a['suspended']]) !== null,
    'a suspended admin survives');
$t->ok(Database::one('SELECT id FROM users WHERE id = :id', ['id' => $a['offboarded']]) !== null,
    'an already-offboarded resident survives');
$t->ok(Database::one('SELECT id FROM users WHERE id = :id', ['id' => $a['payer']]) === null,
    'an ordinary resident is removed');
$t->ok(Database::one('SELECT id FROM users WHERE id = :id', ['id' => $a['creator']]) === null,
    'every ordinary resident is removed');

$t->same(0, (int) Database::value('SELECT COUNT(*) FROM expenses WHERE apartment_id = :a', ['a' => $a['apartment_id']]),
    'the ledger scope removed flat A expenses');
$t->same(0, (int) Database::value(
    'SELECT COUNT(*) FROM expense_splits WHERE expense_id IN (SELECT id FROM expenses WHERE apartment_id = :a)',
    ['a' => $a['apartment_id']]
), 'no orphaned splits remain');
$t->same(0, (int) Database::value(
    'SELECT COUNT(*) FROM contributions WHERE apartment_id = :a',
    ['a' => $a['apartment_id']]
), 'the ledger scope removed flat A house-fund contributions too');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM contributions WHERE id = :id', ['id' => $bContribution]),
    'flat B keeps its contribution');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM expenses WHERE id = :id', ['id' => $bExpense]),
    'flat B expenses are still there after a residents purge');

$t->group('People-scoped tables follow the flat, not the request');

DataResetService::purge($a['apartment_id'], $a['admin'], ['invites'], $confirm);
$t->same(0, (int) Database::value(
    'SELECT COUNT(*) FROM invites WHERE token_hash = :h',
    ['h' => hash('sha256', 'flat-a-token')]
), 'flat A invites are gone');
$t->same(1, (int) Database::value(
    'SELECT COUNT(*) FROM invites WHERE token_hash = :h',
    ['h' => hash('sha256', 'flat-b-token')]
), 'flat B invites are untouched');

DataResetService::purge($a['apartment_id'], $a['admin'], ['signed_in_sessions'], $confirm);
$t->same(0, (int) Database::value(
    'SELECT COUNT(*) FROM magic_links WHERE token_hash = :h',
    ['h' => hash('sha256', 'flat-a-magic')]
), 'flat A magic links are gone');
$t->same(1, (int) Database::value('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $b['payer']]),
    'flat B people keep their accounts, so their sessions still resolve');

$t->group('The reset is recorded even when the log is wiped');

DataResetService::purge($a['apartment_id'], $a['admin'], ['activity_log'], $confirm);
$entry = Database::one(
    'SELECT * FROM activity_log WHERE action = :a ORDER BY id DESC LIMIT 1',
    ['a' => 'data.purged']
);
$t->ok($entry !== null, 'a data.purged entry survives wiping activity_log');
if ($entry !== null) {
    $t->same($a['admin'], (int) $entry['user_id'], 'the entry names the admin who did it');
    $t->same($a['apartment_id'], (int) $entry['apartment_id'], 'the entry is scoped to the right flat');
    $meta = json_decode((string) $entry['meta'], true);
    $t->same(['activity_log'], $meta['scopes'] ?? null, 'the entry records which scopes ran');
}

$t->group('The table allow-list cannot be widened at runtime');

$deleteFrom = new ReflectionMethod(DataResetService::class, 'deleteFrom');
$deleteFrom->setAccessible(true);
$t->throws(
    static fn() => $deleteFrom->invoke(null, $a['apartment_id'], 'users; DROP TABLE users'),
    'unknown table',
    'an unregistered table name is refused'
);
$t->throws(
    static fn() => $deleteFrom->invoke(null, $a['apartment_id'], 'sqlite_master'),
    'unknown table',
    'sqlite_master is refused'
);
$t->ok((bool) Database::one('SELECT id FROM users WHERE id = :id', ['id' => $b['admin']]),
    'the users table is still there afterwards');

$t->group('A failure part way through rolls the whole thing back');

$usersBefore = (int) Database::value('SELECT COUNT(*) FROM users');
$logBefore   = (int) Database::value('SELECT COUNT(*) FROM activity_log');
$expBefore   = (int) Database::value('SELECT COUNT(*) FROM expenses');
$contribBefore = (int) Database::value('SELECT COUNT(*) FROM contributions');

$t->throws(
    static function () use ($a, $confirm): void {
        // purge() opens its own transaction and commits, so the wipe has to be
        // nested inside a wider one to be caught mid-flight -- which is exactly
        // how a real caller (api/index.php) would lose it, e.g. on a write
        // failure after the deletes.
        Database::transaction(static function () use ($a, $confirm): void {
            DataResetService::purge($a['apartment_id'], $a['admin'], ['expense_ledger'], $confirm);
            throw new RuntimeException('simulated failure after the wipe');
        });
    },
    'simulated failure',
    'the failure surfaces to the caller'
);

$t->same($usersBefore, (int) Database::value('SELECT COUNT(*) FROM users'), 'users are intact after the rollback');
$t->same($expBefore, (int) Database::value('SELECT COUNT(*) FROM expenses'),
    'the expenses the aborted wipe deleted are back');
$t->same($contribBefore, (int) Database::value('SELECT COUNT(*) FROM contributions'),
    'the contributions the aborted wipe deleted are back');
$t->same($logBefore, (int) Database::value('SELECT COUNT(*) FROM activity_log'),
    'the audit entry written inside the aborted transaction was rolled back too');

$t->summary();