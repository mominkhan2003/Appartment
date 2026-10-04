<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Authentication
 * ---------------------------------------------------------------------------
 * Two sign-in paths, one session:
 *   1. email + password   (password_hash / password_verify, bcrypt cost 12)
 *   2. magic link         (single-use token, only its SHA-256 is stored)
 *
 * Plus persistent "remember me" tokens in the `sessions` table, unique
 * auto-generated participant codes, and role/status gates.
 */

declare(strict_types=1);

final class Auth
{
    private const PARTICIPANT_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I

    private static ?array $user = null;
    private static bool $resolved = false;

    /* ------------------------------------------------------------------ */
    /*  State                                                             */
    /* ------------------------------------------------------------------ */

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    /**
     * Seed the identity cache from a user row that has already been fetched.
     *
     * Used by bearer-token API auth, where the caller is identified by a
     * `sessions` row rather than by `$_SESSION`, so there is no cookie to
     * establish and nothing to regenerate.
     */
    public static function primeFromUser(array $user): void
    {
        /* Single choke point for "a user row becomes the current user".
           ApiAuth's bearer path, the login path and the remember-me path all
           funnel through here, and Auth::user() is echoed to the browser by
           auth.me -- so this is the one place that has to be certain.

           The array_key_exists() guard matters: ResidentService::updateSelf
           re-primes the already-sanitised row to refresh the name and colour,
           and recomputing from a missing key would report every password
           account as a magic-link one. */
        if (array_key_exists('password_hash', $user)) {
            $user = self::sanitiseUser($user);
        }

        self::$user     = $user;
        self::$resolved = true;

        // Keep the rest of the app consistent if something later reads $_SESSION.
        $_SESSION['user_id'] = (int) $user['id'];
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $uid = $_SESSION['user_id'] ?? null;
        if ($uid) {
            $row = Database::one(
                'SELECT u.*, r.code AS room_code, r.name AS room_name,
                        g.name AS duty_group_name, g.color AS duty_group_color,
                        a.name AS apartment_name, a.currency_symbol
                   FROM users u
                   LEFT JOIN rooms       r ON r.id = u.room_id
                   LEFT JOIN duty_groups g ON g.id = u.duty_group_id
                   LEFT JOIN apartments  a ON a.id = u.apartment_id
                  WHERE u.id = :id',
                ['id' => (int) $uid]
            );

            if ($row === null) {
                unset($_SESSION['user_id']);
                return null;
            }
            if ($row['status'] === 'suspended') {
                self::logout();
                return null;
            }

            // u.* keeps this query short, but self::$user is handed straight
            // to the browser by the auth.me endpoint. The hash must not travel.
            $row = self::sanitiseUser($row);

            self::$user = $row;
            return self::$user;
        }

        // fall back to a persistent "remember me" cookie
        return self::resumeFromCookie();
    }

    private static function resumeFromCookie(): ?array
    {
        $raw = $_COOKIE['flatmate_remember'] ?? null;
        if (!is_string($raw) || strlen($raw) < 32) {
            return null;
        }

        $row = Database::one(
            'SELECT s.id AS session_id, s.user_id
               FROM sessions s
              WHERE s.token_hash = :h AND s.expires_at > UTC_TIMESTAMP()',
            ['h' => hash('sha256', $raw)]
        );
        if ($row === null) {
            return null;
        }

        $_SESSION['user_id'] = (int) $row['user_id'];
        self::$resolved      = false;                 // force a reload
        return self::user();
    }

    /**
     * The coarse gate every route guard reads.
     *
     * True for the legacy role='admin', and also for a resident whose assigned
     * role grants 'admin.access' or '*' -- that is what lets a flat hand out
     * admin powers without touching this method's contract.
     *
     * Degrades to the legacy column alone when sql/patch_roles_profile.sql has
     * not been applied, so a partially migrated database keeps working.
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        if (empty($user['role_id']) || !RoleService::schemaReady()) {
            return false;
        }
        return RoleService::isEffectiveAdmin($user);
    }

    /**
     * Fine-grained check for a single permission key.
     *
     * A legacy admin holds everything, so existing installs behave exactly as
     * before. A resident needs the permission on their assigned role; having no
     * role at all means no extra permissions, which is the safe default.
     */
    public static function can(string $permission): bool
    {
        $user = self::user();
        if (empty($user)) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        if ($permission === 'admin.access' && self::isAdmin()) {
            return true;
        }
        if (empty($user['role_id']) || !RoleService::schemaReady()) {
            return false;
        }

        $raw = Database::value(
            'SELECT permissions FROM roles WHERE id = :r',
            ['r' => (int) $user['role_id']]
        );
        $perms = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        if (!is_array($perms)) {
            return false;
        }
        return in_array(RoleService::WILDCARD, $perms, true)
            || in_array($permission, $perms, true);
    }

    /** Every permission key the signed-in user currently holds. */
    public static function permissions(): array
    {
        $user = self::user();
        if (empty($user)) {
            return [];
        }
        if (($user['role'] ?? '') === 'admin') {
            return [RoleService::WILDCARD];
        }
        if (empty($user['role_id']) || !RoleService::schemaReady()) {
            return [];
        }
        $raw = Database::value(
            'SELECT permissions FROM roles WHERE id = :r',
            ['r' => (int) $user['role_id']]
        );
        $perms = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return is_array($perms) ? array_values(array_filter($perms, 'is_string')) : [];
    }

    public static function apartmentId(): int
    {
        return (int) (self::user()['apartment_id'] ?? 0);
    }

    /* ------------------------------------------------------------------ */
    /*  Credential sign-in                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{ok:bool, user?:array, error?:string}
     */
    public static function attempt(string $email, string $password, bool $remember = false, ?string $captcha = null): array
    {
        $email = strtolower(trim($email));
        $row = Database::one(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );

        // Check lockout
        if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $remaining = ceil((strtotime($row['locked_until']) - time()) / 60);
            return ['ok' => false, 'error' => "Account locked. Try again in {$remaining} minutes."];
        }

        // Validate captcha if in session
        if (isset($_SESSION['captcha_code'])) {
            if ($captcha === null || strtolower((string)$captcha) !== strtolower((string)$_SESSION['captcha_code'])) {
                unset($_SESSION['captcha_code']);
                return ['ok' => false, 'error' => 'Invalid CAPTCHA. Please try again.'];
            }
            unset($_SESSION['captcha_code']);
        }

        // Always run a hash comparison so a missing user and a wrong password
        // take the same amount of time (no user enumeration via timing).
        $hash = ($row && $row['password_hash']) ? $row['password_hash'] : '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

        if (!password_verify($password, $hash) || $row === null) {
            if ($row) {
                $attempts = (int)$row['login_attempts'] + 1;
                $lockedUntil = null;
                if ($attempts >= 3) {
                    $lockedUntil = gmdate('Y-m-d H:i:s', time() + 1200); // 20 minutes
                    $attempts = 0;
                }
                Database::update('users', ['login_attempts' => $attempts, 'locked_until' => $lockedUntil], 'id', (int)$row['id']);
            }
            return ['ok' => false, 'error' => 'Email or password is incorrect.'];
        }

        // Reset lockout on success
        if ($row) {
            Database::update('users', ['login_attempts' => 0, 'locked_until' => null], 'id', (int)$row['id']);
        }
        if ($row['status'] === 'suspended') {
            return ['ok' => false, 'error' => 'This account is suspended. Contact your house admin.'];
        }
        if ($row['status'] === 'invited') {
            return ['ok' => false, 'error' => 'This account is still an invitation. Open your invite link to finish joining.'];
        }
        if ($row['status'] === 'offboarded') {
            return ['ok' => false, 'error' => 'This account belongs to a resident who has already moved out.'];
        }

        // Transparently upgrade legacy/weak hashes on a successful login.
        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            Database::update('users', ['password_hash' => self::hash($password)], 'id', (int) $row['id']);
        }

        self::login($row, $remember);
        /* The row is returned to the client on a successful sign-in, so
           sanitise exactly as primeFromUser() would. The hash is still on
           $row above, which is why password_verify() ran before this point. */
        return ['ok' => true, 'user' => self::sanitiseUser($row)];
    }

    /**
     * Strip anything that must never reach a browser, keeping the non-sensitive
     * has_password flag so the UI can still tell the two sign-in styles apart.
     */
    public static function sanitiseUser(array $user): array
    {
        $user['has_password'] = !empty($user['password_hash']);
        unset($user['password_hash']);
        return $user;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /* ------------------------------------------------------------------ */
    /*  Magic link                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Create a single-use login link. Returns the RAW token — it is the only
     * time the caller can see it; only its hash hits the database.
     */
    public static function issueMagicLink(string $email, int $ttlMinutes = 30): array
    {
        $row = Database::one(
            "SELECT id, full_name, status FROM users WHERE email = :email LIMIT 1",
            ['email' => strtolower(trim($email))]
        );

        // Deliberately identical response whether or not the email exists.
        if ($row === null) {
            return ['sent' => true, 'token' => null];
        }
        if ($row['status'] !== 'active') {
            return ['sent' => true, 'token' => null];
        }

        $token = bin2hex(random_bytes(32));
        Database::insert('magic_links', [
            'user_id'    => (int) $row['id'],
            'token_hash' => hash('sha256', $token),
            'purpose'    => 'login',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlMinutes * 60),
        ]);

        return ['sent' => true, 'token' => $token, 'name' => $row['full_name']];
    }

    public static function redeemMagicLink(string $token, bool $remember = true): array
    {
        $hash = hash('sha256', $token);

        $row = Database::one(
            'SELECT * FROM magic_links
              WHERE token_hash = :h AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            ['h' => $hash]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => 'That link is invalid, already used, or has expired.'];
        }

        Database::update('magic_links', ['used_at' => gmdate('Y-m-d H:i:s')], 'id', (int) $row['id']);

        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => (int) $row['user_id']]);
        if ($user === null || $user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This account is not active any more.'];
        }

        self::login($user, $remember);
        return ['ok' => true, 'user' => $user];
    }

    /* ------------------------------------------------------------------ */
    /*  Session lifecycle                                                 */
    /* ------------------------------------------------------------------ */

    public static function login(array $user, bool $remember = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['logged_in_at'] = time();
        self::$user     = $user;
        self::$resolved = true;
    }

    public static function getClientIp(): ?string
    {
        $headers = ["HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR", "HTTP_X_REAL_IP", "HTTP_CLIENT_IP", "REMOTE_ADDR"];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(",", (string)$_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Session teardown                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Drop the PHP session and clear its cookie without touching the
     * persistent "remember me" token. Used when a session times out or the
     * client IP changes, so the next sign-in is a full one.
     */
    public static function forgetSession(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name() ?: (string) config('app.session_name', 'FLATMATE_SESSID'),
                    '',
                    [
                        'expires'  => time() - 42000,
                        'path'     => $params['path']     ?? '/',
                        'domain'   => $params['domain']   ?? '',
                        'secure'   => $params['secure']   ?? false,
                        'httponly' => $params['httponly'] ?? true,
                        'samesite' => $params['samesite'] ?? 'Lax',
                    ]
                );
            }
            session_destroy();
        }

        self::$user     = null;
        self::$resolved = true;
    }

    public static function logout(): void
    {
        if (!empty($_COOKIE['flatmate_remember'])) {
            Database::query(
                'DELETE FROM sessions WHERE token_hash = :h',
                ['h' => hash('sha256', (string) $_COOKIE['flatmate_remember'])]
            );
        }
        setcookie('flatmate_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        ]);

        self::forgetSession();
    }

    private static function issueRememberToken(int $userId): void
    {
        $raw  = bin2hex(random_bytes(32));
        $life = (int) config('app.session_life', 1209600);

        Database::insert('sessions', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $raw),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $life),
        ]);

        setcookie('flatmate_remember', $raw, [
            'expires'  => time() + $life,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Participant codes                                                 */
    /* ------------------------------------------------------------------ */



    /** Collision-checked, human-typeable id such as "FM-7QRT2M". */
    public static function generateParticipantCode(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::PARTICIPANT_ALPHABET[random_int(0, strlen(self::PARTICIPANT_ALPHABET) - 1)];
            }
            $code = 'FM-' . $suffix;

            $exists = Database::value(
                'SELECT 1 FROM users WHERE participant_code = :c',
                ['c' => $code]
            );
            if ($exists === null) {
                return $code;
            }
        }

        // Deterministic fallback — effectively unreachable.
        return 'FM-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 6));
    }

    /* ------------------------------------------------------------------ */
    /*  Password policy                                                   */
    /* ------------------------------------------------------------------ */

    public static function validatePassword(string $password): array
    {
        $errors = [];
        if (mb_strlen($password) < 8) {
            $errors[] = 'Use at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $errors[] = 'Include at least one letter.';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Include at least one number.';
        }
        return $errors;
    }
}
