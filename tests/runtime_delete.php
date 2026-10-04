<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Runtime delete tests
 * ---------------------------------------------------------------------------
 * Exercises the delete/retire paths against a real database engine instead of
 * only reading them. sql/schema.sql is translated to SQLite (see
 * tests/support/mysql_to_sqlite.php) so this runs anywhere PHP does -- no MySQL
 * server and no web request needed.
 *
 *     php tests/runtime_delete.php
 *
 * The service layer reaches the database and the current user through two
 * private static handles, Database::$pdo and Auth::$user. Both are injected by
 * reflection, so the code under test runs completely unmodified.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/support/mysql_to_sqlite.php';
require_once __DIR__ . '/support/TestCase.php';

/* -------------------------------------------------------------------------- */
/*  Harness: an in-memory database with the real schema loaded                 */
/* -------------------------------------------------------------------------- */

final class Harness
{
    public PDO $pdo;
    public int $apartmentId = 0;
    public int $adminId = 0;
    public int $payerId = 0;
    public int $creatorId = 0;
    public int $bystanderId = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // SQLite has no MySQL date functions; the schema and services use them.
        sqlite_shims($this->pdo);

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec(sqlite_schema_sql());

        // Hand the service layer this connection instead of a MySQL one.
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue(null, $this->pdo);

        $this->seed();
    }

    private function seed(): void
    {
        $this->apartmentId = Database::insert('apartments', [
            'name'             => 'Test Flat',
            'currency_code'    => 'BDT',
            'currency_symbol'  => '৳',
            'week_starts_on'   => 1,
            'meal_deadline_hr' => 10,
        ]);

        $this->adminId     = $this->user('Admin', 'admin@example.test', 'admin');
        $this->payerId     = $this->user('Payer', 'payer@example.test', 'resident');
        $this->creatorId   = $this->user('Creator', 'creator@example.test', 'resident');
        $this->bystanderId = $this->user('Bystander', 'by@example.test', 'resident');
    }

    private function user(string $name, string $email, string $role): int
    {
        return Database::insert('users', [
            'apartment_id'    => $this->apartmentId,
            'participant_code'=> 'FM-' . strtoupper(substr(md5($email), 0, 5)),
            'full_name'       => $name,
            'email'           => $email,
            'password_hash'   => password_hash('secret123', PASSWORD_BCRYPT),
            'role'            => $role,
            'status'          => 'active',
        ]);
    }

    /** Make $userId the acting user for the rest of the test. */
    public function actAs(int $userId): void
    {
        $row = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($row === null) {
            throw new RuntimeException('no such user ' . $userId);
        }
        // Mirrors Auth::user(), which runs every row through sanitiseUser().
        $row = Auth::sanitiseUser($row);

        $userProp = new ReflectionProperty(Auth::class, 'user');
        $userProp->setAccessible(true);
        $userProp->setValue(null, $row);

        $resolved = new ReflectionProperty(Auth::class, 'resolved');
        $resolved->setAccessible(true);
        $resolved->setValue(null, true);
    }

    public function expense(int $payerId, int $createdBy, string $title = 'Tea'): int
    {
        $id = Database::insert('expenses', [
            'apartment_id'    => $this->apartmentId,
            'reference_no'    => 'EX-T-' . bin2hex(random_bytes(3)),
            'title'           => $title,
            'amount'          => 10.00,
            'paid_by_user_id' => $payerId,
            'created_by'      => $createdBy,
            'split_type'      => 'equal',
            'expense_date'    => gmdate('Y-m-d'),
        ]);
        Database::insert('expense_splits', [
            'expense_id'   => $id,
            'user_id'      => $payerId,
            'share_amount' => 10.00,
        ]);
        return $id;
    }

    public function notice(int $authorId, string $title = 'Notice'): int
    {
        return Database::insert('announcements', [
            'apartment_id' => $this->apartmentId,
            'user_id'      => $authorId,
            'title'        => $title,
            'body'         => 'body',
        ]);
    }

    public function choreArea(string $name = 'Kitchen'): int
    {
        return Database::insert('chore_areas', [
            'apartment_id' => $this->apartmentId,
            'name'         => $name,
            'slug'         => strtolower($name) . '-' . bin2hex(random_bytes(2)),
            'frequency'    => 'daily',
        ]);
    }

    public function choreTask(int $areaId, string $date, string $status = 'pending', int $assigned = 0): int
    {
        return Database::insert('chore_tasks', [
            'chore_area_id'    => $areaId,
            'assigned_user_id' => $assigned ?: $this->payerId,
            'task_date'        => $date,
            'status'           => $status,
        ]);
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        return Database::value($sql, $params);
    }
}

/* -------------------------------------------------------------------------- */
/*  Suite                                                                      */
/* -------------------------------------------------------------------------- */

$t = new TestCase();

$h = new Harness();

/* -- expenses: soft delete ------------------------------------------------- */

$t->group('ExpenseService::delete — soft delete');

$id = $h->expense($h->payerId, $h->payerId, 'Payer own');
$h->actAs($h->payerId);
ExpenseService::delete($id, $h->payerId);

$t->same(1, (int) $h->scalar('SELECT is_deleted FROM expenses WHERE id = :id', ['id' => $id]),
    'row survives and is flagged is_deleted = 1');
$t->same(1, (int) $h->scalar('SELECT COUNT(*) FROM expense_splits WHERE expense_id = :id', ['id' => $id]),
    'splits are kept for the audit trail');
$t->same(0, (int) $h->scalar(
    'SELECT COUNT(*) FROM expenses WHERE id = :id AND is_deleted = 0', ['id' => $id]
), 'the deleted row no longer matches the active filter');

$listed = ExpenseService::listFor($h->apartmentId, []);
$t->ok(!in_array($id, array_map('intval', array_column($listed, 'id')), true),
    'listFor() omits the deleted expense');

$id = $h->expense($h->payerId, $h->payerId, 'Payer again');
$h->actAs($h->payerId);
ExpenseService::delete($id, $h->payerId);
$t->same(0, (int) $h->scalar(
    'SELECT COUNT(*) FROM expenses WHERE id = :id AND is_deleted = 0', ['id' => $id]
), 'a repeat delete is reported as gone from the active set');

$t->group('ExpenseService::delete — authorisation');

$id = $h->expense($h->payerId, $h->payerId, 'Not yours');
$h->actAs($h->bystanderId);
$t->throws(
    static fn() => ExpenseService::delete($id, $h->bystanderId),
    'only remove expenses you logged',
    'an uninvolved resident is refused'
);
$t->same(0, (int) $h->scalar('SELECT is_deleted FROM expenses WHERE id = :id', ['id' => $id]),
    'the refused expense is untouched');

$h->actAs($h->adminId);
ExpenseService::delete($id, $h->adminId);
$t->same(1, (int) $h->scalar('SELECT is_deleted FROM expenses WHERE id = :id', ['id' => $id]),
    'an admin may delete an expense they neither paid for nor logged');

$id = $h->expense($h->payerId, $h->creatorId, 'Logged by creator');
$h->actAs($h->creatorId);
ExpenseService::delete($id, $h->creatorId);
$t->same(1, (int) $h->scalar('SELECT is_deleted FROM expenses WHERE id = :id', ['id' => $id]),
    'the creator may delete even when someone else paid');

$t->throws(
    static fn() => ExpenseService::delete(999999, $h->adminId),
    'no longer exists',
    'a missing expense reports a 400-style error, not a 500'
);

/* -- notices: hard delete + read cascade ----------------------------------- */

$t->group('NoticeBoard::remove — hard delete');

$id   = $h->notice($h->payerId, 'Author own');
$read = Database::insert('announcement_reads', [
    'announcement_id' => $id,
    'user_id'         => $h->bystanderId,
    'read_at'         => gmdate('Y-m-d H:i:s'),
]);
$t->ok($read > 0, 'the read receipt seeded');

$h->actAs($h->payerId);
NoticeBoard::remove($h->apartmentId, $id, $h->payerId);

$t->same(0, (int) $h->scalar('SELECT COUNT(*) FROM announcements WHERE id = :id', ['id' => $id]),
    'the notice is gone');
$t->same(0, (int) $h->scalar('SELECT COUNT(*) FROM announcement_reads WHERE announcement_id = :id', ['id' => $id]),
    'its read receipts cascade away with it');

$id = $h->notice($h->payerId, 'Not yours');
$h->actAs($h->bystanderId);
$t->throws(
    static fn() => NoticeBoard::remove($h->apartmentId, $id, $h->bystanderId),
    'author or an admin',
    'a resident cannot delete someone else\'s notice'
);
$t->same(1, (int) $h->scalar('SELECT COUNT(*) FROM announcements WHERE id = :id', ['id' => $id]),
    'the refused notice survives');

$h->actAs($h->adminId);
NoticeBoard::remove($h->apartmentId, $id, $h->adminId);
$t->same(0, (int) $h->scalar('SELECT COUNT(*) FROM announcements WHERE id = :id', ['id' => $id]),
    'an admin can delete any notice');

/* -- chore areas: retire ---------------------------------------------------- */

$t->group('DashboardService::deleteChoreArea — retire');

$area   = $h->choreArea('Washroom');
$future = gmdate('Y-m-d', strtotime('+2 days'));
$past   = gmdate('Y-m-d', strtotime('-2 days'));

$futurePending = $h->choreTask($area, $future, 'pending');
$futureDone    = $h->choreTask($area, gmdate('Y-m-d', strtotime('+3 days')), 'done');
$pastPending   = $h->choreTask($area, $past, 'pending');

$h->actAs($h->adminId);
$out = DashboardService::deleteChoreArea($h->apartmentId, $area);

$t->same(true, $out['retired'], 'the call reports a retirement');
$t->same(0, (int) $h->scalar('SELECT is_active FROM chore_areas WHERE id = :id', ['id' => $area]),
    'the area is flagged inactive, not deleted');
$t->same(1, (int) $h->scalar('SELECT COUNT(*) FROM chore_areas WHERE id = :id', ['id' => $area]),
    'the area row is kept for history');

$t->same('skipped', (string) $h->scalar('SELECT status FROM chore_tasks WHERE id = :id', ['id' => $futurePending]),
    'today-or-later pending tasks are skipped');
$t->same('done', (string) $h->scalar('SELECT status FROM chore_tasks WHERE id = :id', ['id' => $futureDone]),
    'completed tasks are left alone');
$t->same('pending', (string) $h->scalar('SELECT status FROM chore_tasks WHERE id = :id', ['id' => $pastPending]),
    'past tasks keep their real status');

$t->throws(
    static fn() => DashboardService::deleteChoreArea($h->apartmentId, 999999),
    'no longer exists',
    'retiring a missing area reports a 400-style error'
);

/* -- referential integrity on a hard delete -------------------------------- */

$t->group('Foreign keys — expense purge');

$id = $h->expense($h->payerId, $h->payerId, 'Purge me');
$t->same(1, (int) $h->scalar('SELECT COUNT(*) FROM expense_splits WHERE expense_id = :id', ['id' => $id]),
    'the split exists to begin with');

Database::delete('expenses', 'id', $id);
$t->same(0, (int) $h->scalar('SELECT COUNT(*) FROM expense_splits WHERE expense_id = :id', ['id' => $id]),
    'hard-deleting an expense cascades to its splits');

/* -- audit trail ------------------------------------------------------------ */

$t->group('Audit trail');

$id = $h->expense($h->payerId, $h->payerId, 'Audited');
$h->actAs($h->payerId);
ExpenseService::delete($id, $h->payerId);

$row = Database::one(
    'SELECT * FROM activity_log WHERE action = :a AND entity_id = :e ORDER BY id DESC LIMIT 1',
    ['a' => 'expense.deleted', 'e' => $id]
);
$t->ok($row !== null, 'the deletion is written to activity_log');
if ($row !== null) {
    $t->same($h->payerId, (int) $row['user_id'], 'the log names the acting user');
}

$t->summary();
