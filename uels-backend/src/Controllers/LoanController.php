<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{ApiException, Audit, Auth, Database, Paginator, Request, Response, Validator};

class LoanController
{
    /* ------------------------------ listing ------------------------------ */

    public static function index(Request $req, array $p, array $user): void
    {
        // Borrowers can only ever see their own transactions
        $forced = Auth::isLender($user) ? [] : ['borrower_id' => $user['user_id']];
        self::listLoans($req, $forced);
    }

    public static function overdue(Request $req): void
    {
        self::listLoans($req, ['status' => 'overdue']);
    }

    /** Borrowing history of one borrower (staff, or the borrower themself). */
    public static function byUser(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        if (!Auth::isLender($user) && $id !== $user['user_id']) {
            throw new ApiException('You can only view your own borrowing history', 403);
        }
        self::listLoans($req, ['borrower_id' => $id]);
    }

    /** Borrowing history of one equipment item. */
    public static function byEquipment(Request $req, array $p): void
    {
        self::listLoans($req, ['equipment_id' => (int) $p['id']]);
    }

    private static function listLoans(Request $req, array $forced = []): void
    {
        $filters = $forced + [
            'status'       => $req->get('status'),
            'borrower_id'  => $req->get('borrower_id'),
            'equipment_id' => $req->get('equipment_id'),
            'category_id'  => $req->get('category_id'),
        ];

        $w = []; $a = [];
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'overdue') {
                $w[] = "display_status = 'overdue'";
            } else {
                $w[] = 'status = ?';
                $a[] = $filters['status'];
            }
        }
        foreach (['borrower_id', 'equipment_id', 'category_id'] as $key) {
            if (!empty($filters[$key])) {
                $w[] = "{$key} = ?";
                $a[] = (int) $filters[$key];
            }
        }
        if (($q = trim((string) $req->get('q', ''))) !== '') {
            $w[] = '(borrower_name LIKE ? OR borrower_id_number LIKE ? OR equipment_name LIKE ? OR asset_tag LIKE ?)';
            array_push($a, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($from = Validator::dateParam($req->get('from'), 'from')) { $w[] = 'DATE(requested_at) >= ?'; $a[] = $from; }
        if ($to   = Validator::dateParam($req->get('to'), 'to'))     { $w[] = 'DATE(requested_at) <= ?'; $a[] = $to; }

        $order = Paginator::order($req, [
            'requested_at' => 'requested_at', 'due_date' => 'due_date', 'status' => 'status',
            'borrower' => 'borrower_name', 'equipment' => 'equipment_name', 'days_overdue' => 'days_overdue',
        ], 'loan_id DESC');

        [$items, $meta] = Paginator::run($req, '*', 'v_loan_details', Paginator::where($w), $a, $order);
        Response::success($items, 'OK', 200, $meta);
    }

    public static function show(Request $req, array $p, array $user): void
    {
        $loan = self::find((int) $p['id']);
        if (!Auth::isLender($user) && (int) $loan['borrower_id'] !== $user['user_id']) {
            throw new ApiException('Loan not found', 404);
        }
        Response::success($loan);
    }

    /* ------------------------------ actions ------------------------------ */

    /** Borrower asks for equipment (status = pending). */
    public static function request(Request $req, array $p, array $user): void
    {
        $d = Validator::validate($req->input(), [
            'equipment_id' => 'required|int|min:1',
            'quantity'     => 'required|int|min:1',
            'due_date'     => 'required|future_date',
            'purpose'      => 'nullable|string|max:255',
        ]);
        $row = Database::call('sp_request_loan', [
            $user['user_id'], $d['equipment_id'], $d['quantity'], $d['due_date'], $d['purpose'] ?? null,
        ]);
        Audit::log($user['user_id'], 'loan.request', 'loan', (int) $row['loan_id']);
        Response::success(self::find((int) $row['loan_id']), 'Request submitted. Please wait for approval.', 201);
    }

    /** Staff record a walk-in borrowing (request + issue in one transaction). */
    public static function store(Request $req, array $p, array $user): void
    {
        $d = Validator::validate($req->input(), [
            'borrower_id'      => 'required|int|min:1',
            'equipment_id'     => 'required|int|min:1',
            'quantity'         => 'required|int|min:1',
            'due_date'         => 'required|future_date',
            'condition_before' => 'nullable|in:' . EquipmentController::CONDITIONS,
            'purpose'          => 'nullable|string|max:255',
        ]);
        $row = Database::call('sp_direct_borrow', [
            $d['borrower_id'], $d['equipment_id'], $d['quantity'], $d['due_date'],
            $user['user_id'], $d['condition_before'] ?? null, $d['purpose'] ?? null,
        ]);
        Audit::log($user['user_id'], 'loan.borrow', 'loan', (int) $row['loan_id']);
        Response::success(self::find((int) $row['loan_id']), 'Equipment issued', 201);
    }

    /** Staff approve a pending request and hand over the item. */
    public static function issue(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $d = Validator::validate($req->input(), [
            'due_date'         => 'nullable|future_date',
            'condition_before' => 'nullable|in:' . EquipmentController::CONDITIONS,
        ]);
        Database::call('sp_issue_loan', [$id, $user['user_id'], $d['due_date'] ?? null, $d['condition_before'] ?? null]);
        Audit::log($user['user_id'], 'loan.issue', 'loan', $id);
        Response::success(self::find($id), 'Equipment issued');
    }

    public static function reject(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $d = Validator::validate($req->input(), ['remarks' => 'nullable|string|max:500']);
        self::closePending($id, 'rejected', $d['remarks'] ?? null, null);
        Audit::log($user['user_id'], 'loan.reject', 'loan', $id);
        Response::success(self::find($id), 'Request rejected');
    }

    /** Borrower cancels own pending request; staff can cancel any pending request. */
    public static function cancel(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $loan = self::find($id);
        $ownerOnly = null;
        if (!Auth::isLender($user)) {
            if ((int) $loan['borrower_id'] !== $user['user_id']) {
                throw new ApiException('Loan not found', 404);
            }
            $ownerOnly = $user['user_id'];
        }
        self::closePending($id, 'cancelled', null, $ownerOnly);
        Audit::log($user['user_id'], 'loan.cancel', 'loan', $id);
        Response::success(self::find($id), 'Request cancelled');
    }

    /** Staff receive the item back and record its condition. */
    public static function returnLoan(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $d = Validator::validate($req->input(), [
            'condition_after' => 'nullable|in:' . EquipmentController::CONDITIONS,
            'remarks'         => 'nullable|string|max:500',
        ]);
        Database::call('sp_return_loan', [$id, $user['user_id'], $d['condition_after'] ?? null, $d['remarks'] ?? null]);
        Audit::log($user['user_id'], 'loan.return', 'loan', $id, $d['condition_after'] ?? null);
        Response::success(self::find($id), 'Equipment returned');
    }

    /* ------------------------------ helpers ------------------------------ */

    private static function closePending(int $id, string $newStatus, ?string $remarks, ?int $ownerOnly): void
    {
        self::find($id); // 404 if missing
        $sql = "UPDATE loans SET status = ?, remarks = COALESCE(?, remarks) WHERE loan_id = ? AND status = 'pending'";
        $params = [$newStatus, $remarks, $id];
        if ($ownerOnly !== null) {
            $sql .= ' AND borrower_id = ?';
            $params[] = $ownerOnly;
        }
        if (Database::execute($sql, $params) === 0) {
            throw new ApiException('Only pending requests can be ' . $newStatus, 409);
        }
    }

    private static function find(int $id): array
    {
        $loan = Database::one('SELECT * FROM v_loan_details WHERE loan_id = ?', [$id]);
        if (!$loan) {
            throw new ApiException('Loan not found', 404);
        }
        return $loan;
    }
}
