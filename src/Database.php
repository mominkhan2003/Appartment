<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Database facade
 * ---------------------------------------------------------------------------
* Autoloaded from src/ like every other service. The single PDO handle lives
 * here; db.* credentials come from config/config.php. (config/database.php is
 * an optional shim that returns this same handle -- pages load Bootstrap.php.)
 *
 * Everything goes through named parameters -- there is no string interpolation
 * of user input into SQL anywhere in this project.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;
    private static ?array $cfg = null;

    /** Build the DSN from config. */
    public static function dsn(): string
    {
        $c = self::config()['db'];
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            (int) $c['port'],
            $c['database'],
            $c['charset']
        );
    }

    /** Lazily open (and memoize) the PDO handle. */
    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c = self::config()['db'];

        try {
            self::$pdo = new PDO(self::dsn(), $c['username'], $c['password'], $c['options']);
        } catch (PDOException $e) {
            self::fail(
                'Cannot connect to the database.',
                $e,
                (self::config()['app']['env'] ?? 'local') !== 'production'
            );
        }

        // Strict-ish SQL mode so silent truncation / zero dates are errors.
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");

        return self::$pdo;
    }

    /** SELECT helper. */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        self::assertBindingsMatch($sql, $params);

        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fail loudly, and usefully, on the two binding mistakes PDO reports as the
     * same opaque `SQLSTATE[HY093]: Invalid parameter number`:
     *
     *   - a placeholder in the SQL with nothing bound to it, and
     *   - a bound name the SQL never declares.
     *
     * HY093 arrives without saying which statement failed. That is why a real
     * one of these went undiagnosed for so long: the log said only
     * "Database.php:64", the stack trace was not captured, and the failing query
     * was in a helper the page used indirectly. Every caller goes through here,
     * so checking once turns a mystery into a message naming the statement.
     *
     * Repeated placeholders are caught too -- PDO cannot reuse a named marker
     * with emulation off, but reports it as HY093 just the same.
     *
     * Strings and comments are stripped first, so a `:name` inside a quoted
     * literal is not mistaken for a marker.
     */
    private static function assertBindingsMatch(string $sql, array $params): void
    {
        // Cheap bail-out: no parameters at all is the overwhelmingly common case
        // for schema/config queries and needs no parsing.
        if ($params === []) {
            return;
        }

        // Strings and comments are stripped first, so a `:name` inside a quoted
        // literal is not mistaken for a marker.
        $clean = preg_replace(
            ["/'(?:[^'\\\\]|\\\\.)*'/", '/"(?:[^"\\\\]|\\\\.)*"/',
             '/--[^\n]*/', '#/\*.*?\*/#s'],
            ' ',
            $sql
        ) ?? $sql;

        $positional = substr_count($clean, '?');

        // Positional mode: `WHERE id IN (?,?,?)` bound to a plain list. Counted
        // rather than name-matched, and deliberately separate from the named
        // branch -- PDO treats an array with integer keys as positional, so a
        // list bound to named markers (or the reverse) is the mistake to catch.
        if ($positional > 0) {
            $named = preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $clean, $nm);
            if ($named > 0) {
                throw new RuntimeException(sprintf(
                    'PDO binding mismatch: the statement has both %d positional '
                    . '? marker(s) and named placeholder(s) (%s), which cannot be '
                    . 'bound in one call. SQL: %s',
                    $positional,
                    implode(', ', array_unique($nm[1])),
                    self::forMessage($sql)
                ));
            }
            if (count($params) !== $positional) {
                throw new RuntimeException(sprintf(
                    'PDO binding mismatch: %d positional ? marker(s) but %d '
                    . 'value(s) bound. SQL: %s',
                    $positional,
                    count($params),
                    self::forMessage($sql)
                ));
            }
            return;
        }

        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $clean, $m) === 0) {
            throw new RuntimeException(sprintf(
                'PDO binding mismatch: %d value(s) bound (%s) but the query '
                . 'declares no placeholders. SQL: %s',
                count($params),
                implode(', ', array_keys($params)),
                self::forMessage($sql)
            ));
        }

        $declared  = $m[1];
        $unbound   = array_values(array_unique(array_diff($declared, array_keys($params))));
        $unused    = array_values(array_diff(array_keys($params), array_unique($declared)));
        $repeated  = array_values(array_unique(array_diff_assoc(
            $declared,
            array_unique($declared)
        )));

        if ($unbound === [] && $unused === [] && $repeated === []) {
            return;
        }

        $parts = [];
        if ($repeated !== []) {
            // The trap: PDO emits one positional marker per occurrence, so
            // binding the name once leaves the extras unfilled.
            $parts[] = 'repeated placeholder(s): ' . implode(', ', array_map(
                static fn(string $n): string => ':' . $n,
                $repeated
            )) . ' -- use :name1, :name2 instead';
        }
        if ($unbound !== []) {
            $parts[] = 'declared but not bound: ' . implode(', ', array_map(
                static fn(string $n): string => ':' . $n,
                $unbound
            ));
        }
        if ($unused !== []) {
            $parts[] = 'bound but not declared: ' . implode(', ', $unused);
        }

        throw new RuntimeException(sprintf(
            'PDO binding mismatch (%s). SQL: %s',
            implode('; ', $parts),
            self::forMessage($sql)
        ));
    }

    /** One-line, length-capped SQL for an exception message or the log. */
    private static function forMessage(string $sql): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        return strlen($flat) > 300 ? substr($flat, 0, 297) . '...' : $flat;
    }

    /** First row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Single scalar value (first column of first row). */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** INSERT and return the new auto-increment id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`,`', $cols) . '`',
            ':' . implode(', :', $cols)
        );
        self::query($sql, $data);
        return (int) self::conn()->lastInsertId();
    }

    /**
     * INSERT IGNORE — returns the number of rows actually inserted
     * (0 when the row already existed and was skipped).
     */
    public static function insertOrIgnore(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT IGNORE INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`,`', $cols) . '`',
            ':' . implode(', :', $cols)
        );
        return self::query($sql, $data)->rowCount();
    }

    /** UPDATE ... WHERE $whereCol = $whereVal. Returns affected rows. */
    public static function update(string $table, array $data, string $whereCol, mixed $whereVal): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = "`$c` = :set_$c";
        }
        $params = [];
        foreach ($data as $c => $v) {
            $params["set_$c"] = $v;
        }
        $params['where_val'] = $whereVal;

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :where_val',
            $table,
            implode(', ', $sets),
            $whereCol
        );
        return self::query($sql, $params)->rowCount();
    }

    /** DELETE ... WHERE $whereCol = $whereVal. */
    public static function delete(string $table, string $whereCol, mixed $whereVal): int
    {
        return self::query(
            sprintf('DELETE FROM `%s` WHERE `%s` = :v', $table, $whereCol),
            ['v' => $whereVal]
        )->rowCount();
    }

    /* ---------------------------------------------------------------- */
    /*  Transactions                                                     */
    /* ---------------------------------------------------------------- */

    /**
     * Nesting depth.
     *
     * MySQL has no nested transactions, so an inner transaction() cannot open
     * one of its own -- it has to join the outer one. Without a counter the
     * inner commit() would end the outer transaction early, and the outer
     * rollback() would then find nothing to roll back and silently do nothing.
     * That is how a "all or nothing" wipe ends up half applied while the caller
     * is told it failed, so the depth is tracked explicitly: only the outermost
     * pair touches the connection.
     */
    private static int $depth = 0;

    public static function begin(): void
    {
        if (self::$depth === 0 && !self::conn()->inTransaction()) {
            self::conn()->beginTransaction();
        }
        self::$depth++;
    }

    public static function commit(): void
    {
        if (self::$depth > 1) {
            self::$depth--;
            return;
        }
        self::$depth = 0;
        if (self::conn()->inTransaction()) {
            self::conn()->commit();
        }
    }

    /**
     * Abandon the whole nest. An exception inside any level must undo every
     * level, not just its own, so the depth is reset rather than decremented.
     */
    public static function rollback(): void
    {
        self::$depth = 0;
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }

    /** Run $fn inside a transaction, rolling back on any throwable. */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /* ---------------------------------------------------------------- */
    /*  Introspection helpers                                            */
    /* ---------------------------------------------------------------- */

    public static function tableExists(string $table): bool
    {
        $t = self::value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $table]
        );
        return (int) $t > 0;
    }

    /** Health probe used by /api/index.php?action=health */
    public static function health(): array
    {
        $cfg = self::config()['db'];
        return [
            'connected' => self::tableExists('users'),
            'server'    => (string) self::value('SELECT VERSION()'),
            'database'  => $cfg['database'],
            'schema'    => self::tableExists('chore_tasks') ? 'installed' : 'missing',
            'time'      => gmdate('c'),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  Internals                                                        */
    /* ---------------------------------------------------------------- */

    private static function config(): array
    {
        if (self::$cfg === null) {
            self::$cfg = require dirname(__DIR__) . '/config/config.php';
        }
        return self::$cfg;
    }

    private static function fail(string $message, Throwable $e, bool $verbose): never
    {
        Diag::record('db_connect', $e->getMessage(), ['user_visible' => $message]);
        error_log('[FlatMate][DB] ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $detail = $verbose
            ? '<pre style="white-space:pre-wrap;text-align:left;background:#1e1e2e;color:#cdd6f4;padding:16px;border-radius:10px">'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>'
            : '';
        echo '<!doctype html><meta charset="utf-8"><title>Database error</title>'
            . '<div style="font-family:system-ui;max-width:760px;margin:12vh auto;padding:0 20px">'
            . '<h1 style="color:#c01c28">ðŸš« Database connection failed</h1>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . $detail
            . '<p style="color:#666">Check <code>config/config.php</code> &rarr; <code>db</code>, then run '
            . '<code>sql/schema.sql</code> and <code>sql/seed.sql</code> in phpMyAdmin.</p></div>';
        exit;
    }
}

