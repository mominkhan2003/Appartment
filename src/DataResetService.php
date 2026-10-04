<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Selective data reset ("start fresh")
 * ---------------------------------------------------------------------------
 * Lets an admin tick exactly which parts of a flat's data to wipe, so the
 * household can start a fresh month, a fresh rota or a fresh ledger without
 * losing the residents themselves.
 *
 * Design rules this file exists to enforce:
 *
 *  1. Nothing is deleted unless it was ticked. Every scope is opt-in.
 *  2. The signed-in admin is never deleted, and neither is any other active
 *     admin -- otherwise the flat loses its way back in.
 *  3. Deleting a resident is only offered once the financial history that
 *     would cascade away with them is also ticked, because
 *     expenses.paid_by_user_id is ON DELETE CASCADE: removing a payer quietly
 *     destroys the expenses OTHER people are still being charged for.
 *  4. Deletes are counted before they happen and reported after, so the admin
 *     sees exactly what went rather than trusting a "done" toast.
 *  5. Everything runs in one transaction, so a failure half way through leaves
 *     the database exactly as it was.
 *
 * The scope registry is the single place that knows what can be wiped and in
 * what order. Each entry lists its tables child-first, which matters even
 * though most children would CASCADE anyway: deleting explicitly means the
 * reported row counts match what actually disappeared.
 */

declare(strict_types=1);

final class DataResetService
{
    /** Phrase the admin must type before anything irreversible runs. */
    public const CONFIRM_PHRASE = 'DELETE';

    /**
     * The wipeable surface of a flat.
     *
     * tables      child-first delete order.
     * requires    sibling scopes that must be ticked too.
     * keep        tables deliberately left alone inside this scope.
     *
     * @return array<string,array<string,mixed>>
     */
    public const SCOPES = [
        'meal_history' => [
            'label'       => 'Meal plans, votes and responses',
            'description' => 'Every weekly plan, who cooked, what was voted on and who ate.',
            'icon'        => 'bi-cup-hot',
            'tables'      => ['suggestion_votes', 'meal_suggestions', 'meal_participants', 'meals', 'meal_plans'],
            'requires'    => [],
            'group'       => 'daily',
        ],
        'expense_ledger' => [
            'label'       => 'Expenses, settlements and house fund',
            'description' => 'The whole financial ledger: shared costs, shares, recorded payments and cash residents put into the house fund.',
            'icon'        => 'bi-wallet2',
            'tables'      => ['contributions', 'expense_splits', 'settlements', 'expenses'],
            'requires'    => [],
            'group'       => 'money',
        ],
        'expense_categories' => [
            'label'       => 'Expense categories',
            'description' => 'The category list, e.g. Groceries, Utilities.',
            'icon'        => 'bi-tags',
            'tables'      => ['expense_categories'],
            'requires'    => [],
            'group'       => 'money',
        ],
        'chore_history' => [
            'label'       => 'Chore history',
            'description' => 'Completed, skipped and pending chore instances.',
            'icon'        => 'bi-check2-square',
            'tables'      => ['chore_tasks'],
            'requires'    => [],
            'group'       => 'daily',
        ],
        'chore_areas' => [
            'label'       => 'Chore areas and rotation rules',
            'description' => 'The cleanable zones themselves, so the rota can be rebuilt from scratch.',
            'icon'        => 'bi-sliders',
            'tables'      => ['chore_tasks', 'chore_areas'],
            'requires'    => [],
            'group'       => 'daily',
        ],
        'notices' => [
            'label'       => 'Notices and read receipts',
            'description' => 'Announcements posted to the board, and who had read them.',
            'icon'        => 'bi-megaphone',
            'tables'      => ['announcement_reads', 'announcements'],
            'requires'    => [],
            'group'       => 'flat',
        ],
        'reminders' => [
            'label'       => 'Reminders and notifications',
            'description' => 'The in-app notification feed, including anything unread.',
            'icon'        => 'bi-bell',
            'tables'      => ['reminders'],
            'requires'    => [],
            'group'       => 'flat',
        ],
        'offboarding' => [
            'label'       => 'Offboarding checklists',
            'description' => 'Move-out checklists for residents who have already left.',
            'icon'        => 'bi-person-dash',
            'tables'      => ['offboarding_tasks'],
            'requires'    => [],
            'group'       => 'people',
        ],
        'invites' => [
            'label'       => 'Pending invitations',
            'description' => 'Outstanding invites, including their tokens.',
            'icon'        => 'bi-envelope',
            'tables'      => ['invites'],
            'requires'    => [],
            'group'       => 'people',
        ],
        'signed_in_sessions' => [
            'label'       => 'Remembered devices',
            'description' => 'Every "remember me" token and unused magic link. Signs people out everywhere.',
            'icon'        => 'bi-key',
            'tables'      => ['magic_links', 'sessions'],
            'requires'    => [],
            'group'       => 'people',
        ],
        'activity_log' => [
            'label'       => 'Activity log',
            'description' => 'The audit trail of who did what. Removing it also removes the record of this reset.',
            'icon'        => 'bi-clock-history',
            'tables'      => ['activity_log'],
            'requires'    => [],
            'group'       => 'flat',
        ],
        'residents' => [
            'label'       => 'Residents (except you and other admins)',
            'description' => 'Removes every non-admin resident. Their expenses, meals and chores go with them.',
            'icon'        => 'bi-people',
            'tables'      => [],
            'requires'    => ['expense_ledger'],
            'group'       => 'people',
        ],
    ];

    /* ------------------------------------------------------------------ */
    /*  Preview                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * What is on offer right now, with live row counts and any unsatisfied
     * dependency. Drives every checkbox on the page.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function catalogue(int $apartmentId, int $actorId): array
    {
        $out = [];

        foreach (self::SCOPES as $key => $scope) {
            $counts = [];
            $total  = 0;

            foreach ($scope['tables'] as $table) {
                $c = self::countIn($apartmentId, $table);
                $counts[$table] = $c;
                $total += $c;
            }

            if ($key === 'residents') {
                $total = self::removableResidentCount($apartmentId, $actorId);
            }

            $out[] = [
                'key'         => $key,
                'label'       => $scope['label'],
                'description' => $scope['description'],
                'icon'        => $scope['icon'],
                'group'       => $scope['group'],
                'requires'    => $scope['requires'],
                'rows'        => $counts,
                'total'       => $total,
                'has_data'    => $total > 0,
            ];
        }

        return $out;
    }

    /**
     * Validate a ticked set without deleting anything.
     *
     * @param string[] $wanted scope keys
     * @return array{ok:bool, errors:array<string,string>, totals:array<string,int>, row_count:int}
     */
    public static function preview(int $apartmentId, int $actorId, array $wanted): array
    {
        $wanted = self::normaliseScopes($wanted);
        $errors = [];
        $totals = [];
        $rowCount = 0;

        foreach ($wanted as $key) {
            $scope = self::SCOPES[$key];

            foreach ($scope['requires'] as $need) {
                if (!in_array($need, $wanted, true)) {
                    $errors[$key] = 'Tick "' . self::SCOPES[$need]['label'] . '" as well -- '
                                  . 'it would be destroyed by this.';
                }
            }

            $scopeRows = 0;
            foreach ($scope['tables'] as $table) {
                $c = self::countIn($apartmentId, $table);
                $totals[$table] = $c;
                $scopeRows += $c;
            }
            if ($key === 'residents') {
                $scopeRows = self::removableResidentCount($apartmentId, $actorId);
            }
            $totals[$key . ':rows'] = $scopeRows;
            $rowCount += $scopeRows;
        }

        return [
            'ok'        => $errors === [],
            'errors'    => $errors,
            'totals'    => $totals,
            'row_count' => $rowCount,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Execute                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Run the wipe. All or nothing.
     *
     * @param string[] $wanted
     * @return array{scopes:string[], removed:array<string,int>, row_count:int, protected:array<string,int>}
     */
    public static function purge(int $apartmentId, int $actorId, array $wanted, string $confirm): array
    {
        if (trim($confirm) !== self::CONFIRM_PHRASE) {
            throw new ValidationException([
                'confirm' => 'Type ' . self::CONFIRM_PHRASE . ' exactly to confirm.',
            ]);
        }

        $wanted = self::normaliseScopes($wanted);
        if ($wanted === []) {
            throw new ValidationException(['scopes' => 'Tick at least one thing to remove.']);
        }

        $check = self::preview($apartmentId, $actorId, $wanted);
        if (!$check['ok']) {
            throw new ValidationException(['scopes' => implode(' ', $check['errors'])]);
        }

        $removed = [];

        // One transaction for the whole thing: a half-applied reset would be
        // worse than none, because the admin cannot tell what survived.
        return Database::transaction(static function () use (
            $apartmentId, $actorId, $wanted, $removed
        ) {
            foreach ($wanted as $key) {
                $scope = self::SCOPES[$key];

                if ($key === 'residents') {
                    $removed[$key] = self::deleteResidents($apartmentId, $actorId);
                    continue;
                }

                $count = 0;
                foreach ($scope['tables'] as $table) {
                    $count += self::deleteFrom($apartmentId, $table);
                }
                $removed[$key] = $count;
            }

            $total = array_sum($removed);

            // Logged after the wipe so that ticking activity_log still leaves
            // a record of what was destroyed.
            ActivityLog::record(
                'data.purged', 'apartment', $apartmentId,
                'Reset ' . count($wanted) . ' data scope(s), removing ' . $total . ' row(s)',
                [
                    'scopes' => $wanted,
                    'rows'   => $removed,
                    'actor'  => $actorId,
                ],
                $actorId
            );

            // Residents who are still here should know the floor moved.
            foreach (self::remainingAdmins($apartmentId) as $adminId) {
                if ($adminId === $actorId) {
                    continue;
                }
                Reminder::push(
                    $apartmentId,
                    $adminId,
                    'system',
                    'Household data was reset',
                    'An admin removed ' . $total . ' row(s): ' . implode(', ', $wanted) . '.',
                    'warning'
                );
            }

            return [
                'scopes'    => $wanted,
                'removed'   => $removed,
                'row_count' => $total,
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Drop unknown keys rather than trusting the request, and keep the
     * registry's own order so deletes run predictably.
     *
     * @return string[]
     */
    public static function normaliseScopes(array $wanted): array
    {
        $clean = [];
        foreach ($wanted as $key) {
            $key = trim((string) $key);
            if ($key !== '' && isset(self::SCOPES[$key]) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        // Registry order, not request order.
        return array_values(array_filter(
            array_keys(self::SCOPES),
            static fn(string $k): bool => in_array($k, $clean, true)
        ));
    }

    /**
     * How many residents may be removed: everyone except the actor and every
     * admin.
     *
     * Every admin, not just the active ones. A suspended admin is still the
     * person who gets emailed when something goes wrong, and a reset that
     * silently deleted them would leave the flat with nobody able to fix it.
     * Narrowing this to status = 'active' would delete them.
     *
     * $actorId is required because the count and the delete must agree
     * exactly -- a preview that disagrees with the delete is worse than none.
     */
    public static function removableResidentCount(int $apartmentId, int $actorId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM users
              WHERE apartment_id = :a
                AND status <> 'offboarded'
                AND id <> :actor
                AND role <> 'admin'",
            ['a' => $apartmentId, 'actor' => $actorId]
        );
    }

    private static function deleteResidents(int $apartmentId, int $actorId): int
    {
        // Belt and braces: the predicate is repeated at delete time, not just
        // in the count, so a change between preview and confirm cannot widen it.
        $stmt = Database::query(
            "DELETE FROM users
              WHERE apartment_id = :a
                AND status <> 'offboarded'
                AND id <> :actor
                AND role <> 'admin'",
            ['a' => $apartmentId, 'actor' => $actorId]
        );
        return $stmt->rowCount();
    }

    /** @return int[] */
    private static function remainingAdmins(int $apartmentId): array
    {
        return array_map('intval', array_column(
            Database::all(
                "SELECT id FROM users
                  WHERE apartment_id = :a AND role = 'admin' AND status = 'active'",
                ['a' => $apartmentId]
            ),
            'id'
        ));
    }

    /**
     * How each table reaches the apartment.
     *
     * Most tables carry apartment_id directly. The rest are reachable only
     * through a parent, and the predicate below is what scopes them to one
     * flat. These column names come from the FOREIGN KEYs in sql/schema.sql;
     * getting one wrong would silently delete another flat's rows, so they are
     * spelled out here rather than guessed.
     */
    private const SCOPING = [
        // direct
        'meal_plans'          => 'apartment_id = :a',
        'settlements'         => 'apartment_id = :a',
        'expenses'            => 'apartment_id = :a',
        'contributions'       => 'apartment_id = :a',
        'expense_categories'  => 'apartment_id = :a',
        'chore_areas'         => 'apartment_id = :a',
        'announcements'       => 'apartment_id = :a',
        'reminders'           => 'apartment_id = :a',
        'offboarding_tasks'   => 'apartment_id = :a',
        'invites'             => 'apartment_id = :a',
        'activity_log'        => 'apartment_id = :a',

        // through a parent
        'meals' => 'meal_plan_id IN (
                        SELECT id FROM meal_plans WHERE apartment_id = :a)',

        'meal_participants' => 'meal_id IN (
                        SELECT m.id FROM meals m
                          JOIN meal_plans p ON p.id = m.meal_plan_id
                         WHERE p.apartment_id = :a)',

        'meal_suggestions' => 'meal_id IN (
                        SELECT m.id FROM meals m
                          JOIN meal_plans p ON p.id = m.meal_plan_id
                         WHERE p.apartment_id = :a)',

        'suggestion_votes' => 'suggestion_id IN (
                        SELECT ms.id FROM meal_suggestions ms
                          JOIN meals m       ON m.id  = ms.meal_id
                          JOIN meal_plans p  ON p.id  = m.meal_plan_id
                         WHERE p.apartment_id = :a)',

        'expense_splits' => 'expense_id IN (
                        SELECT id FROM expenses WHERE apartment_id = :a)',

        'chore_tasks' => 'chore_area_id IN (
                        SELECT id FROM chore_areas WHERE apartment_id = :a)',

        'announcement_reads' => 'announcement_id IN (
                        SELECT id FROM announcements WHERE apartment_id = :a)',

        // belong to people rather than the flat
        'magic_links' => 'user_id IN (
                        SELECT id FROM users WHERE apartment_id = :a)',

        'sessions' => 'user_id IN (
                        SELECT id FROM users WHERE apartment_id = :a)',
    ];

    private static function knownTable(string $table): bool
    {
        return isset(self::SCOPING[$table]);
    }

    private static function countIn(int $apartmentId, string $table): int
    {
        if (!self::knownTable($table)) {
            throw new RuntimeException('Refusing to count unknown table: ' . $table);
        }

        return (int) Database::value(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::SCOPING[$table],
            ['a' => $apartmentId]
        );
    }

    /** @return int rows removed */
    private static function deleteFrom(int $apartmentId, string $table): int
    {
        if (!self::knownTable($table)) {
            // The registry is hard-coded, so this only trips if someone edits it
            // badly. Better a hard stop than an unscoped DELETE.
            throw new RuntimeException('Refusing to purge unknown table: ' . $table);
        }

        $stmt = Database::query(
            'DELETE FROM `' . $table . '` WHERE ' . self::SCOPING[$table],
            ['a' => $apartmentId]
        );
        return $stmt->rowCount();
    }
}