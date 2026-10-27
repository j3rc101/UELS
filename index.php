<?php
require __DIR__.'/config.php';
$page = $_GET['page'] ?? (current_user() ? 'dashboard' : 'login');
$public = ['login','setup'];
if (!in_array($page, $public, true)) require_login();
verify_csrf();

if ($page === 'setup') {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ($count > 0) { flash('Administrator setup is already complete. Please log in.', 'warning'); redirect('login'); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $name=trim($_POST['full_name']??''); $email=strtolower(trim($_POST['email']??'')); $pass=$_POST['password']??'';
        if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<10) { flash('Enter a name, valid email, and password of at least 10 characters.','danger'); }
        else { $st=$pdo->prepare("INSERT INTO users(full_name,email,password_hash,role) VALUES(?,?,?,'admin')"); $st->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]); flash('Administrator created. You can now log in.'); redirect('login'); }
    }
}
if ($page === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
    $email=strtolower(trim($_POST['email']??'')); $password=$_POST['password']??'';
    $st=$pdo->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1'); $st->execute([$email]); $u=$st->fetch();
    if ($u && password_verify($password,$u['password_hash'])) { session_regenerate_id(true); $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role']]; redirect('dashboard'); }
    flash('Invalid email or password, or account is inactive.','danger');
}
if ($page==='logout') { $_SESSION=[]; session_destroy(); header('Location: index.php?page=login'); exit; }
if ($page==='password' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $uid = (int)current_user()['id'];
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND active=1');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || !password_verify($current, $u['password_hash'])) {
        flash('Current password is incorrect.', 'danger');
    } elseif (strlen($new) < 10) {
        flash('New password must be at least 10 characters long.', 'danger');
    } elseif ($new !== $confirm) {
        flash('New password and confirmation do not match.', 'danger');
    } else {
        $st = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $st->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
        flash('Password updated successfully.');
    }
    redirect('password');
}
if ($page==='equipment' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['admin','staff']);
    $id=(int)($_POST['id']??0); $code=trim($_POST['asset_code']??''); $name=trim($_POST['name']??''); $cat=(int)($_POST['category_id']??0); $qty=filter_var($_POST['total_quantity']??null,FILTER_VALIDATE_INT); $cond=$_POST['condition_status']??'good'; $desc=trim($_POST['description']??'');
    if ($code==='' || $name==='' || $qty===false || $qty<1 || !in_array($cond,['good','fair','damaged'],true)) flash('Enter valid equipment details. Quantity must be at least 1.','danger');
    else {
      try {
        if ($id) { $st=$pdo->prepare('SELECT total_quantity,available_quantity FROM equipment WHERE id=?'); $st->execute([$id]); $old=$st->fetch(); if(!$old) throw new RuntimeException('Equipment not found.'); $borrowed=(int)$old['total_quantity']-(int)$old['available_quantity']; if($qty<$borrowed) throw new RuntimeException('Total quantity cannot be lower than the number currently borrowed.'); $avail=$qty-$borrowed; $st=$pdo->prepare('UPDATE equipment SET asset_code=?,name=?,category_id=?,description=?,total_quantity=?,available_quantity=?,condition_status=? WHERE id=?'); $st->execute([$code,$name,$cat?:null,$desc,$qty,$avail,$cond,$id]); }
        else { $st=$pdo->prepare('INSERT INTO equipment(asset_code,name,category_id,description,total_quantity,available_quantity,condition_status) VALUES(?,?,?,?,?,?,?)'); $st->execute([$code,$name,$cat?:null,$desc,$qty,$qty,$cond]); }
        flash('Equipment saved successfully.');
      } catch(Throwable $ex) { flash($ex instanceof PDOException ? 'Could not save: asset code may already exist.' : $ex->getMessage(),'danger'); }
    } redirect('equipment');
}
if ($page==='borrow' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['admin','staff']);
    $borrower=(int)($_POST['borrower_id']??0); $equip=(int)($_POST['equipment_id']??0); $qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT); $due=$_POST['due_at']??'';
    if (!$borrower || !$equip || $qty===false || $qty<1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due) || $due<date('Y-m-d')) { flash('Check borrower, equipment, quantity, and due date.','danger'); redirect('borrow'); }
    try { $pdo->beginTransaction(); $st=$pdo->prepare('SELECT * FROM equipment WHERE id=? AND active=1 FOR UPDATE'); $st->execute([$equip]); $item=$st->fetch(); if(!$item || (int)$item['available_quantity']<$qty) throw new RuntimeException('Equipment is unavailable or quantity exceeds stock.'); $st=$pdo->prepare('SELECT id FROM users WHERE id=? AND active=1'); $st->execute([$borrower]); if(!$st->fetch()) throw new RuntimeException('Borrower account not found or inactive.'); $st=$pdo->prepare('INSERT INTO loans(borrower_id,equipment_id,quantity,due_at,condition_before,processed_by,status) VALUES(?,?,?,?,?,?,\'borrowed\')'); $st->execute([$borrower,$equip,$qty,$due,$item['condition_status'],current_user()['id']]); $st=$pdo->prepare('UPDATE equipment SET available_quantity=available_quantity-? WHERE id=?'); $st->execute([$qty,$equip]); $pdo->commit(); flash('Borrowing transaction recorded.'); }
    catch(Throwable $ex) { if($pdo->inTransaction())$pdo->rollBack(); flash($ex->getMessage(),'danger'); } redirect('loans');
}
if ($page==='return' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['admin','staff']); $loan=(int)($_POST['loan_id']??0); $cond=$_POST['condition_after']??'good'; $notes=trim($_POST['notes']??'');
    if(!in_array($cond,['good','fair','damaged'],true)) { flash('Invalid condition selected.','danger'); redirect('loans'); }
    try { $pdo->beginTransaction(); $st=$pdo->prepare("SELECT * FROM loans WHERE id=? AND status IN ('borrowed','overdue') FOR UPDATE"); $st->execute([$loan]); $l=$st->fetch(); if(!$l) throw new RuntimeException('Loan is already returned or does not exist.'); $st=$pdo->prepare('UPDATE loans SET status=\'returned\',returned_at=NOW(),condition_after=?,return_processed_by=?,notes=? WHERE id=?'); $st->execute([$cond,current_user()['id'],$notes,$loan]); $st=$pdo->prepare('UPDATE equipment SET available_quantity=LEAST(total_quantity,available_quantity+?), condition_status=? WHERE id=?'); $st->execute([$l['quantity'],$cond,$l['equipment_id']]); $pdo->commit(); flash('Return recorded successfully.'); }
    catch(Throwable $ex) { if($pdo->inTransaction())$pdo->rollBack(); flash($ex->getMessage(),'danger'); } redirect('loans');
}
if ($page==='users' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['admin']);
    $action = $_POST['action'] ?? 'create';
    if ($action === 'change_password') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $st = $pdo->prepare('SELECT id, full_name, role FROM users WHERE id=?');
        $st->execute([$targetId]);
        $target = $st->fetch();
        if (!$target) {
            flash('Target user not found.', 'danger');
        } elseif (strlen($new) < 10) {
            flash('New password must be at least 10 characters long.', 'danger');
        } elseif ($new !== $confirm) {
            flash('New password and confirmation do not match.', 'danger');
        } else {
            $st = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $st->execute([password_hash($new, PASSWORD_DEFAULT), $targetId]);
            flash('Password for ' . $target['full_name'] . ' (' . ucfirst($target['role']) . ') updated successfully.');
        }
        redirect('users');
    } else {
        $name=trim($_POST['full_name']??''); $email=strtolower(trim($_POST['email']??'')); $pass=$_POST['password']??''; $role=$_POST['role']??'student'; $no=trim($_POST['student_staff_no']??'');
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<10||!in_array($role,['admin','staff','student','faculty'],true)) flash('Enter valid user details. Password must be at least 10 characters.','danger');
        else { try{$st=$pdo->prepare('INSERT INTO users(full_name,email,password_hash,role,student_staff_no) VALUES(?,?,?,?,?)');$st->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$no?:null]);flash('User created.');}catch(PDOException $ex){flash('Could not create user: email may already exist.','danger');} } redirect('users');
    }
}

$title = $page === 'password' ? 'Change Password' : ($page === 'loans' ? 'Loans & Returns' : ucfirst($page));
if ($page==='login' || $page==='setup') {
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=e($page==='setup'?'UELS Setup':'UELS Login')?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head><body class="auth-bg"><main class="auth-card"><div class="brand-mark">U</div><h1><?= $page==='setup'?'Create Administrator':'University Equipment Lending System' ?></h1><p class="text-muted"><?= $page==='setup'?'One-time initial setup':'Sign in to manage equipment and lending records.' ?></p><?php show_flash(); ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><?php if($page==='setup'): ?><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required><?php else: ?><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required autocomplete="username"><?php endif; ?><label class="form-label">Password<?= $page==='setup'?' (at least 10 characters)':'' ?></label><input class="form-control mb-3" type="password" name="password" required <?= $page==='setup'?'minlength="10"':'autocomplete="current-password"' ?>><button class="btn btn-primary w-100" type="submit"><?= $page==='setup'?'Create administrator':'Sign in' ?></button></form><?php if($page==='login'): ?><p class="small text-muted mt-4 mb-0">First time setup? Open <code>index.php?page=setup</code> after importing the database.</p><?php endif; ?></main></body></html><?php exit;
}

function show_flash(): void { if(!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="alert alert-'.e($f['type']).'">'.e($f['message']).'</div>'; } }
function rows(PDO $pdo,string $sql,array $args=[]): array { $st=$pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); }
// Mark loans overdue whenever pages are loaded; the due date is the campus-local date.
$pdo->exec("UPDATE loans SET status='overdue' WHERE status='borrowed' AND due_at<CURDATE()");
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=e($title)?> · UELS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head><body>
<nav class="navbar navbar-expand-lg navbar-dark app-nav">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php">UELS <span class="fw-normal small">· Equipment Lending</span></a>
    <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <div class="navbar-nav me-auto">
        <?php
        $links = ['dashboard' => 'Dashboard'];
        if (in_array(current_user()['role'], ['student', 'faculty'], true)) {
            $links['catalog'] = 'Equipment Catalog';
        }
        if (in_array(current_user()['role'], ['admin', 'staff'], true)) {
            $links['equipment'] = 'Equipment';
        }
        $links['loans'] = 'Loans & Returns';
        if (in_array(current_user()['role'], ['admin', 'staff'], true)) {
            $links['borrow'] = 'New Borrowing';
            $links['reports'] = 'Reports';
        }
        if (current_user()['role'] === 'admin') {
            $links['users'] = 'Users';
        }
        foreach ($links as $k => $v) {
            $href = ($k === 'catalog') ? 'catalog.php' : 'index.php?page=' . $k;
            $isActive = ($page === $k);
            echo '<a class="nav-link ' . ($isActive ? 'active' : '') . '" href="' . $href . '">' . e($v) . '</a>';
        }
        ?>
      </div>
      <span class="navbar-text me-3"><?=e(current_user()['name'])?> · <span class="badge bg-light text-dark"><?=e(ucfirst(current_user()['role']))?></span></span>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-light <?=($page==='password'?'active':'')?>" href="index.php?page=password">Change Password</a>
        <a class="btn btn-sm btn-outline-light" href="index.php?page=logout">Sign out</a>
      </div>
    </div>
  </div>
</nav>
<main class="container-fluid px-3 px-lg-4 py-4">
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="page-title"><?=e($title)?></h1>
      <div class="text-muted">University Equipment Lending System</div>
    </div>
  </div>
  <?php show_flash(); ?>
<?php if($page==='dashboard') { $stats=[['Equipment types',(int)$pdo->query('SELECT COUNT(*) FROM equipment WHERE active=1')->fetchColumn(),'bi-box-seam'],['Available units',(int)$pdo->query('SELECT COALESCE(SUM(available_quantity),0) FROM equipment WHERE active=1')->fetchColumn(),'bi-check-circle'],['Currently borrowed',(int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM loans WHERE status IN ('borrowed','overdue')")->fetchColumn(),'bi-arrow-left-right'],['Overdue loans',(int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='overdue'")->fetchColumn(),'bi-exclamation-circle']]; ?><div class="row g-3 mb-4"><?php foreach($stats as $s): ?><div class="col-6 col-xl-3"><div class="stat-card"><div class="text-muted small"><?=e($s[0])?></div><div class="stat-number"><?=e($s[1])?></div></div></div><?php endforeach; ?></div><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Recent lending activity</h2><?php table(['Borrower','Equipment','Qty','Due date','Status'],rows($pdo,"SELECT u.full_name,e.name,l.quantity,l.due_at,l.status FROM loans l JOIN users u ON u.id=l.borrower_id JOIN equipment e ON e.id=l.equipment_id ORDER BY l.id DESC LIMIT 8"),['full_name','name','quantity','due_at','status']); ?></div></div>
<?php } elseif($page==='password') { ?>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-2">Change Password</h2>
        <p class="text-muted small mb-4">Update your account password below. Passwords must be at least 10 characters long.</p>
        <form method="post" action="index.php?page=password">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <div class="mb-3">
            <label class="form-label" for="current_password">Current Password</label>
            <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Enter current password">
          </div>
          <div class="mb-3">
            <label class="form-label" for="new_password">New Password</label>
            <input class="form-control" type="password" id="new_password" name="new_password" minlength="10" required autocomplete="new-password" placeholder="Enter new password (min. 10 chars)">
            <div class="form-text">Must be at least 10 characters long.</div>
          </div>
          <div class="mb-4">
            <label class="form-label" for="confirm_password">Confirm New Password</label>
            <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="10" required autocomplete="new-password" placeholder="Re-enter new password">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Update Password</button>
            <a href="index.php?page=dashboard" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php } elseif($page==='equipment') { require_roles(['admin','staff']); $cats=rows($pdo,'SELECT * FROM categories ORDER BY name'); if(isset($_GET['edit'])) { $ed=rows($pdo,'SELECT * FROM equipment WHERE id=?',[(int)$_GET['edit']]); $ed=$ed[0]??null; } else $ed=null; ?><div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3"><?=$ed?'Edit equipment':'Add equipment'?></h2><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($ed['id']??0)?>"><div class="col-md-2"><label class="form-label">Asset code</label><input class="form-control" name="asset_code" required value="<?=e($ed['asset_code']??'')?>"></div><div class="col-md-3"><label class="form-label">Equipment name</label><input class="form-control" name="name" required value="<?=e($ed['name']??'')?>"></div><div class="col-md-2"><label class="form-label">Category</label><select class="form-select" name="category_id"><option value="0">Uncategorized</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=($ed['category_id']??0)==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Total quantity</label><input type="number" min="1" class="form-control" name="total_quantity" required value="<?=e($ed['total_quantity']??1)?>"></div><div class="col-md-2"><label class="form-label">Condition</label><select class="form-select" name="condition_status"><?php foreach(['good','fair','damaged'] as $c): ?><option <?=($ed['condition_status']??'good')===$c?'selected':''?>><?=$c?></option><?php endforeach; ?></select></div><div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Save</button></div><div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" value="<?=e($ed['description']??'')?>"></div></form></div></div><?php $search=trim($_GET['q']??''); $list=rows($pdo,'SELECT e.*,c.name category FROM equipment e LEFT JOIN categories c ON c.id=e.category_id WHERE e.active=1 AND (e.name LIKE ? OR e.asset_code LIKE ?) ORDER BY e.name',['%'.$search.'%','%'.$search.'%']); ?><div class="card border-0 shadow-sm"><div class="card-body"><form class="d-flex gap-2 mb-3"><input type="hidden" name="page" value="equipment"><input class="form-control" name="q" placeholder="Search equipment or asset code" value="<?=e($search)?>"><button class="btn btn-outline-primary">Search</button></form><?php table(['Asset code','Equipment','Category','Total','Available','Condition'],array_map(fn($r)=>['asset_code'=>$r['asset_code'],'name'=>$r['name'],'category'=>$r['category']??'—','total_quantity'=>$r['total_quantity'],'available_quantity'=>$r['available_quantity'],'condition_status'=>$r['condition_status']],$list),['asset_code','name','category','total_quantity','available_quantity','condition_status'],true); ?><div class="small text-muted">To preserve transaction history, equipment records are not hard-deleted.</div></div></div>
<?php } elseif($page==='borrow') { require_roles(['admin','staff']); $borrowers=rows($pdo,"SELECT id,full_name,email,role FROM users WHERE active=1 AND role IN ('student','faculty','staff') ORDER BY full_name"); $items=rows($pdo,'SELECT id,asset_code,name,available_quantity FROM equipment WHERE active=1 AND available_quantity>0 ORDER BY name'); ?><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5 mb-3">Record a borrowing</h2><?php if(!$borrowers||!$items): ?><div class="alert alert-warning">You need at least one active borrower account and one available equipment item before recording a loan.</div><?php endif; ?><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="col-md-5"><label class="form-label">Borrower</label><select class="form-select" name="borrower_id" required><option value="">Select borrower</option><?php foreach($borrowers as $u): ?><option value="<?=$u['id']?>"><?=e($u['full_name'].' — '.$u['role'].' ('.$u['email'].')')?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label">Equipment</label><select class="form-select" name="equipment_id" required><?php foreach($items as $it): ?><option value="<?=$it['id']?>"><?=e($it['asset_code'].' — '.$it['name'].' ('.$it['available_quantity'].' available)')?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Quantity</label><input class="form-control" type="number" min="1" name="quantity" value="1" required></div><div class="col-md-4"><label class="form-label">Expected return date</label><input class="form-control" type="date" name="due_at" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d',strtotime('+7 days'))?>" required></div><div class="col-12"><button class="btn btn-primary" <?=(!$borrowers||!$items)?'disabled':''?>>Confirm borrowing</button></div></form></div></div>
<?php } elseif($page==='loans') { $isStaff=in_array(current_user()['role'],['admin','staff'],true); $sql="SELECT l.*,u.full_name borrower,e.asset_code,e.name equipment FROM loans l JOIN users u ON u.id=l.borrower_id JOIN equipment e ON e.id=l.equipment_id"; $args=[]; if(!$isStaff){$sql.=' WHERE l.borrower_id=?';$args[] = current_user()['id'];} $sql.=' ORDER BY l.id DESC LIMIT 300'; $loans=rows($pdo,$sql,$args); ?><div class="card border-0 shadow-sm"><div class="card-body"><p class="text-muted">Up to 300 most recent records. Overdue status updates automatically based on the due date.</p><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>ID</th><th>Borrower</th><th>Equipment</th><th>Qty</th><th>Borrowed</th><th>Due</th><th>Status</th><th>Condition</th><?php if($isStaff): ?><th>Action</th><?php endif; ?></tr></thead><tbody><?php foreach($loans as $l): ?><tr><td>#<?=$l['id']?></td><td><?=e($l['borrower'])?></td><td><?=e($l['asset_code'].' · '.$l['equipment'])?></td><td><?=$l['quantity']?></td><td><?=e($l['borrowed_at'])?></td><td><?=e($l['due_at'])?></td><td><span class="badge text-bg-<?= $l['status']==='overdue'?'danger':($l['status']==='returned'?'success':'primary') ?>"><?=e($l['status'])?></span></td><td><?=e($l['condition_before'])?><?= $l['condition_after']?' → '.e($l['condition_after']):'' ?></td><?php if($isStaff): ?><td><?php if(in_array($l['status'],['borrowed','overdue'],true)): ?><form method="post" action="index.php?page=return" class="d-flex gap-1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="loan_id" value="<?=$l['id']?>"><select class="form-select form-select-sm" name="condition_after"><option value="good">Good</option><option value="fair">Fair</option><option value="damaged">Damaged</option></select><input class="form-control form-control-sm" name="notes" placeholder="Return notes" aria-label="Return notes"><button class="btn btn-sm btn-success">Return</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php } elseif($page==='users') { require_roles(['admin']); $users=rows($pdo,'SELECT id,full_name,email,role,student_staff_no,active,created_at FROM users ORDER BY id DESC'); ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3">Create user account</h2><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><div class="col-md-3"><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div><div class="col-md-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div><div class="col-md-2"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach(['student','faculty','staff','admin'] as $r): ?><option value="<?=$r?>"><?=$r?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Student/Staff No.</label><input class="form-control" name="student_staff_no"></div><div class="col-md-2"><label class="form-label">Temporary password</label><input class="form-control" type="password" name="password" minlength="10" required></div><div class="col-12"><button class="btn btn-primary">Create account</button></div></form><p class="small text-muted mt-2 mb-0">Use a temporary password and share it securely. Administrators can change user passwords below, and users can also change their own password anytime.</p></div></div>
<div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5 mb-3">Registered users</h2>
<div class="table-responsive"><table class="table table-hover align-middle">
<thead><tr><th>Name</th><th>Email</th><th>Role</th><th>ID number</th><th>Active</th><th>Created</th><th>Action</th></tr></thead>
<tbody>
<?php if(!$users): ?><tr><td colspan="7" class="text-center text-muted py-4">No records found.</td></tr><?php endif; ?>
<?php foreach($users as $u): ?>
<tr>
<td><?=e($u['full_name'])?></td>
<td><?=e($u['email'])?></td>
<td><span class="badge text-bg-<?= $u['role']==='admin'?'primary':($u['role']==='staff'?'info':($u['role']==='faculty'?'warning':'secondary')) ?>"><?=e(ucfirst($u['role']))?></span></td>
<td><?=e($u['student_staff_no']?:'—')?></td>
<td><?=e($u['active']?'Yes':'No')?></td>
<td><?=e($u['created_at'])?></td>
<td>
<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adminResetModal" data-user-id="<?=$u['id']?>" data-user-name="<?=e($u['full_name'])?>" data-user-role="<?=e(ucfirst($u['role']))?>">Change Password</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div></div>

<div class="modal fade" id="adminResetModal" tabindex="-1" aria-labelledby="adminResetModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="index.php?page=users">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="user_id" id="resetUserId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="adminResetModalLabel">Change User Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Setting a new password for <strong id="resetUserName"></strong> (<span id="resetUserRole"></span>).</p>
          <div class="mb-3">
            <label class="form-label" for="reset_new_password">New Password</label>
            <input class="form-control" type="password" name="new_password" id="reset_new_password" minlength="10" required autocomplete="new-password" placeholder="Enter new password (min. 10 chars)">
            <div class="form-text">Must be at least 10 characters long.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reset_confirm_password">Confirm New Password</label>
            <input class="form-control" type="password" name="confirm_password" id="reset_confirm_password" minlength="10" required autocomplete="new-password" placeholder="Re-enter new password">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php } elseif($page==='reports') { require_roles(['admin','staff']); $inventory=rows($pdo,'SELECT e.asset_code,e.name,c.name category,e.total_quantity,e.available_quantity,(e.total_quantity-e.available_quantity) borrowed,e.condition_status FROM equipment e LEFT JOIN categories c ON c.id=e.category_id WHERE e.active=1 ORDER BY e.name'); $overdue=rows($pdo,"SELECT l.id,u.full_name,e.name,l.quantity,l.due_at,DATEDIFF(CURDATE(),l.due_at) days_overdue FROM loans l JOIN users u ON u.id=l.borrower_id JOIN equipment e ON e.id=l.equipment_id WHERE l.status='overdue' ORDER BY l.due_at"); $history=rows($pdo,'SELECT l.id,u.full_name,e.name,l.quantity,l.borrowed_at,l.due_at,l.returned_at,l.status,l.condition_before,l.condition_after FROM loans l JOIN users u ON u.id=l.borrower_id JOIN equipment e ON e.id=l.equipment_id ORDER BY l.id DESC LIMIT 500'); ?><div class="d-flex flex-wrap gap-2 mb-3"><button class="btn btn-outline-primary" onclick="window.print()">Print / Save PDF</button><button class="btn btn-outline-secondary" onclick="exportTable('inventory-table','uels-inventory.csv')">Export inventory CSV</button><button class="btn btn-outline-secondary" onclick="exportTable('overdue-table','uels-overdue.csv')">Export overdue CSV</button><button class="btn btn-outline-secondary" onclick="exportTable('history-table','uels-history.csv')">Export transaction history CSV</button></div><div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Inventory report</h2><?php table(['Asset code','Equipment','Category','Total','Available','Borrowed','Condition'],array_map(fn($r)=>['asset_code'=>$r['asset_code'],'name'=>$r['name'],'category'=>$r['category']??'—','total_quantity'=>$r['total_quantity'],'available_quantity'=>$r['available_quantity'],'borrowed'=>$r['borrowed'],'condition_status'=>$r['condition_status']],$inventory),['asset_code','name','category','total_quantity','available_quantity','borrowed','condition_status'],'inventory-table'); ?></div></div><div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Overdue report</h2><?php table(['Loan ID','Borrower','Equipment','Qty','Due date','Days overdue'],array_map(fn($r)=>['id'=>$r['id'],'full_name'=>$r['full_name'],'name'=>$r['name'],'quantity'=>$r['quantity'],'due_at'=>$r['due_at'],'days_overdue'=>$r['days_overdue']],$overdue),['id','full_name','name','quantity','due_at','days_overdue'],'overdue-table'); ?></div></div><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Transaction history (latest 500)</h2><?php table(['Loan ID','Borrower','Equipment','Qty','Borrowed','Due','Returned','Status','Before','After'],array_map(fn($r)=>['id'=>$r['id'],'full_name'=>$r['full_name'],'name'=>$r['name'],'quantity'=>$r['quantity'],'borrowed_at'=>$r['borrowed_at'],'due_at'=>$r['due_at'],'returned_at'=>$r['returned_at']??'—','status'=>$r['status'],'condition_before'=>$r['condition_before'],'condition_after'=>$r['condition_after']??'—'],$history),['id','full_name','name','quantity','borrowed_at','due_at','returned_at','status','condition_before','condition_after'],'history-table'); ?></div></div>
<?php } else { http_response_code(404); echo '<div class="alert alert-warning">Page not found.</div>'; } ?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="assets/app.js"></script></body></html>
<?php
function table(array $headers,array $data,array $keys,bool $responsive=false,string $id=''): void { echo '<div class="table-responsive"><table class="table table-hover align-middle"'.($id?' id="'.e($id).'"':'').'><thead><tr>'; foreach($headers as $h)echo '<th>'.e($h).'</th>'; echo '</tr></thead><tbody>'; if(!$data) echo '<tr><td colspan="'.count($headers).'" class="text-center text-muted py-4">No records found.</td></tr>'; foreach($data as $row){echo '<tr>';foreach($keys as $k)echo '<td>'.e($row[$k]??'—').'</td>';echo '</tr>';} echo '</tbody></table></div>'; }
