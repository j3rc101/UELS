<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{ApiException, Audit, Auth, Database, Paginator, Request, Response, Validator};

class UserController
{
    private const COLS = 'u.user_id, u.id_number, u.first_name, u.last_name, u.email, u.department,
                         u.contact_number, u.status, r.role_name AS role, u.last_login_at, u.created_at';
    private const FROM = 'users u JOIN roles r ON r.role_id = u.role_id';

    /** Shared by AuthController::setup / register and UserController::store. */
    public static function createUser(array $d): int
    {
        $roleId = Database::scalar('SELECT role_id FROM roles WHERE role_name = ?', [$d['role']]);
        if (!$roleId) {
            throw new ApiException('Invalid role', 422, ['role' => 'Unknown role.']);
        }
        Database::execute(
            'INSERT INTO users (id_number, first_name, last_name, email, password_hash, role_id, department, contact_number)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $d['id_number'], $d['first_name'], $d['last_name'], $d['email'],
                password_hash($d['password'], PASSWORD_DEFAULT),
                $roleId, $d['department'] ?? null, $d['contact_number'] ?? null,
            ]
        );
        return Database::lastId();
    }

    public static function index(Request $req, array $p, array $actor): void
    {
        $w = []; $a = [];
        if (($q = trim((string) $req->get('q', ''))) !== '') {
            $w[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                     OR u.email LIKE ? OR u.id_number LIKE ?)";
            array_push($a, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($role = $req->get('role')) { $w[] = 'r.role_name = ?'; $a[] = $role; }
        if ($st = $req->get('status')) { $w[] = 'u.status = ?';    $a[] = $st; }
        if ($actor['role'] === Auth::STAFF) {            // staff only manage borrowers
            $w[] = "r.role_name IN ('student','faculty')";
        }

        $order = Paginator::order($req, [
            'name' => 'u.last_name', 'id_number' => 'u.id_number', 'role' => 'r.role_name', 'created_at' => 'u.created_at',
        ], 'u.last_name ASC, u.first_name ASC');

        [$items, $meta] = Paginator::run($req, self::COLS, self::FROM, Paginator::where($w), $a, $order);
        Response::success($items, 'OK', 200, $meta);
    }

    public static function show(Request $req, array $p, array $actor): void
    {
        $u = self::findOrFail((int) $p['id']);
        self::assertCanManage($actor, $u['role']);
        Response::success($u);
    }

    public static function store(Request $req, array $p, array $actor): void
    {
        $d = Validator::validate($req->input(), [
            'id_number'      => 'required|string|max:30',
            'first_name'     => 'required|string|max:60',
            'last_name'      => 'required|string|max:60',
            'email'          => 'required|email|max:120',
            'password'       => 'required|string|min:8|max:72',
            'role'           => 'required|in:administrator,staff,student,faculty',
            'department'     => 'nullable|string|max:100',
            'contact_number' => 'nullable|string|max:30',
        ]);
        self::assertCanManage($actor, $d['role']);

        $id = self::createUser($d);
        Audit::log($actor['user_id'], 'user.create', 'user', $id, $d['role']);
        Response::success(self::findOrFail($id), 'User created', 201);
    }

    public static function update(Request $req, array $p, array $actor): void
    {
        $id = (int) $p['id'];
        $target = self::findOrFail($id);
        self::assertCanManage($actor, $target['role']);
        $isAdmin = $actor['role'] === Auth::ADMIN;

        $d = Validator::validate($req->input(), [
            'id_number'      => 'required|string|max:30',
            'first_name'     => 'required|string|max:60',
            'last_name'      => 'required|string|max:60',
            'email'          => 'required|email|max:120',
            'department'     => 'nullable|string|max:100',
            'contact_number' => 'nullable|string|max:30',
            'role'           => 'required|in:administrator,staff,student,faculty',
            'status'         => 'required|in:active,inactive',
            'password'       => 'required|string|min:8|max:72',
        ], true);

        if (isset($d['role'])) {
            self::assertCanManage($actor, $d['role']);
        }
        if (isset($d['password']) && !$isAdmin) {
            throw new ApiException('Only administrators can reset passwords', 403);
        }
        if ($id === $actor['user_id']
            && ((isset($d['status']) && $d['status'] !== 'active') || (isset($d['role']) && $d['role'] !== $target['role']))) {
            throw new ApiException('You cannot deactivate or change the role of your own account', 400);
        }
        if (!$d) {
            throw new ApiException('No fields to update', 422);
        }

        $sets = []; $vals = [];
        foreach ($d as $field => $value) {
            if ($field === 'role') {
                $sets[] = 'role_id = ?';
                $vals[] = Database::scalar('SELECT role_id FROM roles WHERE role_name = ?', [$value]);
            } elseif ($field === 'password') {
                $sets[] = 'password_hash = ?';
                $vals[] = password_hash($value, PASSWORD_DEFAULT);
            } else {
                $sets[] = "{$field} = ?";
                $vals[] = $value;
            }
        }
        $vals[] = $id;
        Database::execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $vals);

        Audit::log($actor['user_id'], 'user.update', 'user', $id, implode(',', array_keys($d)));
        Response::success(self::findOrFail($id), 'User updated');
    }

    /** "Delete" = deactivate (keeps borrowing history intact). */
    public static function destroy(Request $req, array $p, array $actor): void
    {
        $id = (int) $p['id'];
        self::findOrFail($id);
        if ($id === $actor['user_id']) {
            throw new ApiException('You cannot deactivate your own account', 400);
        }
        $open = (int) Database::scalar("SELECT COUNT(*) FROM loans WHERE borrower_id = ? AND status = 'borrowed'", [$id]);
        if ($open > 0) {
            throw new ApiException('This user still has unreturned equipment', 409);
        }
        Database::execute("UPDATE users SET status = 'inactive' WHERE user_id = ?", [$id]);
        Audit::log($actor['user_id'], 'user.deactivate', 'user', $id);
        Response::success(null, 'User deactivated');
    }

    private static function findOrFail(int $id): array
    {
        $u = Database::one('SELECT ' . self::COLS . ' FROM ' . self::FROM . ' WHERE u.user_id = ?', [$id]);
        if (!$u) {
            throw new ApiException('User not found', 404);
        }
        return $u;
    }

    /** Staff may only manage student/faculty accounts; administrators may manage everyone. */
    private static function assertCanManage(array $actor, string $targetRole): void
    {
        if ($actor['role'] === Auth::STAFF && !in_array($targetRole, Auth::BORROWERS, true)) {
            throw new ApiException('You do not have permission to manage this type of account', 403);
        }
    }
}
