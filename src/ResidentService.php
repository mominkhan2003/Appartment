<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Resident Service  (onboarding / offboarding)
 * ---------------------------------------------------------------------------
 * Join  : invite -> accept -> allocate room + duty group -> active
 * Leave : offboarding checklist -> balance must reach 0.00 -> offboarded
 *
 * The offboarding gate is the important part. Moving out with the flat's
 * money unresolved is the single most common source of conflict in shared
 * housing, so it is enforced in the database write path, not just the UI.
 */

declare(strict_types=1);

final class ResidentService
{
    /* ================================================================== */
    /*  Read                                                               */
    /* ================================================================== */

    /** Everyone, with the derived fields the residents table needs. */
    public static function all(int $apartmentId): array
    {
        $rows = Database::all(
            'SELECT u.*,
                    r.code AS room_code, r.name AS room_name,
                    g.name AS duty_group_name, g.color AS duty_group_color
               FROM users u
               LEFT JOIN rooms r       ON r.id = u.room_id
               LEFT JOIN duty_groups g ON g.id = u.duty_group_id
              WHERE u.apartment_id = :a
              ORDER BY FIELD(u.status, "active", "invited", "suspended", "offboarded"),
                       u.full_name',
            ['a' => $apartmentId]
        );

        $balances = BalanceEngine::memberBalances($apartmentId, true);

        return array_map(static function (array $u) use ($balances): array {
            $uid  = (int) $u['id'];
            $net  = $balances[$uid]['net_cents'] ?? 0;

            $u['id']              = $uid;
            $u['net_cents']       = $net;
            $u['net']             = Money::toAmount($net);
            $u['net_label']       = Money::format($net);
            $u['direction']       = Money::direction($net);
            $u['is_settled']      = $net === 0;
            $u['chores_pending']  = (int) Database::value(
                "SELECT COUNT(*) FROM chore_tasks t
                   JOIN chore_areas ca ON ca.id = t.chore_area_id
                  WHERE t.assigned_user_id = :u1 AND ca.apartment_id = :a
                    AND t.status = 'pending' AND t.task_date <= CURDATE()",
                ['u1' => $uid, 'a' => (int) $u['apartment_id']]
            );
            $u['chores_total']    = (int) Database::value(
                'SELECT COUNT(*) FROM chore_tasks t
                   JOIN chore_areas ca ON ca.id = t.chore_area_id
                  WHERE t.assigned_user_id = :u1 AND ca.apartment_id = :a',
                ['u1' => $uid, 'a' => (int) $u['apartment_id']]
            );
            $u['offboarding']     = self::offboardingProgress($uid);
            $u['initials']        = self::initials((string) $u['full_name']);
            return $u;
        }, $rows);
    }

    public static function find(int $apartmentId, int $userId): array
    {
        $u = Database::one(
            'SELECT u.*, r.code AS room_code, r.name AS room_name,
                    g.name AS duty_group_name, g.color AS duty_group_color
               FROM users u
               LEFT JOIN rooms r       ON r.id = u.room_id
               LEFT JOIN duty_groups g ON g.id = u.duty_group_id
               WHERE u.apartment_id = :a AND u.id = :u1',
            ['a' => $apartmentId, 'u1' => $userId]
        );
        if ($u === null) {
            return [];
        }
        $balance = BalanceEngine::statementFor($apartmentId, $userId);
        $u['balance']       = $balance['member'] ?? [];
        $u['offboarding']   = self::offboardingProgress($userId);
        $u['initials']      = self::initials((string) $u['full_name']);
        return $u;
    }

    /** Roster for pickers: rooms, duty groups and active residents. */
    public static function roster(int $apartmentId): array
    {
        return [
            'rooms' => Database::all(
                'SELECT r.id, r.code, r.name, r.floor, r.capacity,
                        (SELECT COUNT(*) FROM users u WHERE u.room_id = r.id AND u.status = "active") AS occupied
                   FROM rooms r
                  WHERE r.apartment_id = :a AND r.is_active = 1
                  ORDER BY r.code',
                ['a' => $apartmentId]
            ),
            'duty_groups' => Database::all(
                'SELECT g.id, g.name, g.slug, g.color, g.description,
                        (SELECT COUNT(*) FROM users u WHERE u.duty_group_id = g.id AND u.status = "active") AS members
                   FROM duty_groups g
                  WHERE g.apartment_id = :a AND g.is_active = 1
                  ORDER BY g.sort_order, g.name',
                ['a' => $apartmentId]
            ),
            'residents' => ExpenseService::residentsWithMeta($apartmentId),
        ];
    }

    /* ================================================================== */
    /*  Onboarding                                                         */
    /* ================================================================== */

    public static function invite(int $apartmentId, int $actorId, array $input): array
    {
        $email = strtolower(trim((string) $input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'That is not a valid email address.']);
        }

        $existing = Database::one('SELECT id, status FROM users WHERE email = :e', ['e' => $email]);
        if ($existing !== null) {
            throw new ValidationException(['email' => 'Someone with that email is already in the system.']);
        }
        $pending = Database::value(
            "SELECT id FROM invites WHERE email = :e AND apartment_id = :a AND status = 'pending'",
            ['e' => $email, 'a' => $apartmentId]
        );
        if ($pending !== null) {
            throw new ValidationException(['email' => 'There is already a pending invite for that email.']);
        }

        $roomId = !empty($input['room_id']) ? (int) $input['room_id'] : null;
        $groupId = !empty($input['duty_group_id']) ? (int) $input['duty_group_id'] : null;
        self::assertRoomCapacity($apartmentId, $roomId, null);
        self::assertGroupMembership($roomId, $groupId);

        $token = bin2hex(random_bytes(32));
        $inviteId = Database::insert('invites', [
            'apartment_id'  => $apartmentId,
            'email'         => $email,
            'full_name'     => $input['full_name'] ?? null,
            'role'          => $input['role'] === 'admin' ? 'admin' : 'resident',
            'room_id'       => $roomId,
            'duty_group_id' => $groupId,
            'token_hash'    => hash('sha256', $token),
            'status'        => 'pending',
            'invited_by'    => $actorId,
            'expires_at'    => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        // Pre-create the account in 'invited' so their email is reserved.
        Database::insert('users', [
            'apartment_id'     => $apartmentId,
            'participant_code' => Auth::generateParticipantCode(),
            'full_name'        => $input['full_name'] ?? explode('@', $email)[0],
            'email'            => $email,
            'role'             => $input['role'] === 'admin' ? 'admin' : 'resident',
            'status'           => 'invited',
            'room_id'          => $roomId,
            'duty_group_id'    => $groupId,
            'avatar_color'     => self::randomColor(),
        ]);

        ActivityLog::record('resident.invited', 'invite', $inviteId,
            'Invited ' . $email, ['room_id' => $roomId, 'group_id' => $groupId]);

        return [
            'invite'     => self::inviteRow($inviteId),
            'token'      => $token,
            'join_url'   => base_url('join.php?token=' . $token),
        ];
    }

    /**
     * Turn an invite into an active resident. Idempotent enough to survive a
     * double-submitted form.
     */
    public static function acceptInvite(string $token, string $password, string $fullName): array
    {
        $invite = Database::one(
            "SELECT * FROM invites WHERE token_hash = :h AND status = 'pending' AND expires_at > UTC_TIMESTAMP()",
            ['h' => hash('sha256', $token)]
        );
        if ($invite === null) {
            throw new RuntimeException('That invite link is invalid, already used, or has expired.');
        }

        $pwErrors = Auth::validatePassword($password);
        if ($pwErrors !== []) {
            throw new ValidationException(['password' => implode(' ', $pwErrors)]);
        }

        return Database::transaction(static function () use ($invite, $password, $fullName) {
            $userId = (int) Database::value(
                'SELECT id FROM users WHERE email = :e AND apartment_id = :a',
                ['e' => $invite['email'], 'a' => $invite['apartment_id']]
            );

            if ($userId === 0) {
                $userId = Database::insert('users', [
                    'apartment_id'     => (int) $invite['apartment_id'],
                    'participant_code' => Auth::generateParticipantCode(),
                    'full_name'        => $fullName,
                    'email'            => $invite['email'],
                    'role'             => $invite['role'],
                    'status'           => 'active',
                ]);
            } else {
                Database::update('users', [
                    'full_name'     => $fullName,
                    'password_hash' => Auth::hash($password),
                    'status'        => 'active',
                    'role'          => $invite['role'],
                    'room_id'       => $invite['room_id'],
                    'duty_group_id' => $invite['duty_group_id'],
                    'joined_on'     => gmdate('Y-m-d'),
                ], 'id', $userId);
            }

            Database::update('invites', [
                'status'      => 'accepted',
                'accepted_at' => gmdate('Y-m-d H:i:s'),
            ], 'id', (int) $invite['id']);

            // They join every rotation pool they are eligible for, starting today.
            DutyScheduler::rebuildAll((int) $invite['apartment_id']);

            ActivityLog::record('resident.joined', 'user', $userId,
                $fullName . ' joined the flat', ['room_id' => $invite['room_id']]);

            Reminder::push((int) $invite['apartment_id'], $userId, 'system',
                'Welcome to the flat',
                'You are in. Your cleaning duties start today — meal opt-in is separate.');

            return ['user_id' => $userId, 'apartment_id' => (int) $invite['apartment_id']];
        });
    }

    public static function revokeInvite(int $apartmentId, int $inviteId): void
    {
        Database::update('invites', ['status' => 'revoked'], 'id', $inviteId);
        ActivityLog::record('resident.invite_revoked', 'invite', $inviteId);
    }

public static function invites(int $apartmentId): array
    {
        return array_map([self::class, 'decorateInviteRow'], Database::all(
            'SELECT i.*, r.code AS room_code, g.name AS duty_group_name, u.full_name AS inviter_name
               FROM invites i
               LEFT JOIN rooms r       ON r.id = i.room_id
               LEFT JOIN duty_groups g ON g.id = i.duty_group_id
               LEFT JOIN users u       ON u.id = i.invited_by
              WHERE i.apartment_id = :a
              ORDER BY FIELD(i.status, "pending", "accepted", "revoked", "expired"), i.created_at DESC',
            ['a' => $apartmentId]
        ));
    }

    /**
     * Resolve a raw invite token for the public join page.
     *
     * Public by design: the caller is not signed in yet, so this must not
     * leak anything sensitive. It returns apartment/room/group context plus
     * whether the token is still usable.
     *
     * @return array<string,mixed> empty array when the token is unknown or spent
     */
    public static function inviteRowByToken(string $token): array
    {
        if (trim($token) === '') {
            return [];
        }

        $row = Database::one(
            'SELECT i.id, i.status, i.expires_at, i.email,
                    r.code AS room_code, g.name AS group_name,
                    a.name AS apartment_name
               FROM invites i
               LEFT JOIN rooms r      ON r.id = i.room_id
               LEFT JOIN duty_groups g ON g.id = i.duty_group_id
               LEFT JOIN apartments a ON a.id = i.apartment_id
              WHERE i.token_hash = :h',
            ['h' => hash('sha256', $token)]
        );

        if ($row === null) {
            return [];
        }

        $expired = strtotime((string) $row['expires_at']) < time();
        $row['is_expired'] = $expired || $row['status'] !== 'pending';
        $row['usable']     = !$row['is_expired'];

        // Never hand the invited address back to an anonymous caller.
        unset($row['email']);

        return $row;
    }

    private static function inviteRow(int $inviteId): array
    {
        $row = Database::one(
            'SELECT i.*, r.code AS room_code, g.name AS duty_group_name, u.full_name AS inviter_name
               FROM invites i
               LEFT JOIN rooms r       ON r.id = i.room_id
               LEFT JOIN duty_groups g ON g.id = i.duty_group_id
               LEFT JOIN users u       ON u.id = i.invited_by
              WHERE i.id = :id',
            ['id' => $inviteId]
        );
        return $row === null ? [] : self::decorateInviteRow($row);
    }

    /**
     * Add the display-only fields every invite row carries.
     *
     * Takes an already-selected row rather than an id: invites() reads them all
     * in one query, and handing that row straight to a function that expected an
     * int was the TypeError behind the invite page failing.
     */
    private static function decorateInviteRow(array $row): array
    {
        $row['is_expired'] = strtotime((string) $row['expires_at']) < time();
        // The raw token is returned once, at invite() time only — it is
        // never recoverable from storage (we keep just its SHA-256).
        $row['accept_path'] = base_url('join.php?token=<invite-token>');
        return $row;
    }

    /* ================================================================== */
    /*  Profile edits                                                      */
    /* ================================================================== */

    /** Admin edit: role, room, duty group, status. */
    public static function update(int $apartmentId, int $targetId, int $actorId, array $input): array
    {
        $user = Database::one(
            'SELECT * FROM users WHERE id = :u1 AND apartment_id = :a',
            ['u1' => $targetId, 'a' => $apartmentId]
        );
        if ($user === null) {
            throw new RuntimeException('That resident is not in this apartment.');
        }

        $data = [];

        if (array_key_exists('full_name', $input) && trim((string) $input['full_name']) !== '') {
            $data['full_name'] = trim((string) $input['full_name']);
        }
        if (array_key_exists('phone', $input)) {
            $data['phone'] = $input['phone'] === '' ? null : (string) $input['phone'];
        }
        if (array_key_exists('room_id', $input)) {
            $roomId = $input['room_id'] === null || $input['room_id'] === '' ? null : (int) $input['room_id'];
            self::assertRoomCapacity($apartmentId, $roomId, $targetId);
            $data['room_id'] = $roomId;
        }
        if (array_key_exists('duty_group_id', $input)) {
            $groupId = $input['duty_group_id'] === null || $input['duty_group_id'] === ''
                ? null : (int) $input['duty_group_id'];
            self::assertGroupMembership(
                $data['room_id'] ?? $user['room_id'],
                $groupId
            );
            $data['duty_group_id'] = $groupId;
        }
        if (array_key_exists('role', $input) && in_array($input['role'], ['admin', 'resident'], true)) {
            // Never let the last admin be demoted — that would orphan the flat.
            if ($input['role'] === 'resident' && $user['role'] === 'admin') {
                $admins = (int) Database::value(
                    "SELECT COUNT(*) FROM users WHERE apartment_id = :a AND role = 'admin' AND status = 'active'",
                    ['a' => $apartmentId]
                );
                if ($admins <= 1) {
                    throw new ValidationException(['role' => 'This is the only admin. Promote someone else first.']);
                }
            }
            $data['role'] = $input['role'];
        }
        if (array_key_exists('status', $input) && in_array($input['status'], ['active', 'suspended'], true)) {
            $data['status'] = $input['status'];
        }

        if ($data === []) {
            return self::find($apartmentId, $targetId);
        }

        Database::update('users', $data, 'id', $targetId);

        // Room or group change => the rotation must be re-synced.
        if (isset($data['room_id']) || isset($data['duty_group_id']) || isset($data['status'])) {
            DutyScheduler::rebuildAll($apartmentId);
        }

        ActivityLog::record('resident.updated', 'user', $targetId,
            'Updated ' . ($data['full_name'] ?? 'profile'), array_keys($data));

        return self::find($apartmentId, $targetId);
    }

    /* ================================================================== */
    /*  Offboarding                                                        */
    /* ================================================================== */

    /** The default departure checklist for a resident. */
    private static function defaultChecklist(int $apartmentId, int $userId): void
    {
        $items = [
            ['Confirm the move-out date with the owner',            'admin',    1, 1],
            ['Settle the outstanding shared balance to ' . config('app.currency', '\u{20AC}') . '0.00', 'finance', 1, 2],
            ['Return the room key and the gate fob',                 'property', 1, 3],
            ['Clear personal items from the shared fridge and shelves', 'property', 0, 4],
            ['Final reading of the electricity and gas meters',     'admin',    1, 5],
            ['Transfer Wi-Fi / subscription ownership',              'admin',    0, 6],
        ];
        foreach ($items as [$label, $category, $blocking, $order]) {
            Database::insert('offboarding_tasks', [
                'apartment_id' => $apartmentId,
                'user_id'      => $userId,
                'label'        => $label,
                'category'     => $category,
                'is_blocking'  => $blocking,
                'sort_order'   => $order,
            ]);
        }
    }

    /** Start the departure workflow (idempotent). */
    public static function beginOffboarding(int $apartmentId, int $userId, int $actorId): array
    {
        $user = Database::one(
            'SELECT * FROM users WHERE id = :u1 AND apartment_id = :a',
            ['u1' => $userId, 'a' => $apartmentId]
        );
        if ($user === null) {
            throw new RuntimeException('That resident is not in this apartment.');
        }
        if ($user['status'] === 'offboarded') {
            throw new RuntimeException('That resident has already moved out.');
        }

        $exists = Database::value(
            'SELECT 1 FROM offboarding_tasks WHERE user_id = :u1',
            ['u1' => $userId]
        );
        if ($exists === null) {
            self::defaultChecklist($apartmentId, $userId);
        }

        ActivityLog::record('resident.offboarding_started', 'user', $userId,
            'Offboarding started for ' . $user['full_name']);

        return self::offboarding($apartmentId, $userId);
    }

    public static function offboarding(int $apartmentId, int $userId): array
    {
        $user = Database::one(
            'SELECT id, full_name, participant_code, avatar_color, status, room_id
               FROM users WHERE id = :u1 AND apartment_id = :a',
            ['u1' => $userId, 'a' => $apartmentId]
        );
        if ($user === null) {
            return [];
        }

        $items = Database::all(
            'SELECT * FROM offboarding_tasks WHERE user_id = :u1 ORDER BY sort_order, id',
            ['u1' => $userId]
        );

        $balance = 0;
        foreach (BalanceEngine::memberBalances($apartmentId) as $m) {
            if ($m['user_id'] === $userId) {
                $balance = $m['net_cents'];
            }
        }

        $openBlocking = array_values(array_filter(
            $items,
            static fn(array $i): bool => (int) $i['is_blocking'] === 1 && (int) $i['is_done'] === 0
        ));

        $items = array_map(static function (array $i): array {
            $i['is_done']  = (int) $i['is_done'] === 1;
            $i['category_label'] = match ($i['category']) {
                'finance'  => 'Money',
                'property' => 'Property',
                'dunno'    => 'Duty',
                default    => 'Admin',
            };
            $i['icon'] = match ($i['category']) {
                'finance'  => 'bi-cash-coin',
                'property' => 'bi-key',
                'dunno'    => 'bi-stars',
                default    => 'bi-clipboard-check',
            };
            return $i;
        }, $items);

        return [
            'user'             => $user,
            'items'            => $items,
            'balance_cents'    => $balance,
            'balance_label'    => Money::format($balance),
            'balance_settled'  => $balance === 0,
            'blocking_open'    => count($openBlocking),
            'blocking_labels'  => array_column($openBlocking, 'label'),
            'can_complete'     => $balance === 0 && $openBlocking === [],
            'percent'          => $items === [] ? 100 : (int) round(
                count(array_filter($items, static fn(array $i): bool => $i['is_done'])) / count($items) * 100
            ),
        ];
    }

    public static function toggleChecklistItem(int $apartmentId, int $itemId, bool $done): void
    {
        $ok = Database::value(
            'SELECT 1 FROM offboarding_tasks t JOIN users u ON u.id = t.user_id
              WHERE t.id = :i AND u.apartment_id = :a',
            ['i' => $itemId, 'a' => $apartmentId]
        );
        if ($ok === null) {
            throw new RuntimeException('That checklist item no longer exists.');
        }
        Database::update('offboarding_tasks', [
            'is_done'      => $done ? 1 : 0,
            'completed_at' => $done ? gmdate('Y-m-d H:i:s') : null,
        ], 'id', $itemId);
    }

    /**
     * Finalise the departure.
     *
     * Gates, in order:
     *   1. no blocking checklist item may be open
     *   2. the net balance must be exactly 0.00
     *   3. the last admin cannot be removed
     *
     * On success: status -> offboarded, room and duty group are released, and
     * the rotations are re-synced so nobody is left with a phantom duty.
     */
    public static function completeOffboarding(int $apartmentId, int $userId, int $actorId, bool $force = false): array
    {
        $strict = (bool) config('features.strict_offboarding', true) && !$force;

        $state = self::offboarding($apartmentId, $userId);
        if ($state === []) {
            throw new RuntimeException('That resident is not in this apartment.');
        }

        if ($state['user']['status'] === 'offboarded') {
            throw new RuntimeException('That resident has already moved out.');
        }

        $admins = (int) Database::value(
            "SELECT COUNT(*) FROM users
              WHERE apartment_id = :a AND role = 'admin' AND status = 'active' AND id <> :u1",
            ['a' => $apartmentId, 'u1' => $userId]
        );
        if ($admins === 0) {
            throw new ValidationException(['user' => 'This is the only admin. Promote a new one before removing them.']);
        }

        if ($strict && $state['blocking_open'] > 0) {
            throw new ValidationException([
                'checklist' => 'Still open: ' . implode('; ', $state['blocking_labels'])
                             . '. Tick these off, or force the removal as admin.',
            ]);
        }

        if ($strict && !$state['balance_settled']) {
            throw new ValidationException([
                'balance' => sprintf(
                    '%s still has an outstanding balance of %s. Record a settlement first, or force the removal as admin.',
                    $state['user']['full_name'],
                    $state['balance_label']
                ),
            ]);
        }

        return Database::transaction(static function () use ($apartmentId, $userId, $actorId, $state) {
            Database::update('users', [
                'status'        => 'offboarded',
                'offboarded_on' => gmdate('Y-m-d'),
                'room_id'       => null,
                'duty_group_id' => null,
            ], 'id', $userId);

            // Close anything still assigned to them and hand it back to the pool.
            Database::query(
                "UPDATE chore_tasks SET assigned_user_id = NULL
                  WHERE assigned_user_id = :u1 AND status = 'pending' AND task_date >= CURDATE()",
                ['u1' => $userId]
            );
            Database::query(
                'DELETE FROM sessions WHERE user_id = :u1', ['u1' => $userId]
            );
            Database::query(
                "UPDATE magic_links SET used_at = UTC_TIMESTAMP() WHERE user_id = :u1 AND used_at IS NULL",
                ['u1' => $userId]
            );

            DutyScheduler::rebuildAll($apartmentId);

            ActivityLog::record('resident.offboarded', 'user', $userId,
                $state['user']['full_name'] . ' moved out', [
                    'forced' => !($state['balance_settled'] && $state['blocking_open'] === 0),
                    'balance_at_exit' => $state['balance_label'],
                ]);

            foreach (array_column(
                Database::all(
                    "SELECT id FROM users WHERE apartment_id = :a AND role = 'admin' AND id <> :u1",
                    ['a' => $apartmentId, 'u1' => $userId]
                ),
                'id'
            ) as $adminId) {
                Reminder::push($apartmentId, (int) $adminId, 'system',
                    $state['user']['full_name'] . ' has moved out',
                    'Room and duty group are free. Re-sync the rotation if anything looks off.',
                    'info', 'users', $userId);
            }

            return ['user_id' => $userId, 'status' => 'offboarded', 'released' => true];
        });
    }

    /** Restore a departed resident (undo a mistaken removal). */
    public static function reinstate(int $apartmentId, int $userId, ?int $roomId, ?int $groupId): array
    {
        return Database::transaction(static function () use ($apartmentId, $userId, $roomId, $groupId) {
            Database::update('users', [
                'status'        => 'active',
                'offboarded_on' => null,
                'room_id'       => $roomId,
                'duty_group_id' => $groupId,
            ], 'id', $userId);

            Database::query('DELETE FROM offboarding_tasks WHERE user_id = :u1', ['u1' => $userId]);
            DutyScheduler::rebuildAll($apartmentId);
            ActivityLog::record('resident.reinstated', 'user', $userId);

            return self::find($apartmentId, $userId);
        });
    }

    /* ================================================================== */
    /*  Helpers                                                            */
    /* ================================================================== */

    private static function offboardingProgress(int $userId): array
    {
        $row = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(is_done = 1) AS done,
                    SUM(is_blocking = 1 AND is_done = 0) AS blocking_open
                FROM offboarding_tasks WHERE user_id = :u1',
            ['u1' => $userId]
        ) ?? ['total' => 0, 'done' => 0, 'blocking_open' => 0];

        $total = (int) $row['total'];
        return [
            'total'    => $total,
            'done'     => (int) $row['done'],
            'blocking' => (int) $row['blocking_open'],
            'percent'  => $total === 0 ? 0 : (int) round((int) $row['done'] / $total * 100),
            'active'   => $total > 0 && (int) $row['blocking_open'] > 0,
        ];
    }

    private static function assertRoomCapacity(int $apartmentId, ?int $roomId, ?int $exceptUserId): void
    {
        if ($roomId === null) {
            return;
        }
        $room = Database::one(
            'SELECT id, code, capacity FROM rooms WHERE id = :id AND apartment_id = :a',
            ['id' => $roomId, 'a' => $apartmentId]
        );
        if ($room === null) {
            throw new ValidationException(['room_id' => 'That room is not in this apartment.']);
        }
        $occupied = (int) Database::value(
            "SELECT COUNT(*) FROM users WHERE room_id = :r AND status = 'active' AND id <> :x",
            ['r' => $roomId, 'x' => $exceptUserId ?? 0]
        );
        if ($occupied >= (int) $room['capacity']) {
            throw new ValidationException([
                'room_id' => sprintf('Room %s is full (%d/%d).', $room['code'], $occupied, $room['capacity']),
            ]);
        }
    }

    /**
     * A duty group must line up with the room it serves, otherwise
     * "Washroom A rotates among its 3 users" quietly breaks.
     */
    private static function assertGroupMembership(?int $roomId, ?int $groupId): void
    {
        if ($groupId === null || $roomId === null) {
            return;
        }
        $groupRoom = Database::value(
            'SELECT room_id FROM duty_groups WHERE id = :g',
            ['g' => $groupId]
        );
        if ($groupRoom !== null && (int) $groupRoom !== (int) $roomId) {
            throw new ValidationException([
                'duty_group_id' => 'That duty group belongs to a different room. Pick a matching group or none.',
            ]);
        }
    }

    private static function randomColor(): string
    {
        $palette = ['#6366f1', '#ec4899', '#14b8a6', '#f59e0b', '#8b5cf6', '#ef4444', '#0ea5e9', '#84cc16'];
        return $palette[random_int(0, count($palette) - 1)];
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out   = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $out === '' ? '?' : $out;
    }
}
