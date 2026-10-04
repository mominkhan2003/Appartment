<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Application bootstrap
 * ---------------------------------------------------------------------------
 * One require from any entry point:
 *     require_once __DIR__ . '/src/Bootstrap.php';
 *
 * Gives you: the PSR-0 style autoloader, config(), the shared PDO handle,
 * session start, error handling, CSRF helpers and small view helpers.
 */

declare(strict_types=1);

define('FLATMATE_ROOT', dirname(__DIR__));
define('FLATMATE_VERSION', '1.0.0');

/* -------------------------------------------------------------------------- */
/*  Autoloader — src/BalanceEngine.php  ->  class BalanceEngine                 */
/* -------------------------------------------------------------------------- */
spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
        return;
    }
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/* -------------------------------------------------------------------------- */
/*  Configuration                                                              */
/* -------------------------------------------------------------------------- */
if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require FLATMATE_ROOT . '/config/config.php';
        }
        if ($key === null) {
            return $cfg;
        }
        // dotted lookup: config('app.currency')
        $value = $cfg;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

/* -------------------------------------------------------------------------- */
/*  Database handle                                                            */
/* -------------------------------------------------------------------------- */
if (!function_exists('db')) {
    function db(): PDO
    {
        return Database::conn();
    }
}

/* -------------------------------------------------------------------------- */
/*  Error handling                                                             */
/* -------------------------------------------------------------------------- */
if (!function_exists('app_error_handler')) {
    function app_error_handler(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
}

error_reporting(E_ALL);
ini_set('display_errors', config('app.env', 'local') === 'production' ? '0' : '1');
ini_set('log_errors', '1');
set_error_handler('app_error_handler');

/* -------------------------------------------------------------------------- */
/*  Timezone + session                                                         */
/* -------------------------------------------------------------------------- */
date_default_timezone_set((string) config('app.timezone', 'UTC'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) config('app.session_name', 'FLATMATE_SESSID'));
    session_set_cookie_params([
        'lifetime' => (int) config('app.session_life', 1209600),
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_start();
    $_SESSION['last_activity'] = time();
}

/* -------------------------------------------------------------------------- */
/*  Small view helpers                                                         */
/* -------------------------------------------------------------------------- */
if (!function_exists('e')) {
    /** HTML-escape. Use for every dynamic value printed into HTML. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('detect_base_url')) {
    /**
     * Work out the URL prefix of the folder holding index.php.
     *
     * Config can override this (set app.base_url to a non-empty string), but the
     * default is derived from SCRIPT_NAME so the app runs correctly whether it
     * is at /flatmate/, /Appartment/, or the vhost root, with no editing.
     */
    function detect_base_url(): string
    {
        $configured = (string) config('app.base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // SCRIPT_NAME is e.g. /Appartment/login.php -> /Appartment
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');

        // The API lives in api/, so strip it to reach the app root.
        if (str_ends_with($dir, '/api')) {
            $dir = substr($dir, 0, -4);
        }

        // dirname('/index.php') is '/', which already means "web root".
        return $dir === '/' || $dir === '.' ? '' : $dir;
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        $base = detect_base_url();
        if ($path === '') {
            return $base === '' ? '/' : $base;
        }
        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }
}

if (!function_exists('money')) {
    /** Format a decimal/cents value as "\u{20AC}1,234.50". */
    function money(float|int|string $amount, bool $withSymbol = true): string
    {
        $n = number_format((float) $amount, 2);
        return $withSymbol
            ? config('app.currency', '\u{20AC}') . $n
            : $n;
    }
}

if (!function_exists('initials')) {
    /** "Aisha Rahman" -> "AR". Used for avatars. */
    function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $out   = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $out === '' ? '?' : $out;
    }
}

if (!function_exists('ago')) {
    /** Relative time for activity feeds: "3h ago". */
    function ago(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        // Stored as UTC.
        $ts   = strtotime($datetime . ' UTC');
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        foreach ([[31536000, 'y'], [2592000, 'mo'], [604800, 'w'], [86400, 'd'], [3600, 'h'], [60, 'm']] as [$secs, $unit]) {
            if ($diff >= $secs) {
                return intdiv($diff, $secs) . $unit . ' ago';
            }
        }
        return 'just now';
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $rel  = '/assets/' . ltrim($path, '/');
        $file = FLATMATE_ROOT . '/' . ltrim($rel, '/');
        $v    = is_file($file) ? (string) filemtime($file) : FLATMATE_VERSION;
        return base_url($rel) . '?v=' . $v;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        $target = str_starts_with($path, 'http') ? $path : base_url($path);
        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('safe_page')) {
    /**
     * Normalise a `?next=` return path into a real page in this app.
     *
     * The value reaches us on the URL and round-trips through a hidden field, so
     * it can be "chores", "chores.php", "./chores.php" or an absolute URL. Only
     * the file name is kept, exactly one ".php" is applied, and anything outside
     * the allow-list falls back to the dashboard. That prevents both the
     * "index.php.php" double-extension and any open-redirect/path-traversal.
     */
    function safe_page(?string $requested, string $fallback = 'index.php'): string
    {
        static $allowed = [
            'index', 'chores', 'expenses', 'meals',
            'notices', 'residents', 'join', 'login',
        ];

        $name = basename(str_replace('\\', '/', (string) $requested));
        $name = strtolower(trim(preg_replace('/\.php$/i', '', $name) ?? ''));

        return in_array($name, $allowed, true) ? $name . '.php' : $fallback;
    }
}

/* -------------------------------------------------------------------------- */
/*  Flash messages                                                             */
/* -------------------------------------------------------------------------- */
if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('take_flashes')) {
    function take_flashes(): array
    {
        $out = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $out;
    }
}

/* -------------------------------------------------------------------------- */
/*  CSRF                                                                      */
/* -------------------------------------------------------------------------- */
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_valid')) {
    function csrf_valid(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }
}

if (!function_exists('require_csrf')) {
    /** Abort the request unless a valid CSRF token was supplied. */
    function require_csrf(): void
    {
        $token = $_POST['_csrf']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? (json_decode(file_get_contents('php://input') ?: '{}', true)['_csrf'] ?? null);

        if (!csrf_valid(is_string($token) ? $token : null)) {
            if (str_starts_with((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                Response::error('CSRF token missing or expired. Reload the page.', 419);
            }
            http_response_code(419);
            exit('CSRF token missing or expired. Reload the page and try again.');
        }
    }
}

/* -------------------------------------------------------------------------- */
/*  Session lifetime                                                          */
/* -------------------------------------------------------------------------- */
if (!function_exists('enforce_session_lifetime')) {
    /**
     * End a session that has gone stale or moved to a different network.
     *
     * Runs on every authenticated request. Two things end the session:
     *   * idle for longer than app.idle_timeout seconds, or
     *   * the client IP changed, which is what a reconnect / network switch
     *     looks like from the server's side.
     *
     * $json is true for XHR callers, which get a 401 body instead of a
     * redirect so the client can send the browser to the login page.
     *
     * @return bool true when the caller may keep using the session
     */
    function enforce_session_lifetime(bool $json = false): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return true;
        }

        $now          = time();
        $idleTimeout  = max(60, (int) config('app.idle_timeout', 1800));
        $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
        $idleFor      = $now - $lastActivity;

        $clientIp  = Auth::getClientIp();
        $ipChanged = $clientIp !== null
            && isset($_SESSION['client_ip'])
            && $_SESSION['client_ip'] !== $clientIp;

        if ($idleFor <= $idleTimeout && !$ipChanged) {
            $_SESSION['last_activity'] = $now;
            return true;
        }

        $reason = $idleFor > $idleTimeout ? 'timeout' : 'session';
        Auth::forgetSession();

        session_unset();
        session_destroy();

        if ($json) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'    => false,
                'error' => [
                    'code'    => 'session_expired',
                    'message' => $reason === 'timeout'
                        ? 'You were signed out after a period of inactivity.'
                        : 'You were signed out because your connection changed.',
                    'reason'  => $reason,
                ],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (!headers_sent()) {
            redirect('login.php?reason=' . $reason);
        }
        exit;
    }
}

/* -------------------------------------------------------------------------- */
/*  Guard helpers for pages                                                    */
/* -------------------------------------------------------------------------- */
if (!function_exists('require_login')) {
    function require_login(): void
    {
        enforce_session_lifetime();
        if (!Auth::check()) {
            flash('warning', 'Please sign in to continue.');
            redirect('login.php');
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        require_login();
        if (!Auth::isAdmin()) {
            flash('danger', 'That area is restricted to the apartment admin.');
            redirect('index.php');
        }
    }
}
