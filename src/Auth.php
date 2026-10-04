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

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
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
        return ['ok' => true, 'user' => $row];
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
