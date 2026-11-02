<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{ApiException, Audit, Auth, Database, Env, Request, Response, Validator};

class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    /** One-time creation of the first administrator (only works while the users table is empty). */
    public static function setup(Request $req): void
    {
        if ((int) Database::scalar('SELECT COUNT(*) FROM users') > 0) {
            throw new ApiException('Setup has already been completed', 403);
        }
        $d = Validator::validate($req->input(), [
            'id_number'  => 'required|string|max:30',
            'first_name' => 'required|string|max:60',
            'last_name'  => 'required|string|max:60',
            'email'      => 'required|email|max:120',
            'password'   => 'required|string|min:8|max:72',
        ]);
        $d['role'] = Auth::ADMIN;
        $id = UserController::createUser($d);
        Audit::log($id, 'setup.admin_created', 'user', $id);
        Response::success(['user_id' => $id], 'Administrator account created. You can now log in.', 201);
    }

    public static function login(Request $req): void
    {
        $d = Validator::validate($req->input(), [
            'login'    => 'required|string|max:120',   // email or ID number
            'password' => 'required|string|max:255',
        ]);

        $u = Database::one(
            "SELECT u.user_id, u.password_hash, u.status, u.failed_attempts,
                    (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked
             FROM users u WHERE u.email = ? OR u.id_number = ? LIMIT 1",
            [$d['login'], $d['login']]
        );

        if (!$u) {
            throw new ApiException('Invalid login or password', 401);
        }
        if ((int) $u['is_locked'] === 1) {
            throw new ApiException('Too many failed attempts. Try again in ' . self::LOCK_MINUTES . ' minutes.', 429);
        }
        if (!password_verify($d['password'], $u['password_hash'])) {
            $attempts = (int) $u['failed_attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                Database::execute(
                    'UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) WHERE user_id = ?',
                    [$u['user_id']]
                );
            } else {
                Database::execute('UPDATE users SET failed_attempts = ? WHERE user_id = ?', [$attempts, $u['user_id']]);
            }
            Audit::log((int) $u['user_id'], 'auth.login_failed', 'user', (int) $u['user_id']);
            throw new ApiException('Invalid login or password', 401);
        }
        if ($u['status'] !== 'active') {
            throw new ApiException('This account is inactive. Please contact the administrator.', 403);
        }

        Database::execute('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE user_id = ?', [$u['user_id']]);
        Auth::login((int) $u['user_id']);
        Audit::log((int) $u['user_id'], 'auth.login', 'user', (int) $u['user_id']);
        Response::success(Auth::user(), 'Login successful');
    }

    public static function logout(Request $req, array $p, array $user): void
    {
        Audit::log($user['user_id'], 'auth.logout', 'user', $user['user_id']);
        Auth::logout();
        Response::success(null, 'Logged out');
    }

    public static function me(Request $req, array $p, array $user): void
    {
        Response::success($user);
    }

    /** Public self-registration for students and faculty. */
    public static function register(Request $req): void
    {
        if (!Env::bool('ALLOW_SELF_REGISTRATION', true)) {
            throw new ApiException('Self-registration is disabled. Please see the lending staff.', 403);
        }
        $d = Validator::validate($req->input(), [
            'id_number'      => 'required|string|max:30',
            'first_name'     => 'required|string|max:60',
            'last_name'      => 'required|string|max:60',
            'email'          => 'required|email|max:120',
            'password'       => 'required|string|min:8|max:72',
            'role'           => 'required|in:student,faculty',
            'department'     => 'nullable|string|max:100',
            'contact_number' => 'nullable|string|max:30',
        ]);
        $id = UserController::createUser($d);
        Audit::log($id, 'auth.register', 'user', $id);
        Response::success(['user_id' => $id], 'Registration successful. You can now log in.', 201);
    }

    public static function changePassword(Request $req, array $p, array $user): void
    {
        $d = Validator::validate($req->input(), [
            'current_password' => 'required|string|max:255',
            'new_password'     => 'required|string|min:8|max:72',
        ]);
        $hash = Database::scalar('SELECT password_hash FROM users WHERE user_id = ?', [$user['user_id']]);
        if (!password_verify($d['current_password'], (string) $hash)) {
            throw new ApiException('Current password is incorrect', 422, ['current_password' => 'Current password is incorrect.']);
        }
        Database::execute('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($d['new_password'], PASSWORD_DEFAULT), $user['user_id']]);
        Audit::log($user['user_id'], 'auth.password_changed', 'user', $user['user_id']);
        Response::success(null, 'Password updated');
    }
}
