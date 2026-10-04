<?php
/**
 * Translates sql/schema.sql (MySQL dialect) into an SQLite-compatible script.
 *
 * This exists so the service layer can be exercised for real in CLI tests
 * without a MySQL server. It is a TEST helper -- nothing in the app calls it.
 *
 * Preserved: columns, PRIMARY KEY, FOREIGN KEY (including ON DELETE CASCADE),
 *            UNIQUE constraints.
 * Dropped:   ENGINE/CHARSET/COLLATE, non-unique KEY indexes, UNSIGNED,
 *            ON UPDATE CURRENT_TIMESTAMP, ENUM collapsed to TEXT, COMMENTs.
 */

declare(strict_types=1);

if (!function_exists('mysql_to_sqlite')) {
    function mysql_to_sqlite(string $sql): string
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        $statements = [];
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }

        $out        = [];
        $uniqIndex  = [];

        foreach ($statements as $stmt) {
            if (preg_match('/^SET\b/i', $stmt)) {
                continue;
            }

            if (!preg_match('/^CREATE TABLE\s+`?(\w+)`?/i', $stmt, $m)) {
                $out[] = $stmt . ';';
                continue;
            }

            $table = $m[1];

            // Table options are dropped before the closing paren is located:
            // a COMMENT such as COMMENT='UNIQUE(area,date) ...' contains a
            // ')' that would otherwise be mistaken for the end of the body.
            $stmt  = mysql_strip_table_options($stmt);
            $open  = strpos($stmt, '(');
            $close = strrpos($stmt, ')');
            if ($open === false || $close === false || $close < $open) {
                $out[] = $stmt . ';';
                continue;
            }

            $head = substr($stmt, 0, $open);
            $body = substr($stmt, $open + 1, $close - $open - 1);
            $tail = substr($stmt, $close + 1);

            $keep = [];
            foreach (preg_split('/\R/', $body) ?: [] as $rawLine) {
                $line = trim($rawLine);
                if ($line === '') {
                    continue;
                }

                // MySQL allows a trailing comma before the closing paren.
                $line = rtrim($line, ',');

                // Non-unique indexes do not affect behaviour under test.
                if (preg_match('/^(KEY|FULLTEXT KEY|SPATIAL KEY)\s+/i', $line)) {
                    continue;
                }

                // Table-level COMMENT '...' has no SQLite equivalent.
                if (preg_match('/^COMMENT\b/i', $line)) {
                    continue;
                }

                // A wrapped column definition can leave its modifiers alone on
                // their own lines (e.g. "NOT NULL DEFAULT 'active'," or
                // "ON UPDATE CURRENT_TIMESTAMP,"). They carry no type, so they
                // cannot be columns, and SQLite has no ON UPDATE clause.
                if (!preg_match('/^[`"]/i', $line)
                    && preg_match(
                        '/^(NOT\s+NULL|NULL|DEFAULT|AUTO_INCREMENT|ON\s+UPDATE)\b/i',
                        $line
                    )) {
                    continue;
                }

                // UNIQUE KEY name (cols) -> hoisted CREATE UNIQUE INDEX.
                if (preg_match('/^UNIQUE KEY\s+`?(\w+)`?\s*\((.+)\)$/i', $line, $um)) {
                    $uniqIndex[] = sprintf(
                        'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s);',
                        $um[1],
                        $table,
                        $um[2]
                    );
                    continue;
                }

                // AUTO_INCREMENT surrogate key -> SQLite AUTOINCREMENT. Width and
                // UNSIGNED vary across the schema (activity_log.id is BIGINT).
                if (preg_match(
                    '/^`id`\s+(?:TINYINT|SMALLINT|MEDIUMINT|BIG)?INT(?:EGER)?'
                    . '(?:\s+UNSIGNED)?(?:\s+NOT\s+NULL)?\s+AUTO_INCREMENT$/i',
                    $line
                )) {
                    $keep[] = '`id` INTEGER PRIMARY KEY AUTOINCREMENT';
                    continue;
                }

                // Redundant once id became INTEGER PRIMARY KEY.
                if (preg_match('/^PRIMARY KEY\s*\(`id`\)$/i', $line)) {
                    continue;
                }

                $line = mysql_column_to_sqlite($line);
                $keep[] = rtrim($line, ',');
            }

            $def = trim($head) . " (\n  " . implode(",\n  ", $keep) . "\n)" . rtrim($tail);

            // MySQL may wrap a foreign key so REFERENCES lands on its own line.
            $def = preg_replace('/,\s*\n\s*REFERENCES\b/i', "\n  REFERENCES", $def) ?? $def;
            $def = preg_replace('/\n\s*,\s*\)$/', "\n)", $def) ?? $def;
            $def = preg_replace('/,\s*\)$/', ')', $def) ?? $def;

            $out[] = rtrim($def) . ';';
        }

        return implode("\n\n", array_merge($out, $uniqIndex)) . "\n";
    }
}

if (!function_exists('mysql_column_to_sqlite')) {
    /** Removes MySQL-only decoration from a single column/constraint line. */
    function mysql_column_to_sqlite(string $line): string
    {
        $line = preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line) ?? $line;
        $line = preg_replace('/\bENUM\s*\([^)]*\)/is', 'TEXT', $line) ?? $line;
        $line = preg_replace('/\bUNSIGNED\b/i', '', $line) ?? $line;
        $line = preg_replace("/\s+COMMENT\s+'[^']*'/i", '', $line) ?? $line;

        return $line;
    }
}

if (!function_exists('mysql_strip_table_options')) {
    /**
 * Removes the table option block that trails the closing paren, keeping line
 * breaks. Anchored at the end so that column-level COMMENTs inside the body are
 * left alone for the per-line handling.
 */
    function mysql_strip_table_options(string $sql): string
    {
        $sql = preg_replace(
            '/\)\s*ENGINE\s*=\s*\w+'
            . '(?:\s+DEFAULT\s+CHARSET\s*=\s*\w+)?'
            . '(?:\s+COLLATE\s*=\s*\w+)?'
            . "(?:\s+COMMENT\s*=\s*'[^']*')?\s*$/is",
            ')',
            $sql
        ) ?? $sql;

        return trim($sql);
    }
}

if (!function_exists('sqlite_schema_sql')) {
    /** The translated schema DDL for the repository's sql/schema.sql. */
    function sqlite_schema_sql(?string $schemaPath = null): string
    {
        $schemaPath ??= dirname(__DIR__, 2) . '/sql/schema.sql';
        $mysql = file_get_contents($schemaPath);
        if ($mysql === false) {
            throw new RuntimeException('cannot read ' . $schemaPath);
        }

        return mysql_to_sqlite($mysql);
    }
}