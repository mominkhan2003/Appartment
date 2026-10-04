<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Roles & fine-grained permissions
 * ---------------------------------------------------------------------------
 * `users.role` (admin|resident) stays the coarse gate that every route guard
 * reads, because rewriting that contract would mean touching every guard in
 * the app for no user-visible gain. On top of it a resident may be given one
 * role from the `roles` table, which carries a list of permission keys.
 *
 * This service is the single source of truth for:
 *   - the permission vocabulary (PERMISSIONS),
 *   - who may create / edit / assign a role,
 *   - the invariant that an apartment is never left without an admin.
 *
 * Two rules are enforced here and nowhere else, so they cannot drift:
 *   1. `role='admin'` OR a role carrying '*' or 'admin.access' makes
 *      Auth::isAdmin() true, which keeps every existing admin route working.
 *   2. The last active admin of an apartment can never be demoted, unroled or
 *      removed. The same rule already lives in ResidentService for offboarding;
 *      it is duplicated deliberately, because losing it here would strand the
 *      flat with no way back in.
 */

declare(strict_types=1);

final class RoleService
{
    /** Every permission an apartment can hand out. Order drives the UI. */
    public const PERMISSIONS = [
        'admin.access'    => 'Full admin panel access',
        'resident.view'   => 'See the resident roster',
        'resident.manage' => 'Invite, edit and offboard residents',
        'expense.manage'  => 'Record and settle expenses',
        'chore.verify'    => 'Verify and skip chores',
        'chore.manage'    => 'Edit chore areas and rotation rules',
        'notice.manage'   => 'Post and pin notices',
        'report.view'     => 'Read the financial reports',
        'role.manage'     => 'Create roles and assign them',
        'data.purge'      => 'Permanently delete household data',
    ];

    /** Marks a role that grants everything, now and as permissions are added. */
    public const WILDCARD = '*';

    /**
     * Roles seeded by the patch. Kept in code so the UI can show sensible
     * starting points even before the seed rows are inserted.
     *
     * @return array<string,array{name:string,slug:string,description:string,permissions:string[]}>
     */
    public const TEMPLATES = [
        'manager' => [
            'name'        => 'Manager',
            'slug'        => 'manager',
            'description' => 'Full control of the flat, including reports and data management.',
            'permissions' => [self::WILDCARD],
        ],
        'treasurer' => [
            'name'        => 'Treasurer',
            'slug'        => 'treasurer',
            'description' => 'Handles money: expenses, settlements and the financial reports.',
            'permissions' => ['report.view', 'expense.manage', 'resident.view'],
        ],
        'chore_captain' => [
            'name'        => 'Chore captain',
            'slug'        => 'chore_captain',
            'description' => 'Runs the rota: verifies chores and edits rotation rules.',
            'permissions' => ['chore.verify', 'chore.manage', 'resident.view'],
        ],
        'resident' => [
            'name'        => 'Resident',
            'slug'        => 'resident',
            'description' => 'The default. Takes part in meals, chores, expenses and notices.',
            'permissions' => ['resident.view'],
        ],
    ];

    /* ------------------------------------------------------------------ */
    /*  Reads                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Roles visible to an apartment: its own, plus the global templates.
     *
     * @return array<int,array<string,mixed>>
     */
    /**
     * Stop with a readable message instead of a PDO 500 when the roles table
     * has not been created yet.
     *
     * This is deliberately a ValidationException rather than a silent no-op:
     * an admin who cannot see their roles needs to be told to run
     * sql/patch_roles_profile.sql, not left wondering why the page is empty.
     */
    public static function assertReady(): void
    {
        if (!self::schemaReady()) {
            throw new ValidationException([
                'schema' => 'Roles are not set up on this server yet. '
                          . 'Run sql/patch_roles_profile.sql, then reload.',
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function listFor(int $apartmentId, bool $includeGlobal = true): array
    {
        self::assertReady();

        $sql = 'SELECT id, apartment_id, name, slug, description, permissions, is_system,
                       (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS member_count
                  FROM roles r';
        $params = [];

        if ($includeGlobal) {
            $sql .= ' WHERE r.apartment_id IS NULL OR r.apartment_id = :a';
            $params['a'] = $apartmentId;
        } else {
            $sql .= ' WHERE r.apartment_id = :a';
            $params['a'] = $apartmentId;
        }

        $sql .= ' ORDER BY r.is_system DESC, r.name';

        return array_map([self::class, 'hydrate'], Database::all($sql, $params));
    }

    public static function find(int $apartmentId, int $roleId): array
    {
        self::assertReady();

        $row = Database::one(
            'SELECT id, apartment_id, name, slug, description, permissions, is_system
               FROM roles
              WHERE id = :id
                AND (apartment_id IS NULL OR apartment_id = :a)',
            ['id' => $roleId, 'a' => $apartmentId]
        );
        if ($row === null) {
            throw new ValidationException(['role_id' => 'That role does not exist.']);
        }
        return self::hydrate($row);
    }

    /** Every role a given resident holds, for the admin roster UI. */
    public static function forUser(int $userId): array
    {
        self::assertReady();

        $rows = Database::all(
            'SELECT r.id, r.name, r.slug, r.permissions, r.is_system
               FROM roles r
               JOIN users u ON u.role_id = r.id
              WHERE u.id = :id',
            ['id' => $userId]
        );
        return array_map([self::class, 'hydrate'], $rows);
    }

    /* ------------------------------------------------------------------ */
    /*  Writes                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Create an apartment-scoped role.
     *
     * @throws ValidationException
     */
    public static function create(int $apartmentId, int $actorId, array $input): array
    {
        self::assertReady();

        $clean = Validator::make($input)
            ->string('name', 'Role name', 2, 60)
            ->string('slug', 'Slug', 2, 60)
            ->check();

        $name = trim((string) $clean['name']);
        $slug = strtolower(trim((string) $clean['slug']));

        if (!self::isValidSlug($slug)) {
            throw new ValidationException([
                'slug' => 'Use lowercase letters, digits and underscores, starting with a letter.',
            ]);
        }

        $permissions = self::normalisePermissions($input['permissions'] ?? []);
        if ($permissions === []) {
            throw new ValidationException([
                'permissions' => 'Pick at least one permission, or tick "Everything".',
            ]);
        }

        $exists = Database::value('SELECT 1 FROM roles WHERE slug = :s', ['s' => $slug]);
        if ($exists !== null) {
            throw new ValidationException(['slug' => 'That slug is already taken.']);
        }

        $roleId = Database::insert('roles', [
            'apartment_id' => $apartmentId,
            'name'         => $name,
            'slug'         => $slug,
            'description'  => self::nullableNote($input['description'] ?? null, 255),
            'permissions'  => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            'is_system'    => 0,
        ]);

        ActivityLog::record(
            'role.created', 'role', $roleId,
            'Created the role "' . $name . '"',
            ['permissions' => $permissions]
        );

        return self::find($apartmentId, $roleId);
    }

    /**
     * Rename / re-permission an apartment role. Global templates are refused.
     */
    public static function update(int $apartmentId, int $roleId, array $input): array
    {
        $role = self::find($apartmentId, $roleId);

        if ((int) $role['is_system'] === 1 || $role['apartment_id'] === null) {
            throw new ValidationException([
                'role_id' => 'Built-in roles cannot be edited. Copy one into your flat instead.',
            ]);
        }

        $data = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
                throw new ValidationException(['name' => 'Role name must be 2 to 60 characters.']);
            }
            $data['name'] = $name;
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = self::nullableNote($input['description'] ?? null, 255);
        }

        if (array_key_exists('permissions', $input)) {
            $permissions = self::normalisePermissions($input['permissions'] ?? []);
            if ($permissions === []) {
                throw new ValidationException([
                    'permissions' => 'Pick at least one permission, or tick "Everything".',
                ]);
            }
            $data['permissions'] = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        }

        // Shrinking a role must not strip the flat of its last admin.
        self::assertNotOrphaning($apartmentId, $role, $data);

        if ($data !== []) {
            Database::update('roles', $data, 'id', $roleId);
            ActivityLog::record(
                'role.updated', 'role', $roleId,
                'Updated the role "' . ($data['name'] ?? $role['name']) . '"',
                ['fields' => array_keys($data)]
            );
        }

        return self::find($apartmentId, $roleId);
    }

    /**
     * Delete an apartment role. Members fall back to their plain `role`.
     * Refuses to orphan the flat.
     */
    public static function delete(int $apartmentId, int $actorId, int $roleId): void
    {
        $role = self::find($apartmentId, $roleId);

        if ($role['apartment_id'] === null || (int) $role['is_system'] === 1) {
            throw new ValidationException([
                'role_id' => 'Built-in roles cannot be deleted.',
            ]);
        }

        self::assertNotOrphaning($apartmentId, $role, []);

        $members = (int) Database::value(
            'SELECT COUNT(*) FROM users WHERE role_id = :r AND apartment_id = :a',
            ['r' => $roleId, 'a' => $apartmentId]
        );

        // role_id is ON DELETE SET NULL, so members keep working with their
        // plain `role` -- no orphaned rows, no manual cleanup needed.
        Database::delete('roles', 'id', $roleId);

        ActivityLog::record(
            'role.deleted', 'role', $roleId,
            'Deleted the role "' . $role['name'] . '"',
            ['members_reassigned' => $members]
        );
    }

    /**
     * Give a resident a role, or clear it with a null id.
     *
     * @param int|null $roleId null removes the assignment
     */
    public static function assign(int $apartmentId, int $targetUserId, ?int $roleId): array
    {
        self::assertReady();

        $target = Database::one(
            'SELECT id, full_name, role, role_id, status FROM users
              WHERE id = :id AND apartment_id = :a',
            ['id' => $targetUserId, 'a' => $apartmentId]
        );
        if ($target === null) {
            throw new ValidationException(['user_id' => 'That resident is not in this apartment.']);
        }

        if ($roleId === null) {
            // Dropping the only thing that made this person an admin.
            $losesAdmin = $target['role'] !== 'admin' && self::isEffectiveAdmin($target);
            if ($losesAdmin && self::activeAdminCount($apartmentId) <= 1) {
                throw new ValidationException([
                    'user_id' => 'This is the only admin. Promote someone else first.',
                ]);
            }

            Database::update('users', ['role_id' => null], 'id', $targetUserId);

            ActivityLog::record(
                'role.unassigned', 'user', $targetUserId,
                'Removed the role from ' . $target['full_name']
            );
            return ['user_id' => $targetUserId, 'role_id' => null];
        }

        $role = self::find($apartmentId, $roleId);

        // Assigning a weak role must not silently demote a plain admin.
        if ($target['role'] === 'admin' && !self::grantsAdmin($role['permissions'])) {
            if (self::activeAdminCount($apartmentId) <= 1) {
                throw new ValidationException([
                    'user_id' => 'This is the only admin. Promote someone else before '
                               . 'removing their admin rights.',
                ]);
            }
            Database::query(
                "UPDATE users SET role = 'resident' WHERE id = :id",
                ['id' => $targetUserId]
            );
        }

        // Giving a weak role to someone whose only admin rights came from their
        // previous role would strip them, so guard that direction too.
        if ($target['role'] !== 'admin'
            && self::isEffectiveAdmin($target)
            && !self::grantsAdmin($role['permissions'])
            && self::activeAdminCount($apartmentId) <= 1) {
            throw new ValidationException([
                'user_id' => 'This is the only admin. Promote someone else before '
                           . 'removing their admin rights.',
            ]);
        }

        Database::update('users', ['role_id' => $roleId], 'id', $targetUserId);

        ActivityLog::record(
            'role.assigned', 'user', $targetUserId,
            'Gave ' . $target['full_name'] . ' the role "' . $role['name'] . '"',
            ['role_id' => $roleId, 'slug' => $role['slug']]
        );

        return ['user_id' => $targetUserId, 'role_id' => $roleId, 'role' => $role];
    }

    /**
     * Residents with their current role, for the assignment list.
     *
     * Deliberately separate from ExpenseService::residentsWithMeta(): that one
     * feeds the expense split picker and has no business knowing about roles,
     * and widening a shared projection to serve two unrelated screens is how
     * those screens start depending on each other.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function assignableUsers(int $apartmentId): array
    {
        self::assertReady();

        return Database::all(
            "SELECT u.id, u.full_name, u.participant_code, u.avatar_color,
                    u.role, u.role_id, u.status,
                    room.code AS room_code,
                    assigned.name AS role_name
               FROM users u
               LEFT JOIN rooms  room    ON room.id = u.room_id
               LEFT JOIN roles  assigned ON assigned.id = u.role_id
              WHERE u.apartment_id = :a
                AND u.status <> 'offboarded'
              ORDER BY u.full_name",
            ['a' => $apartmentId]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                         */
    /* ------------------------------------------------------------------ */

    /** Decode the JSON permission list and annotate each key. */
    private static function hydrate(array $row): array
    {
        $decoded = $row['permissions'] ?? null;
        $perms   = is_string($decoded) ? json_decode($decoded, true) : $decoded;
        $perms   = is_array($perms) ? array_values(array_filter($perms, 'is_string')) : [];

        $row['permissions'] = $perms;
        $row['is_wildcard'] = in_array(self::WILDCARD, $perms, true);
        $row['labels']      = self::describe($perms);
        $row['is_global']   = $row['apartment_id'] === null;
        $row['editable']    = !$row['is_global'] && (int) ($row['is_system'] ?? 0) !== 1;

        return $row;
    }

    /** @param string[] $perms @return array<int,string> */
    private static function describe(array $perms): array
    {
        if (in_array(self::WILDCARD, $perms, true)) {
            return ['Everything'];
        }
        $out = [];
        foreach ($perms as $p) {
            $out[] = self::PERMISSIONS[$p] ?? $p;
        }
        return $out;
    }

    public static function grantsAdmin(array|string|null $permissions): bool
    {
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            $permissions = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($permissions)) {
            return false;
        }
        return in_array(self::WILDCARD, $permissions, true)
            || in_array('admin.access', $permissions, true);
    }

    /**
     * Accept the permissions field as an array or a JSON string, drop unknown
     * keys, and sort so the stored value is stable regardless of tick order.
     *
     * @return string[]
     */
    public static function normalisePermissions(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : array_filter(explode(',', $raw), 'strlen');
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key) {
            $key = trim((string) $key);
            if ($key === self::WILDCARD) {
                return [self::WILDCARD];
            }
            if ($key !== '' && isset(self::PERMISSIONS[$key])) {
                $out[$key] = true;
            }
        }

        $keys = array_keys($out);
        sort($keys);
        return $keys;
    }

    /** Slugs are URL/log safe: lowercase letters, digits, underscores. */
    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,59}$/', $slug);
    }

    /**
     * Canonical definition of "this apartment still has an admin".
     *
     * An admin is a user whose legacy `role` is 'admin', OR whose assigned role
     * grants admin.access / '*'. Every last-admin guard in the app routes
     * through here so the definition cannot drift between features.
     *
     * Falls back to the legacy column-only count when the roles table has not
     * been patched in yet, so a partially migrated database still works.
     */
    public static function activeAdminCount(int $apartmentId): int
    {
        if (!self::schemaReady()) {
            return (int) Database::value(
                "SELECT COUNT(*) FROM users
                  WHERE apartment_id = :a AND role = 'admin' AND status = 'active'",
                ['a' => $apartmentId]
            );
        }

        return (int) Database::value(
            'SELECT COUNT(*)
               FROM users u
               LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.apartment_id = :a
                AND u.status = \'active\'
                AND (
                      u.role = \'admin\'
                   OR (r.id IS NOT NULL
                       AND (JSON_CONTAINS(r.permissions, :wild)
                         OR JSON_CONTAINS(r.permissions, :access)))
                    )',
            [
                'a'      => $apartmentId,
                'wild'   => json_encode(self::WILDCARD),
                'access' => json_encode('admin.access'),
            ]
        );
    }

    /** True once the roles table exists, so callers can degrade gracefully. */
    public static function schemaReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Database::tableExists('roles');
            } catch (Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * Does this user row currently count as an admin, taking the assigned role
     * into account? Used to detect "admin only because of a role".
     */
    public static function isEffectiveAdmin(array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        if (!self::schemaReady() || empty($user['role_id'])) {
            return false;
        }

        $perms = Database::value(
            'SELECT permissions FROM roles WHERE id = :r',
            ['r' => (int) $user['role_id']]
        );
        return self::grantsAdmin(is_string($perms) ? $perms : null);
    }

    /**
     * Refuse a change that would take away the flat's only way back in.
     *
     * Only residents who are admins *because of this role* are at risk: a user
     * whose legacy `role` column already says 'admin' stays an admin whatever
     * happens to the role, because SET NULL never touches that column.
     */
    private static function assertNotOrphaning(int $apartmentId, array $role, array $pending): void
    {
        $nextPerms = $pending['permissions'] ?? json_encode($role['permissions'], JSON_UNESCAPED_UNICODE);

        // The role still grants admin afterwards, so nobody loses anything.
        if (self::grantsAdmin($nextPerms)) {
            return;
        }

        $roleId = (int) $role['id'];

        // Active residents who would stop being admins if this role is narrowed
        // or deleted, because 'admin' is not set on their users row.
        $atRisk = (int) Database::value(
            "SELECT COUNT(*) FROM users
              WHERE apartment_id = :a
                AND role_id = :r
                AND status = 'active'
                AND role <> 'admin'",
            ['a' => $apartmentId, 'r' => $roleId]
        );

        if ($atRisk === 0) {
            return; // nobody was relying on this role for admin rights
        }

        if (self::activeAdminCount($apartmentId) - $atRisk <= 0) {
            throw new ValidationException([
                'role_id' => 'This role is the only thing keeping an admin in place. '
                            . 'Promote someone else first.',
            ]);
        }
    }

    private static function nullableNote(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, $max);
    }
}