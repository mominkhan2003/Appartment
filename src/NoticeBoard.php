<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Notice Board
 * ---------------------------------------------------------------------------
 * Pinned announcements and maintenance alerts, with room / duty-group
 * targeting so a washroom cohort can be warned without spamming the whole flat.
 */

declare(strict_types=1);

final class NoticeBoard
{
    public const CATEGORIES = ['general', 'maintenance', 'billing', 'event', 'alert'];

    public const CATEGORY_META = [
        'general'     => ['icon' => 'bi-chat-left-text', 'class' => 'primary',   'label' => 'General'],
        'maintenance' => ['icon' => 'bi-tools',         'class' => 'warning',   'label' => 'Maintenance'],
        'billing'     => ['icon' => 'bi-receipt',       'class' => 'info',      'label' => 'Billing'],
        'event'       => ['icon' => 'bi-calendar-event','class' => 'success',   'label' => 'Event'],
        'alert'       => ['icon' => 'bi-exclamation-triangle', 'class' => 'danger', 'label' => 'Alert'],
    ];

    /* ------------------------------------------------------------------ */

    /**
     * Notices visible to one user, pinned first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function feed(int $apartmentId, int $userId, int $limit = 30): array
    {
        $me = Database::one(
            'SELECT room_id, duty_group_id, role FROM users WHERE id = :u1',
            ['u1' => $userId]
        ) ?? ['room_id' => null, 'duty_group_id' => null, 'role' => 'resident'];

        $rows = Database::all(
            'SELECT n.*,
                    u.full_name AS author_name, u.avatar_color AS author_avatar,
                    u.participant_code AS author_code,
                    r.code AS audience_room_code,
                    g.name AS audience_group_name,
                    ar.read_at
               FROM announcements n
               LEFT JOIN users u       ON u.id = n.user_id
               LEFT JOIN rooms r       ON r.id = n.audience_room_id
               LEFT JOIN duty_groups g ON g.id = n.audience_group_id
               LEFT JOIN announcement_reads ar
                      ON ar.announcement_id = n.id AND ar.user_id = :me
              WHERE n.apartment_id = :a
                AND (n.expires_at IS NULL OR n.expires_at > UTC_TIMESTAMP())
                AND (
                      n.audience = "everyone"
                   OR (n.audience = "admins"      AND :role = "admin")
                   OR (n.audience = "room"        AND n.audience_room_id  <=> :room)
                   OR (n.audience = "duty_group"  AND n.audience_group_id <=> :group)
                )
              ORDER BY (n.is_pinned AND (n.pinned_until IS NULL OR n.pinned_until >= CURDATE())) DESC,
                       n.created_at DESC
              LIMIT :lim',
            [
                'a'    => $apartmentId,
                'me'   => $userId,
                'lim'  => max(1, min(100, $limit)),
                'role' => $me['role'],
                'room' => $me['room_id'] === null ? null : (int) $me['room_id'],
                'group'=> $me['duty_group_id'] === null ? null : (int) $me['duty_group_id'],
            ]
        );

        return array_map([self::class, 'decorate'], $rows);
    }

    public static function find(int $apartmentId, int $id, int $viewerId): array
    {
        $row = Database::one(
            'SELECT n.*, u.full_name AS author_name, u.avatar_color AS author_avatar,
                    r.code AS audience_room_code, g.name AS audience_group_name
               FROM announcements n
               LEFT JOIN users u       ON u.id = n.user_id
               LEFT JOIN rooms r       ON r.id = n.audience_room_id
               LEFT JOIN duty_groups g ON g.id = n.audience_group_id
              WHERE n.apartment_id = :a AND n.id = :id',
            ['a' => $apartmentId, 'id' => $id]
        );
        if ($row === null) {
            return [];
        }
        $row = self::decorate($row);
        $row['is_read'] = Database::value(
            'SELECT 1 FROM announcement_reads WHERE announcement_id = :n AND user_id = :u1',
            ['n' => $id, 'u1' => $viewerId]
        ) !== null;
        return $row;
    }

    public static function create(int $apartmentId, int $actorId, array $input): array
    {
        $category = (string) ($input['category'] ?? 'general');
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ValidationException(['category' => 'Unknown notice category.']);
        }

        $audience = (string) ($input['audience'] ?? 'everyone');
        if (!in_array($audience, ['everyone', 'duty_group', 'room', 'admins'], true)) {
            $audience = 'everyone';
        }
        if ($audience === 'admins' && !Auth::isAdmin()) {
            $audience = 'everyone';
        }

        $id = Database::insert('announcements', [
            'apartment_id'      => $apartmentId,
            'user_id'           => $actorId,
            'title'             => trim((string) $input['title']),
            'body'              => $input['body'] ?? null,
            'category'          => $category,
            'audience'          => $audience,
            'audience_room_id'  => $audience === 'room' ? (int) $input['audience_room_id'] : null,
            'audience_group_id' => $audience === 'duty_group' ? (int) $input['audience_group_id'] : null,
            'is_pinned'         => !empty($input['is_pinned']) ? 1 : 0,
            'pinned_until'      => $input['pinned_until'] ?? null,
            'expires_at'        => $input['expires_at'] ?? null,
        ]);

        // Notify the audience, skipping the author.
        $recipients = self::recipients($apartmentId, $audience,
            $audience === 'room' ? (int) $input['audience_room_id'] : null,
            $audience === 'duty_group' ? (int) $input['audience_group_id'] : null
        );
        foreach ($recipients as $uid) {
            if ($uid === $actorId) {
                continue;
            }
            Reminder::push($apartmentId, $uid, 'announcement',
                self::CATEGORY_META[$category]['label'] . ': ' . $input['title'],
                mb_substr((string) ($input['body'] ?? ''), 0, 200)
                    ?: null,
                $category === 'alert' ? 'danger' : 'info',
                'announcements',
                $id
            );
        }

        ActivityLog::record('announcement.posted', 'announcement', $id,
            'Posted "' . $input['title'] . '"', ['category' => $category, 'audience' => $audience]);

        return self::find($apartmentId, $id, $actorId);
    }

    public static function pin(int $apartmentId, int $id, bool $pinned, ?string $until = null): array
    {
        Database::update('announcements', [
            'is_pinned'    => $pinned ? 1 : 0,
            'pinned_until' => $pinned ? $until : null,
        ], 'id', $id);
        ActivityLog::record($pinned ? 'announcement.pinned' : 'announcement.unpinned', 'announcement', $id);
        return self::find($apartmentId, $id, Auth::id() ?? 0);
    }

    public static function remove(int $apartmentId, int $id, int $actorId): void
    {
        $n = Database::one(
            'SELECT * FROM announcements WHERE id = :id AND apartment_id = :a',
            ['id' => $id, 'a' => $apartmentId]
        );
        if ($n === null) {
            throw new RuntimeException('That notice no longer exists.');
        }
        if (!Auth::isAdmin() && (int) $n['user_id'] !== $actorId) {
            throw new RuntimeException('Only the author or an admin can remove a notice.');
        }
        Database::delete('announcements', 'id', $id);
        ActivityLog::record('announcement.removed', 'announcement', $id, (string) $n['title']);
    }

    public static function markRead(int $apartmentId, int $id, int $userId): void
    {
        Database::query(
            'INSERT IGNORE INTO announcement_reads (announcement_id, user_id, read_at)
                  VALUES (:n, :u1, UTC_TIMESTAMP())',
            ['n' => $id, 'u1' => $userId]
        );
        Database::query('UPDATE announcements SET view_count = view_count + 1 WHERE id = :n', ['n' => $id]);
    }

    public static function markAllRead(int $apartmentId, int $userId): int
    {
        $ids = array_column(
            Database::all(
                'SELECT n.id FROM announcements n
                  WHERE n.apartment_id = :a
                     AND NOT EXISTS (SELECT 1 FROM announcement_reads ar
                                      WHERE ar.announcement_id = n.id AND ar.user_id = :u1)',
                ['a' => $apartmentId, 'u1' => $userId]
            ),
            'id'
        );
        foreach ($ids as $id) {
            self::markRead($apartmentId, (int) $id, $userId);
        }
        return count($ids);
    }

    public static function unreadCount(int $apartmentId, int $userId): int
    {
        $me = Database::one(
            'SELECT room_id, duty_group_id, role FROM users WHERE id = :u1',
            ['u1' => $userId]
        ) ?? ['room_id' => null, 'duty_group_id' => null, 'role' => 'resident'];

        // Counted in SQL rather than over feed(), which caps at 100 rows and
        // would under-report on a busy board.
        return (int) Database::value(
            'SELECT COUNT(*)
               FROM announcements n
              WHERE n.apartment_id = :a
                AND (n.expires_at IS NULL OR n.expires_at > UTC_TIMESTAMP())
                AND NOT EXISTS (SELECT 1 FROM announcement_reads ar
                                  WHERE ar.announcement_id = n.id AND ar.user_id = :u1)
                AND (n.audience = "everyone"
                      OR (n.audience = "admins"   AND :role = "admin")
                      OR (n.audience = "room"     AND n.audience_room_id = :room)
                      OR (n.audience = "duty_group" AND n.audience_group_id = :grp))',
            [
                'a'    => $apartmentId,
                'u1'   => $userId,
                'role' => $me['role'],
                'room' => $me['room_id'] === null ? 0 : (int) $me['room_id'],
                'grp'  => $me['duty_group_id'] === null ? 0 : (int) $me['duty_group_id'],
            ]
        );
    }

    /* ------------------------------------------------------------------ */

    private static function recipients(int $apartmentId, string $audience, ?int $roomId, ?int $groupId): array
    {
        $sql    = "SELECT id FROM users WHERE apartment_id = :a AND status = 'active'";
        $params = ['a' => $apartmentId];

        if ($audience === 'admins') {
            $sql .= " AND role = 'admin'";
        } elseif ($audience === 'room' && $roomId !== null) {
            $sql .= ' AND room_id = :r';
            $params['r'] = $roomId;
        } elseif ($audience === 'duty_group' && $groupId !== null) {
            $sql .= ' AND duty_group_id = :g';
            $params['g'] = $groupId;
        }
        return array_map('intval', array_column(Database::all($sql, $params), 'id'));
    }

    private static function decorate(array $n): array
    {
        $meta = self::CATEGORY_META[$n['category']] ?? self::CATEGORY_META['general'];

        $n['id']          = (int) $n['id'];
        $n['icon']        = $meta['icon'];
        $n['category_class'] = $meta['class'];
        $n['category_label']  = $meta['label'];
        $n['is_pinned_active'] = (int) $n['is_pinned'] === 1
            && ($n['pinned_until'] === null || $n['pinned_until'] >= gmdate('Y-m-d'));
        $n['is_read']     = !empty($n['read_at']);
        $n['ago']         = ActivityLog::ago((string) $n['created_at']);
        $n['excerpt']     = mb_strimwidth(strip_tags((string) $n['body']), 0, 140, "\u{2026}");
        $n['audience_label'] = match ($n['audience']) {
            'admins'     => 'Admins only',
            'room'       => 'Room ' . ($n['audience_room_code'] ?? '?'),
            'duty_group' => $n['audience_group_name'] ?? 'Duty group',
            default      => 'Everyone',
        };
        $n['is_mine']     = Auth::check() && (int) $n['user_id'] === Auth::id();
        return $n;
    }
}
