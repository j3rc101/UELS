<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Database, Paginator, Request, Response, Validator};

class ReportController
{
    private const LIMIT = 5000;

    /* ------------------------------ dashboard ------------------------------ */

    public static function dashboard(Request $req, array $p, array $user): void
    {
        if (Auth::isLender($user)) {
            $stats = Database::one(
                "SELECT
                   (SELECT COUNT(*) FROM equipment WHERE is_active = 1)                                   AS equipment_items,
                   (SELECT COALESCE(SUM(total_quantity), 0)     FROM equipment WHERE is_active = 1)       AS total_units,
                   (SELECT COALESCE(SUM(available_quantity), 0) FROM equipment WHERE is_active = 1)       AS available_units,
                   (SELECT COALESCE(SUM(damaged_quantity), 0)   FROM equipment)                           AS damaged_units,
                   (SELECT COALESCE(SUM(quantity), 0) FROM loans WHERE status = 'borrowed')               AS units_on_loan,
                   (SELECT COUNT(*) FROM loans WHERE status = 'pending')                                  AS pending_requests,
                   (SELECT COUNT(*) FROM loans WHERE status = 'borrowed')                                 AS active_loans,
                   (SELECT COUNT(*) FROM loans WHERE status = 'borrowed' AND due_date < CURDATE())        AS overdue_loans,
                   (SELECT COUNT(*) FROM loans WHERE status = 'borrowed' AND due_date = CURDATE())        AS due_today,
                   (SELECT COUNT(*) FROM users WHERE status = 'active')                                   AS active_users"
            );
            $top = Database::all(
                "SELECT e.name, e.asset_tag, COUNT(*) AS times_borrowed, SUM(l.quantity) AS units_borrowed
                 FROM loans l JOIN equipment e ON e.equipment_id = l.equipment_id
                 WHERE l.status IN ('borrowed','returned')
                 GROUP BY e.equipment_id, e.name, e.asset_tag
                 ORDER BY times_borrowed DESC LIMIT 5"
            );
            $cats = Database::all('SELECT * FROM v_category_summary ORDER BY category_name');
            Response::success(['role' => $user['role'], 'stats' => $stats, 'most_borrowed' => $top, 'by_category' => $cats]);
        }

        $stats = Database::one(
            "SELECT
               SUM(status = 'pending')                                  AS pending_requests,
               SUM(status = 'borrowed')                                 AS active_loans,
               SUM(status = 'borrowed' AND due_date < CURDATE())        AS overdue_loans,
               SUM(status = 'borrowed' AND due_date = CURDATE())        AS due_today,
               SUM(status = 'returned')                                 AS returned_total
             FROM loans WHERE borrower_id = ?",
            [$user['user_id']]
        );
        foreach ($stats as $k => $v) { $stats[$k] = (int) $v; }
        Response::success(['role' => $user['role'], 'stats' => $stats]);
    }

    /* ------------------------------ reports ------------------------------ */

    public static function inventory(Request $req): void
    {
        $w = []; $a = [];
        if ($c = $req->get('category_id')) { $w[] = 'category_id = ?'; $a[] = (int) $c; }
        if ($s = $req->get('status'))      { $w[] = 'status = ?';      $a[] = $s; }
        $rows = Database::all(
            'SELECT equipment_id, asset_tag, name, category_name, total_quantity, available_quantity, borrowed_quantity,
                    damaged_quantity, condition_status, status, location, is_active
             FROM v_equipment_availability ' . Paginator::where($w) . ' ORDER BY category_name, name LIMIT ' . self::LIMIT,
            $a
        );
        self::output($req, 'inventory-report', $rows);
    }

    public static function borrowing(Request $req): void
    {
        [$w, $a] = self::loanFilters($req, 'borrowed_at');
        array_unshift($w, "status IN ('borrowed','returned')");
        self::output($req, 'borrowing-report', self::loanRows($w, $a, 'borrowed_at DESC'));
    }

    public static function returns(Request $req): void
    {
        [$w, $a] = self::loanFilters($req, 'returned_at');
        array_unshift($w, "status = 'returned'");
        self::output($req, 'returns-report', self::loanRows($w, $a, 'returned_at DESC'));
    }

    public static function overdue(Request $req): void
    {
        $rows = Database::all(
            'SELECT loan_id, borrower_name, borrower_id_number, borrower_email, equipment_name, asset_tag, quantity,
                    borrowed_at, due_date, days_overdue
             FROM v_overdue_loans ORDER BY days_overdue DESC LIMIT ' . self::LIMIT
        );
        self::output($req, 'overdue-report', $rows);
    }

    public static function damaged(Request $req): void
    {
        $rows = Database::all(
            'SELECT equipment_id, asset_tag, name, category_name, total_quantity, damaged_quantity, condition_status, status, location
             FROM v_damaged_equipment ORDER BY damaged_quantity DESC, name LIMIT ' . self::LIMIT
        );
        self::output($req, 'damaged-equipment-report', $rows);
    }

    public static function transactions(Request $req): void
    {
        [$w, $a] = self::loanFilters($req, 'requested_at');
        if ($s = $req->get('status')) { $w[] = 'status = ?'; $a[] = $s; }
        self::output($req, 'transaction-history', self::loanRows($w, $a, 'loan_id DESC'));
    }

    public static function auditLogs(Request $req): void
    {
        $w = []; $a = [];
        if ($u = $req->get('user_id')) { $w[] = 'a.user_id = ?'; $a[] = (int) $u; }
        if ($x = $req->get('action'))  { $w[] = 'a.action LIKE ?'; $a[] = $x . '%'; }
        [$items, $meta] = Paginator::run(
            $req,
            "a.log_id, a.created_at, a.action, a.entity, a.entity_id, a.details, a.ip_address,
             CONCAT(u.first_name, ' ', u.last_name) AS user_name",
            'audit_logs a LEFT JOIN users u ON u.user_id = a.user_id',
            Paginator::where($w), $a, 'a.log_id DESC'
        );
        Response::success($items, 'OK', 200, $meta);
    }

    /* ------------------------------ helpers ------------------------------ */

    /** Date-range filter (?from=YYYY-MM-DD&to=YYYY-MM-DD) on the given column. */
    private static function loanFilters(Request $req, string $column): array
    {
        $w = []; $a = [];
        if ($from = Validator::dateParam($req->get('from'), 'from')) { $w[] = "DATE({$column}) >= ?"; $a[] = $from; }
        if ($to   = Validator::dateParam($req->get('to'), 'to'))     { $w[] = "DATE({$column}) <= ?"; $a[] = $to; }
        if ($c = $req->get('category_id'))                           { $w[] = 'category_id = ?';       $a[] = (int) $c; }
        if ($b = $req->get('borrower_id'))                           { $w[] = 'borrower_id = ?';       $a[] = (int) $b; }
        return [$w, $a];
    }

    private static function loanRows(array $w, array $a, string $order): array
    {
        return Database::all(
            'SELECT loan_id, borrower_name, borrower_id_number, borrower_role, equipment_name, asset_tag, category_name,
                    quantity, display_status AS status, requested_at, borrowed_at, due_date, returned_at,
                    condition_before, condition_after, issued_by_name, received_by_name, remarks
             FROM v_loan_details ' . Paginator::where($w) . " ORDER BY {$order} LIMIT " . self::LIMIT,
            $a
        );
    }

    /** JSON by default; CSV when ?format=csv */
    private static function output(Request $req, string $name, array $rows): void
    {
        if ($req->get('format') === 'csv') {
            Response::csv($name . '-' . date('Ymd-His') . '.csv', $rows);
        }
        Response::success($rows, 'OK', 200, ['count' => count($rows), 'generated_at' => date('c')]);
    }
}
