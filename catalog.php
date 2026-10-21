<?php
require __DIR__ . '/config.php';
require_login();
verify_csrf();
if (!in_array(current_user()['role'], ['student', 'faculty'], true)) {
    header('Location: index.php?page=equipment');
    exit;
}
function catalog_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$search = trim($_GET['q'] ?? '');
$category = (int)($_GET['category'] ?? 0);
$cats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$sql = 'SELECT e.asset_code, e.name, e.description, e.total_quantity, e.available_quantity, e.condition_status, c.name AS category FROM equipment e LEFT JOIN categories c ON c.id=e.category_id WHERE e.active=1 AND (e.name LIKE ? OR e.asset_code LIKE ?)';
$args = ['%' . $search . '%', '%' . $search . '%'];
if ($category > 0) { $sql .= ' AND e.category_id=?'; $args[] = $category; }
$sql .= ' ORDER BY e.name';
$stmt = $pdo->prepare($sql); $stmt->execute($args); $items = $stmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Equipment Catalog · UELS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head><body>
<nav class="navbar navbar-expand-lg navbar-dark app-nav"><div class="container-fluid"><a class="navbar-brand fw-bold" href="index.php">UELS <span class="fw-normal small">· Equipment Lending</span></a><div class="navbar-nav me-auto"><a class="nav-link" href="index.php?page=dashboard">Dashboard</a><a class="nav-link active" href="catalog.php">Equipment Catalog</a><a class="nav-link" href="index.php?page=loans">Loans &amp; Returns</a></div><span class="navbar-text me-3"><?=catalog_escape(current_user()['name'])?> · <?=catalog_escape(ucfirst(current_user()['role']))?></span><a class="btn btn-sm btn-outline-light" href="index.php?page=logout">Sign out</a></div></nav>
<main class="container-fluid px-3 px-lg-4 py-4"><div class="mb-4"><h1 class="page-title">Equipment Catalog</h1><div class="text-muted">Browse university equipment and check availability.</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body"><form method="get" class="row g-2"><div class="col-md-7"><input class="form-control" name="q" value="<?=catalog_escape($search)?>" placeholder="Search equipment name or asset code"></div><div class="col-md-3"><select class="form-select" name="category"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?=$c['id']?>" <?=$category === (int)$c['id'] ? 'selected' : ''?>><?=catalog_escape($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-primary">Search</button></div></form></div></div>
<div class="row g-3"><?php if (!$items): ?><div class="col-12"><div class="alert alert-info">No equipment found. Check that the administrator has saved active equipment in this same database.</div></div><?php endif; ?>
<?php foreach ($items as $it): $available=(int)$it['available_quantity']; $condition=$it['condition_status']; $isAvailable=$available>0 && $condition!=='damaged'; ?><div class="col-sm-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2 mb-2"><span class="badge text-bg-light"><?=catalog_escape($it['category'] ?? 'Uncategorized')?></span><span class="badge text-bg-<?=$isAvailable?'success':'secondary'?>"><?=$isAvailable?'Available':'Unavailable'?></span></div><h2 class="h5"><?=catalog_escape($it['name'])?></h2><div class="small text-muted mb-2">Asset code: <?=catalog_escape($it['asset_code'])?></div><p><?=catalog_escape($it['description'] ?: 'No description provided.')?></p><div class="d-flex justify-content-between"><span class="text-muted">Available units</span><strong><?=$available?> / <?=(int)$it['total_quantity']?></strong></div><div class="small text-muted mt-2">Condition: <?=catalog_escape(ucfirst($condition))?></div></div></div></div><?php endforeach; ?></div>
<div class="alert alert-secondary mt-4">To borrow an item, contact the lending staff. Staff process and approve borrowing transactions.</div></main></body></html>
