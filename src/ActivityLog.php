<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Activity log + in-app reminders
 * ---------------------------------------------------------------------------
 * Append-only audit trail (`activity_log`) and the notification feed that
 * drives the header badge (`reminders`).
 */

declare(strict_types=1);

final class ActivityLog
{
    /** Record an auditable event. Never throws — logging must not break a request. */
    public static function record(
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        ?string $summary = null,
        array $meta = [],
        ?int $userId = null
    ): void {
        try {
            Database::insert('activity_log', [
                'apartment_id' => Auth::check() ? Auth::apartmentId() : null,
                'user_id'      => $userId ?? (Auth::check() ? Auth::id() : null),
                'action'       => $action,
                'entity'       => $entity,
                'entity_id'    => $entityId,
                'summary'      => $summary === null ? null : mb_substr($summary, 0, 400),
                'meta'         => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('[FlatMate][log] ' . $e->getMessage());
        }
    }

    /** Paginated feed, newest first. */
    public static function feed(int $apartmentId, int $limit = 40, int $offset = 0): array
    {
        return array_map([self::class, 'decorate'], Database::all(
            'SELECT l.id, l.action, l.entity, l.entity_id, l.summary, l.meta, l.created_at,
                    u.full_name, u.avatar_color, u.participant_code
               FROM activity_log l
               LEFT JOIN users u ON u.id = l.user_id
              WHERE l.apartment_id = :a
              ORDER BY l.created_at DESC, l.id DESC
              LIMIT :lim OFFSET :off',
            ['a' => $apartmentId, 'lim' => max(1, min(200, $limit)), 'off' => max(0, $offset)]
        ));
    }

    private static function decorate(array $r): array
    {
        $r['icon']  = self::iconFor((string) $r['action']);
        $r['color'] = self::colorFor((string) $r['action']);
        $r['ago']   = self::ago((string) $r['created_at']);
        $r['meta']  = $r['meta'] ? json_decode((string) $r['meta'], true) : null;
        return $r;
    }

    private static function iconFor(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'expense.')   => 'bi-receipt',
            str_starts_with($action, 'settle.')    => 'bi-cash-coin',
            str_starts_with($action, 'chore.')     => 'bi-stars',
            str_starts_with($action, 'meal.')      => 'bi-egg-fried',
            str_starts_with($action, 'resident.')  => 'bi-person-plus',
            str_starts_with($action, 'announcement.') => 'bi-megaphone',
            str_starts_with($action, 'vote.')      => 'bi-hand-thumbs-up',
            default                               => 'bi-activity',
        };
    }

    private static function colorFor(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'expense.disputed') => 'warning',
            str_starts_with($action, 'resident.removed') => 'danger',
            str_starts_with($action, 'chore.completed')  => 'success',
            str_starts_with($action, 'settle.')         => 'primary',
            default => 'secondary',
        };
    }

    /** "3h ago" / "2d ago" */
    public static function ago(string $datetime): string
    {
        $ts    = strtotime($datetime . ' UTC');
        $delta = time() - $ts;
        if ($delta < 60)     return 'just now';
        if ($delta < 3600)   return intdiv($delta, 60) . 'm ago';
        if ($delta < 86400)  return intdiv($delta, 3600) . 'h ago';
        if ($delta < 604800) return intdiv($delta, 86400) . 'd ago';
        return gmdate('j M', $ts);
    }
}

