<?php
declare(strict_types=1);

namespace App\Core;

/** Session-based authentication + role-based access control. */
class Auth
{
    public const ADMIN     = 'administrator';
    public const STAFF     = 'staff';
    public const STUDENT   = 'student';
    public const FACULTY   = 'faculty';
    public const BORROWERS = [self::STUDENT, self::FACULTY];
    public const LENDERS   = [self::ADMIN, self::STAFF];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('UELSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        ini_set('session.gc_maxlifetime', (string) Env::get('SESSION_LIFETIME', 7200));
        session_start();
    }

    public static function login(int $userId): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$loaded = true;
    }

    /** Current user (fresh from DB so deactivated accounts lose access immediately), or null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        self::startSession();

        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $lifetime = (int) Env::get('SESSION_LIFETIME', 7200);
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $lifetime) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();

        $u = Database::one(
            "SELECT u.user_id, u.id_number, u.first_name, u.last_name, u.email, u.department,
                    u.contact_number, u.status, r.role_name AS role
             FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = 'active'",
            [$_SESSION['user_id']]
        );
        if (!$u) {
            self::logout();
            return null;
        }
        $u['user_id'] = (int) $u['user_id'];
        return self::$user = $u;
    }

    /** Require a logged-in user, optionally restricted to given roles. */
    public static function require(array $roles = []): array
    {
        $u = self::user();
        if (!$u) {
            throw new ApiException('Authentication required', 401);
        }
        if ($roles && !in_array($u['role'], $roles, true)) {
            throw new ApiException('You do not have permission to perform this action', 403);
        }
        return $u;
    }

    public static function isLender(array $user): bool
    {
        return in_array($user['role'], self::LENDERS, true);
    }
}
