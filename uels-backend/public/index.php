<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\{AuthController, CategoryController, EquipmentController, LoanController, ReportController, UserController};
use App\Core\{ApiException, Auth, Env, Request, Response, Router};

/* ----------------------------- headers / CORS ----------------------------- */
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(array_map('trim', explode(',', (string) Env::get('ALLOWED_ORIGIN', ''))));
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* --------------------------------- routes --------------------------------- */
$ANY      = [];                                    // any logged-in user
$ADMIN    = [Auth::ADMIN];
$LENDERS  = Auth::LENDERS;                         // administrator + staff
$BORROWER = Auth::BORROWERS;                       // student + faculty

$r = new Router();

// Public
$r->get('/api/health',        static function () { Response::success(['status' => 'ok', 'time' => date('c')]); });
$r->post('/api/setup',        [AuthController::class, 'setup']);
$r->post('/api/auth/login',   [AuthController::class, 'login']);
$r->post('/api/auth/register', [AuthController::class, 'register']);

// Session
$r->post('/api/auth/logout',          [AuthController::class, 'logout'], $ANY);
$r->get('/api/auth/me',               [AuthController::class, 'me'], $ANY);
$r->post('/api/auth/change-password', [AuthController::class, 'changePassword'], $ANY);
$r->get('/api/dashboard',             [ReportController::class, 'dashboard'], $ANY);

// Users
$r->get('/api/users',              [UserController::class, 'index'], $LENDERS);
$r->post('/api/users',             [UserController::class, 'store'], $LENDERS);
$r->get('/api/users/{id}',         [UserController::class, 'show'], $LENDERS);
$r->put('/api/users/{id}',         [UserController::class, 'update'], $LENDERS);
$r->delete('/api/users/{id}',      [UserController::class, 'destroy'], $ADMIN);
$r->get('/api/users/{id}/loans',   [LoanController::class, 'byUser'], $ANY);

// Categories
$r->get('/api/categories',         [CategoryController::class, 'index'], $ANY);
$r->post('/api/categories',        [CategoryController::class, 'store'], $ADMIN);
$r->put('/api/categories/{id}',    [CategoryController::class, 'update'], $ADMIN);
$r->delete('/api/categories/{id}', [CategoryController::class, 'destroy'], $ADMIN);

// Equipment
$r->get('/api/equipment',                       [EquipmentController::class, 'index'], $ANY);
$r->post('/api/equipment',                      [EquipmentController::class, 'store'], $ADMIN);
$r->get('/api/equipment/{id}',                  [EquipmentController::class, 'show'], $ANY);
$r->put('/api/equipment/{id}',                  [EquipmentController::class, 'update'], $ADMIN);
$r->delete('/api/equipment/{id}',               [EquipmentController::class, 'destroy'], $ADMIN);
$r->get('/api/equipment/{id}/loans',            [LoanController::class, 'byEquipment'], $LENDERS);
$r->get('/api/equipment/{id}/condition-logs',   [EquipmentController::class, 'conditionLogs'], $LENDERS);

// Loans (borrowing / returns)
$r->get('/api/loans',                [LoanController::class, 'index'], $ANY);
$r->post('/api/loans',               [LoanController::class, 'store'], $LENDERS);      // walk-in borrowing
$r->post('/api/loans/request',       [LoanController::class, 'request'], $BORROWER);   // borrower request
$r->get('/api/loans/overdue',        [LoanController::class, 'overdue'], $LENDERS);
$r->get('/api/loans/{id}',           [LoanController::class, 'show'], $ANY);
$r->post('/api/loans/{id}/issue',    [LoanController::class, 'issue'], $LENDERS);
$r->post('/api/loans/{id}/reject',   [LoanController::class, 'reject'], $LENDERS);
$r->post('/api/loans/{id}/cancel',   [LoanController::class, 'cancel'], $ANY);
$r->post('/api/loans/{id}/return',   [LoanController::class, 'returnLoan'], $LENDERS);

// Reports (add ?format=csv to download)
$r->get('/api/reports/inventory',    [ReportController::class, 'inventory'], $LENDERS);
$r->get('/api/reports/borrowing',    [ReportController::class, 'borrowing'], $LENDERS);
$r->get('/api/reports/returns',      [ReportController::class, 'returns'], $LENDERS);
$r->get('/api/reports/overdue',      [ReportController::class, 'overdue'], $LENDERS);
$r->get('/api/reports/damaged',      [ReportController::class, 'damaged'], $LENDERS);
$r->get('/api/reports/transactions', [ReportController::class, 'transactions'], $LENDERS);

// Admin
$r->get('/api/audit-logs',           [ReportController::class, 'auditLogs'], $ADMIN);

/* -------------------------------- dispatch -------------------------------- */
try {
    $r->dispatch(Request::fromGlobals());
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->status, $e->errors);
} catch (Throwable $e) {
    error_log('UELS error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error(Env::bool('APP_DEBUG') ? $e->getMessage() : 'Internal server error', 500);
}
