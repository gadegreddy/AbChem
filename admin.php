<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'functions.php';

// Enforce 15-minute session timeout
enforceSessionTimeout(900);

// ── Gate: Admins only ────────────────────────────────────────────────────────
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    header('Location: signin.php'); exit;
}

// ── Download actions (must run before any HTML output) ───────────────────────
if (isset($_GET['download'])) {
    if ($_GET['download'] === 'audit')  downloadAuditCSV();
    if ($_GET['download'] === 'report') downloadMonthlyReportCSV();
}

$tab  = $_GET['tab'] ?? 'users';
$msg  = '';
$users   = getUsers();
$queries = getQueries();

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Update existing user ──────────────────────────────────────────────────
    if (isset($_POST['update_user'])) {
        $email  = $_POST['email'];
        $role   = $_POST['role'];
        $status = $_POST['status'];
        $pwd    = $_POST['password'];
        foreach ($users as &$u) {
            if ($u['email'] === $email) {
                $oldStatus = $u['status'];
                $u['role'] = $role; $u['status'] = $status;
                if (!empty($pwd)) $u['password'] = password_hash($pwd, PASSWORD_DEFAULT);
                // Approval workflow log
                if ($oldStatus === 'Pending' && $status === 'Active') {
                    $u['approved_by'] = $_SESSION['user'];
                    $u['approved_at'] = date('Y-m-d H:i:s');
                    logAudit('user_approved', "User $email approved", 'Pending', 'Active');
                } elseif ($oldStatus !== $status) {
                    logAudit('user_status_change', "User $email", $oldStatus, $status);
                }
                if ($role !== ($u['role'] ?? '')) logAudit('user_role_change', "User $email", $u['role'], $role);
            }
        }
        unset($u);
        saveUsers($users);
        $msg = 'User updated.';
    }

    // ── Add new user ──────────────────────────────────────────────────────────
    if (isset($_POST['add_user'])) {
        $newEmail = $_POST['email'];
        $exists   = false;
        foreach ($users as $u) { if ($u['email'] === $newEmail) $exists = true; }
        if (!$exists) {
            $users[] = [
                'email'      => $newEmail,
                'password'   => password_hash($_POST['password'], PASSWORD_DEFAULT),
                'role'       => $_POST['role'],
                'status'     => 'Active',
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $_SESSION['user'],
            ];
            saveUsers($users);
            logAudit('user_created', "New user $newEmail with role {$_POST['role']}");
            $msg = 'User created.';
        } else {
            $msg = 'Email already exists.';
        }
    }

    // ── Inquiry status update ─────────────────────────────────────────────────
    if (isset($_POST['update_inquiry_status'])) {
        updateQueryStatus($_POST['inquiry_id'], $_POST['inquiry_status'], $_POST['admin_note'] ?? '');
        $queries = getQueries(); // reload
        $msg = 'Inquiry updated.';
    }

    // ── CSV upload ────────────────────────────────────────────────────────────
    if (isset($_POST['upload_csv']) && isset($_FILES['csv']) && $_FILES['csv']['error'] === 0) {
        move_uploaded_file($_FILES['csv']['tmp_name'], 'products_master.csv');
        clearProductCache();
        logAudit('csv_upload', 'products_master.csv replaced via admin panel');
        $msg = '✅ CSV updated & cache cleared.';
    }

    // ── PubChem lazy fetch ────────────────────────────────────────────────────
    if (isset($_POST['pubchem_fetch']) && !empty($_POST['fetch_slug'])) {
        require_once 'pubchem_fetch.php';
        $fetcher = new PubChemFetcher();
        $result  = $fetcher->lazyFetchProduct(preg_replace('/[^\w\-]/', '', $_POST['fetch_slug']));
        clearProductCache();
        logAudit('pubchem_fetch', "Fetched slug: {$_POST['fetch_slug']}", '', $result['status'] ?? 'error');
        $msg = isset($result['error']) ? '❌ ' . $result['error'] : '✅ PubChem data saved for: ' . e($result['product']['product_name'] ?? '');
    }

    // ── Zoho invoice trigger ──────────────────────────────────────────────────
    if (isset($_POST['raise_invoice'])) {
        $qid    = $_POST['inquiry_id'];
        $target = null;
        foreach ($queries as $q) { if (($q['id'] ?? '') === $qid) { $target = $q; break; } }
        if ($target) {
            $res = triggerZohoInvoice($target);
            $msg = isset($res['error']) ? '❌ Zoho: ' . e($res['error']) : '✅ Invoice raised: ' . e($res['invoice_number'] ?? '');
            logAudit('zoho_invoice', "Invoice for inquiry {$qid}", '', $msg);
        }
    }
}

// Status badge helper
function statusBadge($s) {
    $map = ['New'=>'#3b82f6','Replied'=>'#f59e0b','Closed'=>'#22c55e','Active'=>'#22c55e','Inactive'=>'#ef4444','Pending'=>'#f59e0b'];
    $c   = $map[$s] ?? '#64748b';
    return "<span style='background:{$c}22;color:{$c};padding:2px 9px;border-radius:12px;font-size:0.78rem;font-weight:500;'>$s</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Admin | AB Chem India</title>
<link rel="stylesheet" href="styles.css">
<style>
  .admin-table { width:100%; border-collapse:collapse; font-size:0.875rem; }
  .admin-table th { background:#f8fafc; padding:9px 10px; text-align:left; font-weight:500; color:#475569; border-bottom:2px solid #e2e8f0; }
  .admin-table td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
  .admin-table tr:hover td { background:#f8fafc; }
  .pill { display:inline-block; padding:2px 10px; border-radius:20px; font-size:0.75rem; font-weight:500; }
  .pill-red  { background:#fee2e2; color:#b91c1c; }
  .pill-amber{ background:#fef3c7; color:#92400e; }
  .pill-green{ background:#dcfce7; color:#15803d; }
  .progress  { height:6px; background:#e2e8f0; border-radius:4px; width:80px; display:inline-block; vertical-align:middle; }
  .progress-fill { height:6px; border-radius:4px; }
  .score-red  { color:#dc2626; }
  .score-amber{ color:#f59e0b; }
  .score-green{ color:#22c55e; }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main style="max-width:1280px; margin:32px auto; padding:0 16px;">

  <!-- Tab bar -->
  <div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; align-items:center;">
    <?php
    $tabs = [
      'users'   => 'Users & Roles',
      'queries' => 'Inquiries',
      'data'    => 'CSV & Images',
      'audit'   => 'Data Audit',
      'trail'   => 'Audit Trail',
      'pubchem' => 'PubChem',
      'reports' => 'Reports',
      'invoice' => 'Invoices',
    ];
    foreach ($tabs as $k => $v):
    ?>
      <a href="?tab=<?=$k?>" class="btn <?= $tab===$k ? 'btn-primary' : 'btn-outline' ?>" style="font-size:0.85rem;"><?=$v?></a>
    <?php endforeach; ?>
    <a href="logout" class="btn btn-outline" style="margin-left:auto; font-size:0.85rem;">Logout</a>
  </div>

  <?php if ($msg): ?>
  <div style="background:var(--success,#22c55e); color:#fff; padding:10px 16px; border-radius:6px; margin-bottom:16px;"><?=$msg?></div>
  <?php endif; ?>

  <div style="background:#fff; padding:24px; border-radius:8px; border:1px solid #e2e8f0;">

  <?php /* ═══════════════════════════════ USERS ══════════════════════════════ */ ?>
  <?php if ($tab === 'users'): ?>
    <h3 style="margin:0 0 16px;">User Management</h3>

    <?php $pendingCount = count(array_filter($users, fn($u)=>($u['status']??'')===('Pending'))); ?>
    <?php if ($pendingCount): ?>
    <div style="background:#fef3c7; border-left:4px solid #f59e0b; padding:10px 14px; border-radius:4px; margin-bottom:16px; font-size:0.875rem;">
      ⏳ <strong><?=$pendingCount?> user(s)</strong> are pending approval.
    </div>
    <?php endif; ?>

    <table class="admin-table">
      <tr><th>Email</th><th>Company</th><th>Role</th><th>Status</th><th>Approved By</th><th>Joined</th><th>Actions</th></tr>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?=e($u['email'])?></td>
        <td><?=e($u['company_name']??'—')?></td>
        <td><?=e($u['role'])?></td>
        <td><?=statusBadge($u['status']??'Active')?></td>
        <td style="font-size:0.8rem; color:#64748b;"><?=e($u['approved_by']??'—')?></td>
        <td style="font-size:0.8rem; color:#64748b;"><?=e(substr($u['created_at']??'',0,10))?></td>
        <td>
          <form method="post" style="display:flex; gap:4px; flex-wrap:wrap;">
            <input type="hidden" name="email" value="<?=e($u['email'])?>">
            <select name="role" class="filter-input" style="width:auto; font-size:0.8rem;">
              <?php foreach(['Admin','Buyer','Vendor','EndUser','User'] as $r): ?>
              <option <?=$u['role']===$r?'selected':''?>><?=$r?></option>
              <?php endforeach; ?>
            </select>
            <select name="status" class="filter-input" style="width:auto; font-size:0.8rem;">
              <?php foreach(['Active','Inactive','Pending'] as $s): ?>
              <option <?=($u['status']??'')===$s?'selected':''?>><?=$s?></option>
              <?php endforeach; ?>
            </select>
            <input type="password" name="password" placeholder="New pass" style="width:90px; font-size:0.8rem;" class="filter-input">
            <button type="submit" name="update_user" class="btn btn-outline btn-sm">Save</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <h4 style="margin:24px 0 12px;">Add New User</h4>
    <form method="post" style="display:flex; gap:8px; flex-wrap:wrap;">
      <input type="email"    name="email"    placeholder="Email"    required class="filter-input">
      <input type="password" name="password" placeholder="Password" required class="filter-input">
      <select name="role" class="filter-input" style="width:auto;">
        <option>Buyer</option><option>Vendor</option><option>EndUser</option><option>Admin</option>
      </select>
      <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
    </form>

  <?php /* ═══════════════════════════════ INQUIRIES ══════════════════════════ */ ?>
  <?php elseif ($tab === 'queries'):
    $filterStatus = $_GET['qs'] ?? 'all';
    $statusCounts = ['New'=>0,'Replied'=>0,'Closed'=>0];
    foreach ($queries as $q) { $s=$q['status']??'New'; if(isset($statusCounts[$s])) $statusCounts[$s]++; }
  ?>
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
      <h3 style="margin:0;">Inquiries (<?=count($queries)?>)</h3>
      <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <?php foreach(['all'=>'All'] + $statusCounts as $k=>$cnt): ?>
        <a href="?tab=queries&qs=<?=$k?>" class="btn <?=$filterStatus===$k?'btn-primary':'btn-outline'?> btn-sm">
          <?=$k==='all'?'All':$k?> <?=$k!=='all'?"($cnt)":''?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="overflow-x:auto;">
    <table class="admin-table">
      <tr><th>Date</th><th>Name</th><th>Email</th><th>Type</th><th>Message</th><th>Status</th><th>Actions</th></tr>
      <?php foreach (array_reverse($queries) as $q):
        if ($filterStatus !== 'all' && ($q['status']??'New') !== $filterStatus) continue;
      ?>
      <tr>
        <td style="white-space:nowrap; font-size:0.8rem;"><?=e(substr($q['created_at']??$q['date']??'',0,10))?></td>
        <td><?=e($q['name']??'')?></td>
        <td style="font-size:0.8rem;"><?=e($q['email']??'')?></td>
        <td><?=e($q['type']??'')?></td>
        <td style="max-width:220px; font-size:0.8rem;"><?=e(substr($q['message']??'',0,120))?><?= strlen($q['message']??'')>120?'…':''?></td>
        <td><?=statusBadge($q['status']??'New')?></td>
        <td>
          <form method="post" style="display:flex; flex-direction:column; gap:4px; min-width:200px;">
            <input type="hidden" name="inquiry_id" value="<?=e($q['id']??'')?>">
            <select name="inquiry_status" class="filter-input" style="font-size:0.8rem;">
              <?php foreach(['New','Replied','Closed'] as $s): ?>
              <option <?=($q['status']??'New')===$s?'selected':''?>><?=$s?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="admin_note" placeholder="Note (optional)" class="filter-input" style="font-size:0.8rem;" value="<?=e($q['admin_note']??'')?>">
            <div style="display:flex; gap:4px;">
              <button type="submit" name="update_inquiry_status" class="btn btn-outline btn-sm">Update</button>
              <button type="submit" name="raise_invoice" class="btn btn-primary btn-sm" title="Raise Zoho Invoice">Invoice</button>
            </div>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php /* ═══════════════════════════════ DATA / CSV ════════════════════════ */ ?>
  <?php elseif ($tab === 'data'): ?>
    <h3 style="margin:0 0 16px;">CSV & Image Management</h3>
    <p style="color:#64748b; font-size:0.875rem;">
      CSV columns expected: <code>slug, Company_make, cas_number, smiles, inchi, inchi_key, iupac_name, molecular_formula, molecular_weight, purity, product_type, availability, product_name, image_url, lead_time, pubchem_cid, synonyms, Lot_number, manufacture_date, expiry_date</code>
    </p>
    <p style="color:#64748b; font-size:0.875rem; margin-top:6px;">
      <strong>Note:</strong> <code>Company_make</code>, <code>Lot_number</code>, <code>manufacture_date</code>, <code>expiry_date</code> are <em>admin-only</em> and never shown to end users.
    </p>
    <form method="post" enctype="multipart/form-data" style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <input type="file" name="csv" accept=".csv" required class="filter-input" style="display:inline;">
      <button type="submit" name="upload_csv" class="btn btn-primary">Upload & Clear Cache</button>
    </form>
    <hr style="margin:24px 0; border-color:#e2e8f0;">
    <h4>Compound Images</h4>
    <p style="font-size:0.875rem; color:#64748b;">Upload images via cPanel/FTP to <code>/public_html/compound_images/</code>.<br>
    Filenames should match the product slug, e.g. <code>atorvastatin-impurity-d.png</code>.<br>
    Then set <code>image_url</code> in CSV as <code>/compound_images/atorvastatin-impurity-d.png</code>.</p>

  <?php /* ═══════════════════════════════ DATA AUDIT ══════════════════════════ */ ?>
  <?php elseif ($tab === 'audit'):
    $products = getProducts();
    // Admin-visible fields (all fields)
    $criticalFields  = ['cas_number','molecular_formula','molecular_weight','purity','product_type','slug','smiles','inchi_key'];
    $recFields       = ['image_url','synonyms','iupac_name','availability','pubchem_cid'];
    $adminFields     = ['Company_make','Lot_number','manufacture_date','expiry_date'];
    $allFields       = array_merge($criticalFields, $recFields, $adminFields);

    $issues = [];
    foreach ($products as $p) {
        $missing = array_filter($criticalFields, fn($f) => empty($p[$f]));
        $warn    = array_filter($recFields,      fn($f) => empty($p[$f]));
        $admin   = array_filter($adminFields,    fn($f) => empty($p[$f]));
        $total   = count($allFields);
        $score   = round((($total - count($missing) - count($warn) - count($admin)) / $total) * 100);
        if (!empty($missing) || !empty($warn) || !empty($admin)) {
            $issues[] = ['name'=>$p['product_name']??'','cas'=>$p['cas_number']??'','slug'=>$p['slug']??'',
                         'missing'=>array_values($missing),'warn'=>array_values($warn),'admin'=>array_values($admin),'score'=>$score];
        }
    }
    usort($issues, fn($a,$b) => $a['score'] - $b['score']);

    $missingCritical = count(array_filter($issues, fn($i)=>!empty($i['missing'])));
    $missingImage    = count(array_filter($issues, fn($i)=>in_array('image_url',$i['warn'])));
    $missingAdmin    = count(array_filter($issues, fn($i)=>!empty($i['admin'])));
    $filterBy = $_GET['af'] ?? '';
    if ($filterBy === 'critical') $issues = array_filter($issues, fn($i)=>!empty($i['missing']));
    if ($filterBy === 'image')    $issues = array_filter($issues, fn($i)=>in_array('image_url',$i['warn']));
    if ($filterBy === 'admin')    $issues = array_filter($issues, fn($i)=>!empty($i['admin']));
    if ($filterBy === 'smiles')   $issues = array_filter($issues, fn($i)=>in_array('smiles',$i['missing']));
  ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
      <h3 style="margin:0;">Data Audit — Catalog Completeness</h3>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:18px;">
      <?php foreach([
        ['Total', count($products), ''],
        ['Missing Critical', $missingCritical, 'color:#dc2626'],
        ['No Image', $missingImage, 'color:#f59e0b'],
        ['Missing Admin Fields', $missingAdmin, 'color:#7c3aed'],
        ['Issues Total', count($issues), ''],
      ] as [$lbl,$val,$style]): ?>
      <div style="background:#f8fafc; padding:14px; border-radius:8px; text-align:center;">
        <div style="font-size:1.5rem; font-weight:600; <?=$style?>"><?=$val?></div>
        <div style="font-size:0.75rem; color:#64748b; margin-top:4px;"><?=$lbl?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex; gap:6px; margin-bottom:12px; flex-wrap:wrap;">
      <?php foreach([''=>'All Issues','critical'=>'Missing CAS/Formula/etc','image'=>'No Image','smiles'=>'No SMILES','admin'=>'Missing Admin Fields'] as $k=>$v): ?>
      <a href="?tab=audit&af=<?=$k?>" class="btn <?=$filterBy===$k?'btn-primary':'btn-outline'?> btn-sm"><?=$v?></a>
      <?php endforeach; ?>
    </div>

    <div style="overflow-x:auto;">
    <table class="admin-table">
      <tr><th>Product</th><th>CAS</th><th>Missing (critical)</th><th>Missing (recommended)</th><th style="color:#7c3aed;">Missing (admin-only)</th><th>Score</th><th>PubChem</th></tr>
      <?php foreach ($issues as $i):
        $sc = $i['score'];
        $scClass = $sc >= 85 ? 'score-green' : ($sc >= 60 ? 'score-amber' : 'score-red');
        $barColor= $sc >= 85 ? '#22c55e' : ($sc >= 60 ? '#f59e0b' : '#ef4444');
      ?>
      <tr>
        <td><?=e($i['name'])?></td>
        <td><?= $i['cas'] ? e($i['cas']) : '<span style="color:#dc2626">—</span>'?></td>
        <td><?php foreach($i['missing'] as $m): ?>
          <span class="pill pill-red"><?=e(str_replace('_',' ',$m))?></span>
        <?php endforeach; ?></td>
        <td><?php foreach($i['warn'] as $w): ?>
          <span class="pill pill-amber"><?=e(str_replace('_',' ',$w))?></span>
        <?php endforeach; ?></td>
        <td><?php foreach($i['admin'] as $a): ?>
          <span class="pill" style="background:#ede9fe;color:#5b21b6;"><?=e(str_replace('_',' ',$a))?></span>
        <?php endforeach; ?></td>
        <td>
          <div class="progress"><div class="progress-fill" style="width:<?=$sc?>%;background:<?=$barColor?>;"></div></div>
          <span class="<?=$scClass?>" style="font-size:0.8rem; margin-left:6px;"><?=$sc?>%</span>
        </td>
        <td>
          <?php if (!empty($i['slug'])): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="fetch_slug" value="<?=e($i['slug'])?>">
            <button type="submit" name="pubchem_fetch" class="btn btn-outline btn-sm" title="Fetch from PubChem">⚗ Fetch</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php /* ═══════════════════════════════ AUDIT TRAIL ═══════════════════════ */ ?>
  <?php elseif ($tab === 'trail'):
    $logs = getAuditLog(500);
  ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
      <h3 style="margin:0;">Audit Trail (<?=count($logs)?> recent entries)</h3>
      <a href="?tab=trail&download=audit" class="btn btn-outline">⬇ Download CSV</a>
    </div>
    <div style="overflow-x:auto;">
    <table class="admin-table">
      <tr><th>Timestamp</th><th>User</th><th>Action</th><th>Detail</th><th>Old</th><th>New</th><th>IP</th></tr>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td style="white-space:nowrap; font-size:0.78rem;"><?=e($l['timestamp'])?></td>
        <td style="font-size:0.8rem;"><?=e($l['user'])?></td>
        <td><span class="pill" style="background:#eff6ff;color:#1e40af;"><?=e($l['action'])?></span></td>
        <td style="font-size:0.8rem; max-width:200px;"><?=e($l['detail'])?></td>
        <td style="font-size:0.78rem; color:#64748b; max-width:100px;"><?=e($l['old_value'])?></td>
        <td style="font-size:0.78rem; color:#22c55e; max-width:100px;"><?=e($l['new_value'])?></td>
        <td style="font-size:0.75rem; color:#94a3b8;"><?=e($l['ip'])?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php /* ═══════════════════════════════ PUBCHEM ════════════════════════════ */ ?>
  <?php elseif ($tab === 'pubchem'): ?>
    <h3 style="margin:0 0 16px;">PubChem Data Fetcher</h3>
    <p style="font-size:0.875rem; color:#64748b; margin-bottom:16px;">
      Fetches SMILES, InChI, InChIKey, synonyms, molecular weight, IUPAC name from PubChem and saves them back to the CSV.<br>
      <strong>Lazy fetch</strong> = one product at a time. Go to <a href="pubchem_fetch" target="_blank">pubchem_fetch.php</a> for batch operations.
    </p>
    <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px;">
      <input type="text" name="fetch_slug" placeholder="Product slug (e.g. atorvastatin-123)" required class="filter-input" style="width:300px;">
      <button type="submit" name="pubchem_fetch" class="btn btn-primary">⚗ Fetch from PubChem</button>
    </form>
    <div style="background:#f8fafc; border-radius:8px; padding:16px; font-size:0.875rem; color:#64748b;">
      <strong>Fields updated from PubChem:</strong> smiles, inchi, inchi_key, iupac_name, molecular_formula, molecular_weight, pubchem_cid, synonyms, image_url (via OPSIN)<br>
      <strong>Fields preserved (not overwritten):</strong> Company_make, Lot_number, manufacture_date, expiry_date, purity, product_type, availability, lead_time<br>
      <a href="pubchem_fetch" target="_blank" class="btn btn-outline btn-sm" style="margin-top:10px; display:inline-block;">Open Full PubChem Fetcher →</a>
    </div>

  <?php /* ═══════════════════════════════ REPORTS ════════════════════════════ */ ?>
  <?php elseif ($tab === 'reports'):
    $report = getMonthlyReport();
  ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
      <h3 style="margin:0;">Monthly Data Report — <?=e($report['month'])?></h3>
      <a href="?tab=reports&download=report" class="btn btn-outline">⬇ Download CSV Report</a>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:20px;">
      <?php foreach([
        ['Total Products', $report['total'], ''],
        ['Complete', $report['complete'], 'color:#22c55e'],
        ['Incomplete', $report['incomplete'], 'color:#ef4444'],
        ['Score', $report['score'].'%', $report['score']>=85?'color:#22c55e':($report['score']>=60?'color:#f59e0b':'color:#ef4444')],
      ] as [$l,$v,$s]): ?>
      <div style="background:#f8fafc; padding:14px; border-radius:8px; text-align:center;">
        <div style="font-size:1.5rem; font-weight:600; <?=$s?>"><?=$v?></div>
        <div style="font-size:0.75rem; color:#64748b; margin-top:4px;"><?=$l?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <h4 style="margin:0 0 10px;">Field-by-field gaps</h4>
    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:8px; margin-bottom:20px;">
      <?php foreach ($report['field_missing'] as $field => $count):
        $pct = $report['total'] ? round(($count/$report['total'])*100) : 0;
        $col = $pct > 20 ? '#ef4444' : ($pct > 5 ? '#f59e0b' : '#22c55e');
      ?>
      <div style="background:#f8fafc; padding:10px 14px; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:0.8rem;"><?=e(str_replace('_',' ',$field))?></span>
        <span style="font-size:0.85rem; font-weight:600; color:<?=$col?>;"><?=$count?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <h4 style="margin:0 0 10px;">Incomplete products (top 50)</h4>
    <div style="overflow-x:auto;">
    <table class="admin-table">
      <tr><th>Product</th><th>CAS</th><th>Missing Fields</th></tr>
      <?php foreach (array_slice($report['items'], 0, 50) as $i): ?>
      <tr>
        <td><?=e($i['name'])?></td>
        <td><?=e($i['cas'])?></td>
        <td style="font-size:0.8rem; color:#64748b;"><?=e(implode(', ', array_map(fn($f)=>str_replace('_',' ',$f), $i['missing'])))?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <p style="font-size:0.8rem; color:#94a3b8; margin-top:8px;">Generated at <?=e($report['generated_at'])?></p>

  <?php /* ═══════════════════════════════ INVOICES (ZOHO) ═══════════════════ */ ?>
  <?php elseif ($tab === 'invoice'): ?>
    <h3 style="margin:0 0 8px;">Invoice Management — Zoho Books</h3>
    <p style="font-size:0.875rem; color:#64748b; margin-bottom:16px;">
      Raise invoices directly from inquiries using Zoho Books API.<br>
      Configure your Zoho credentials in <code>zoho_helper.php</code> to activate. SaaS alternatives (Razorpay, Tally) can also be plugged in there.
    </p>

    <?php
    $zohoReady = file_exists(__DIR__ . '/zoho_helper.php');
    $zohoToken = file_exists(__DIR__ . '/zoho_token.json');
    ?>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:20px;">
      <div style="background:#f8fafc; padding:14px; border-radius:8px;">
        <div style="font-weight:500; margin-bottom:4px;">zoho_helper.php</div>
        <div style="font-size:0.85rem;"><?=$zohoReady?'<span style="color:#22c55e;">✅ Present</span>':'<span style="color:#ef4444;">❌ Missing</span>'?></div>
      </div>
      <div style="background:#f8fafc; padding:14px; border-radius:8px;">
        <div style="font-weight:500; margin-bottom:4px;">OAuth Token</div>
        <div style="font-size:0.85rem;"><?=$zohoToken?'<span style="color:#22c55e;">✅ Present</span>':'<span style="color:#f59e0b;">⚠ Not connected</span>'?></div>
      </div>
    </div>

    <?php if (!$zohoReady): ?>
    <div style="background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 16px; border-radius:4px; font-size:0.875rem;">
      <strong>Setup steps:</strong>
      <ol style="margin:8px 0 0 18px; padding:0; line-height:1.8;">
        <li>Upload <code>zoho_helper.php</code> (provided separately) to <code>/public_html/</code></li>
        <li>Add your Zoho Client ID, Client Secret, and Organisation ID in <code>zoho_helper.php</code></li>
        <li>Visit <code>/zoho_helper.php?connect=1</code> to complete OAuth and save token</li>
        <li>Return here — the "Invoice" button on inquiries will be live</li>
      </ol>
    </div>
    <?php endif; ?>

    <h4 style="margin:20px 0 10px;">Recent Invoices from Audit Log</h4>
    <div style="overflow-x:auto;">
    <table class="admin-table">
      <tr><th>Timestamp</th><th>Raised By</th><th>Detail</th><th>Result</th></tr>
      <?php
      $invoiceLogs = array_filter(getAuditLog(200), fn($l)=>$l['action']==='zoho_invoice');
      foreach ($invoiceLogs as $l): ?>
      <tr>
        <td style="font-size:0.8rem; white-space:nowrap;"><?=e($l['timestamp'])?></td>
        <td><?=e($l['user'])?></td>
        <td style="font-size:0.8rem;"><?=e($l['detail'])?></td>
        <td style="font-size:0.8rem;"><?=e($l['new_value'])?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php endif; ?>
  </div><!-- /card -->
</main>
<?php include 'footer.php'; ?>
</body>
</html>
