<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{ApiException, Audit, Auth, Database, Paginator, Request, Response, Validator};

class EquipmentController
{
    public const CONDITIONS = 'excellent,good,fair,poor,damaged';

    public static function index(Request $req, array $p, array $user): void
    {
        $w = []; $a = [];
        if (($q = trim((string) $req->get('q', ''))) !== '') {
            $w[] = '(name LIKE ? OR asset_tag LIKE ? OR description LIKE ? OR category_name LIKE ?)';
            array_push($a, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($c = $req->get('category_id'))      { $w[] = 'category_id = ?';      $a[] = (int) $c; }
        if ($s = $req->get('status'))           { $w[] = 'status = ?';           $a[] = $s; }
        if ($cn = $req->get('condition'))       { $w[] = 'condition_status = ?'; $a[] = $cn; }
        if ($req->get('available_only') === '1') { $w[] = 'available_quantity > 0'; }

        // Borrowers only see lendable items; staff/admin can include inactive ones
        if (!Auth::isLender($user) || $req->get('include_inactive') !== '1') {
            $w[] = 'is_active = 1';
        }

        $order = Paginator::order($req, [
            'name' => 'name', 'category' => 'category_name', 'available' => 'available_quantity',
            'status' => 'status', 'created_at' => 'created_at',
        ], 'name ASC');

        [$items, $meta] = Paginator::run($req, '*', 'v_equipment_availability', Paginator::where($w), $a, $order);
        Response::success($items, 'OK', 200, $meta);
    }

    public static function show(Request $req, array $p, array $user): void
    {
        $e = self::find((int) $p['id']);
        if (!Auth::isLender($user) && (int) $e['is_active'] === 0) {
            throw new ApiException('Equipment not found', 404);
        }
        Response::success($e);
    }

    public static function store(Request $req, array $p, array $user): void
    {
        $d = Validator::validate($req->input(), [
            'asset_tag'        => 'required|string|max:40',
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string|max:2000',
            'category_id'      => 'required|int|min:1',
            'total_quantity'   => 'required|int|min:1|max:100000',
            'condition_status' => 'nullable|in:' . self::CONDITIONS,
            'location'         => 'nullable|string|max:100',
        ]);

        Database::execute(
            'INSERT INTO equipment (asset_tag, name, description, category_id, total_quantity, available_quantity, condition_status, location)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $d['asset_tag'], $d['name'], $d['description'] ?? null, $d['category_id'],
                $d['total_quantity'], $d['total_quantity'], $d['condition_status'] ?? 'good', $d['location'] ?? null,
            ]
        );
        $id = Database::lastId();
        Audit::log($user['user_id'], 'equipment.create', 'equipment', $id, $d['asset_tag']);
        Response::success(self::find($id), 'Equipment added', 201);
    }

    public static function update(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $d = Validator::validate($req->input(), [
            'asset_tag'        => 'required|string|max:40',
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string|max:2000',
            'category_id'      => 'required|int|min:1',
            'total_quantity'   => 'required|int|min:0|max:100000',
            'damaged_quantity' => 'required|int|min:0|max:100000',
            'condition_status' => 'required|in:' . self::CONDITIONS,
            'location'         => 'nullable|string|max:100',
            'is_active'        => 'required|bool',
        ], true);
        if (!$d) {
            throw new ApiException('No fields to update', 422);
        }

        Database::transaction(function () use ($id, $d, $user) {
            $row = Database::one('SELECT * FROM equipment WHERE equipment_id = ? FOR UPDATE', [$id]);
            if (!$row) {
                throw new ApiException('Equipment not found', 404);
            }

            // Re-balance stock: available = total - damaged - currently on loan
            $onLoan    = (int) $row['total_quantity'] - (int) $row['available_quantity'] - (int) $row['damaged_quantity'];
            $total     = $d['total_quantity']   ?? (int) $row['total_quantity'];
            $damaged   = $d['damaged_quantity'] ?? (int) $row['damaged_quantity'];
            $available = $total - $damaged - $onLoan;
            if ($available < 0) {
                throw new ApiException("Quantities conflict with {$onLoan} unit(s) currently on loan", 422);
            }
            $d['available_quantity'] = $available;

            $sets = []; $vals = [];
            foreach ($d as $k => $v) { $sets[] = "{$k} = ?"; $vals[] = $v; }
            $vals[] = $id;
            Database::execute('UPDATE equipment SET ' . implode(', ', $sets) . ' WHERE equipment_id = ?', $vals);

            if (isset($d['condition_status']) && $d['condition_status'] !== $row['condition_status']) {
                Database::execute(
                    "INSERT INTO equipment_condition_logs (equipment_id, event_type, condition_status, notes, recorded_by)
                     VALUES (?, 'inspection', ?, 'Updated from equipment record', ?)",
                    [$id, $d['condition_status'], $user['user_id']]
                );
            }
        });

        Audit::log($user['user_id'], 'equipment.update', 'equipment', $id, implode(',', array_keys($d)));
        Response::success(self::find($id), 'Equipment updated');
    }

    /** Hard-delete when never lent; otherwise deactivate so history stays intact. */
    public static function destroy(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        self::find($id);
        $hasLoans = (int) Database::scalar('SELECT COUNT(*) FROM loans WHERE equipment_id = ?', [$id]) > 0;

        if ($hasLoans) {
            $open = (int) Database::scalar("SELECT COUNT(*) FROM loans WHERE equipment_id = ? AND status IN ('borrowed','pending')", [$id]);
            if ($open > 0) {
                throw new ApiException('Equipment has pending or unreturned loans', 409);
            }
            Database::execute('UPDATE equipment SET is_active = 0 WHERE equipment_id = ?', [$id]);
            Audit::log($user['user_id'], 'equipment.deactivate', 'equipment', $id);
            Response::success(null, 'Equipment has borrowing history, so it was deactivated instead of deleted');
        }
        Database::execute('DELETE FROM equipment WHERE equipment_id = ?', [$id]);
        Audit::log($user['user_id'], 'equipment.delete', 'equipment', $id);
        Response::success(null, 'Equipment deleted');
    }

    public static function conditionLogs(Request $req, array $p): void
    {
        $id = (int) $p['id'];
        self::find($id);
        Response::success(Database::all(
            "SELECT l.log_id, l.loan_id, l.event_type, l.condition_status, l.notes, l.recorded_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS recorded_by
             FROM equipment_condition_logs l LEFT JOIN users u ON u.user_id = l.recorded_by
             WHERE l.equipment_id = ? ORDER BY l.recorded_at DESC, l.log_id DESC LIMIT 200",
            [$id]
        ));
    }

    private static function find(int $id): array
    {
        $e = Database::one('SELECT * FROM v_equipment_availability WHERE equipment_id = ?', [$id]);
        if (!$e) {
            throw new ApiException('Equipment not found', 404);
        }
        return $e;
    }
}
