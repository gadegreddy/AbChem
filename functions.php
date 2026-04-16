
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// ─── SESSION TIMEOUT ────────────────────────────────────────────────────────
/**
 * Auto-logout if inactive for > $timeoutSeconds.
 * Updates timestamp on every valid request to keep active sessions alive.
 */
function enforceSessionTimeout($timeoutSeconds = 900) {
    if (php_sapi_name() === 'cli') return; // Skip for CLI/batch scripts
    
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return;
    }
    
    if ((time() - $_SESSION['last_activity']) > $timeoutSeconds) {
        // ⏳ Session expired: clear cookies, destroy session, redirect
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        header('Location: signin.php?expired=1');
        exit;
    }
    
    // ✅ Update activity timestamp on every page load/request
    $_SESSION['last_activity'] = time();
}



define('CSV_PATH',     __DIR__ . '/products_master.csv');
define('CACHE_TTL',    3600);
define('USERS_FILE',   __DIR__ . '/users.json');
define('QUERIES_FILE', __DIR__ . '/queries.json');
define('AUDIT_LOG',    __DIR__ . '/audit_log.json');
define('CACHE_FILE',   sys_get_temp_dir() . '/abchem_products_v3.cache');

// Auto-create required files
foreach ([USERS_FILE => '[]', QUERIES_FILE => '[]', AUDIT_LOG => '[]'] as $file => $default) {
    if (!file_exists($file)) {
        @file_put_contents($file, $default, LOCK_EX);
        @chmod($file, 0644);
    }
}

// ─── HELPERS ────────────────────────────────────────────────────────────────
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function sanitize_input($input) { return preg_replace('/[^\w\s\-\.\,\@]/', '', trim($input)); }

// ─── PRODUCTS ────────────────────────────────────────────────────────────────
function getProducts() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cacheFile  = sys_get_temp_dir() . '/abchem_products_v3.cache';
    $csvModTime = file_exists(CSV_PATH) ? filemtime(CSV_PATH) : 0;

    // Invalidate cache if CSV is newer OR if cached column count differs
    $cacheValid = false;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL && filemtime($cacheFile) >= $csvModTime) {
        $cached = @unserialize(file_get_contents($cacheFile));
        // Check column count matches current CSV headers
        if (!empty($cached) && is_array($cached)) {
            $handle  = @fopen(CSV_PATH, 'r');
            $headers = $handle ? array_map('trim', fgetcsv($handle)) : [];
            if ($handle) fclose($handle);
            if (!empty($headers) && count(array_keys($cached[0])) === count($headers)) {
                $cache = $cached;
                return $cache;
            }
            // Column count mismatch — delete stale cache and re-parse
            @unlink($cacheFile);
        }
    }

    if (!file_exists(CSV_PATH)) return [];
    $handle = fopen(CSV_PATH, 'r');
    if (!$handle) return [];

    $headers  = array_map('trim', fgetcsv($handle));
    $colCount = count($headers);
    $products = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rowCount = count($row);
        if ($rowCount === 0) continue;

        if ($rowCount < $colCount) {
            // Pad short rows with empty strings — prevents silent skip
            $row = array_pad($row, $colCount, '');
        } elseif ($rowCount > $colCount) {
            // Trim extra columns
            $row = array_slice($row, 0, $colCount);
        }

        $products[] = array_map('trim', array_combine($headers, $row));
    }
    fclose($handle);

    if (!empty($products)) {
        file_put_contents($cacheFile, serialize($products), LOCK_EX);
    }

    $cache = $products;
    return $cache;
}


function getProductBySlug($slug) {
    foreach (getProducts() as $p) {
        if (($p['slug'] ?? '') === $slug) return $p;
    }
    return null;
}

// Strip admin-only fields before sending to end-user pages
function getPublicProduct($slug) {
    $p = getProductBySlug($slug);
    if (!$p) return null;
    unset($p['Company_make'], $p['Lot_number'], $p['manufacture_date'], $p['expiry_date']);
    return $p;
}

function clearProductCache() {
    if (file_exists(CACHE_FILE)) @unlink(CACHE_FILE);
}

function get_seo_meta($product = null) {
    $site = 'AB Chem India';
    return $product ? [
        'title'       => e($product['product_name']) . ' | CAS ' . e($product['cas_number']) . ' | ' . $site,
        'description' => 'Buy ' . e($product['product_name']) . ' – ' . e($product['purity']) . ' Purity. GMP Grade from Hyderabad.',
        'url'         => 'https://abchem.co.in/product.php?slug=' . e($product['slug'])
    ] : [
        'title'       => 'Pharma Catalog & Chemical Standards | ' . $site,
        'description' => 'High-purity APIs, impurities & standards with CoA. GMP-compliant manufacturing in Hyderabad.',
        'url'         => 'https://abchem.co.in/'
    ];
}

// ─── USERS ───────────────────────────────────────────────────────────────────
function getUsers() {
    if (!file_exists(USERS_FILE)) return [[
        'email'   => 'admin@abchem.co.in',
        'password'=> password_hash('Admin@2026', PASSWORD_DEFAULT),
        'role'    => 'Admin', 'status' => 'Active'
    ]];
    return json_decode(file_get_contents(USERS_FILE), true) ?? [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
}

function checkRole($required) {
    if (!isset($_SESSION['user'])) return false;
    return $_SESSION['role'] === $required || $_SESSION['role'] === 'Admin';
}

// ─── QUERIES / INQUIRIES ─────────────────────────────────────────────────────
function getQueries() {
    if (!file_exists(QUERIES_FILE)) return [];
    return json_decode(file_get_contents(QUERIES_FILE), true) ?? [];
}

function saveQuery($q) {
    $data      = getQueries();
    $q['id']         = uniqid('q_', true);
    $q['status']     = 'New';
    $q['created_at'] = date('Y-m-d H:i:s');
    $data[]    = $q;
    file_put_contents(QUERIES_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Update inquiry status (New → Replied → Closed) with audit trail
 */
function updateQueryStatus($id, $status, $note = '') {
    $queries = getQueries();
    foreach ($queries as &$q) {
        if (($q['id'] ?? '') === $id) {
            $old            = $q['status'] ?? 'New';
            $q['status']    = $status;
            $q['updated_at']= date('Y-m-d H:i:s');
            $q['updated_by']= $_SESSION['user'] ?? 'admin';
            if (!empty($note)) $q['admin_note'] = $note;
            logAudit('inquiry_status_change', "Inquiry #{$id} from {$q['email']}", $old, $status);
            break;
        }
    }
    unset($q);
    file_put_contents(QUERIES_FILE, json_encode($queries, JSON_PRETTY_PRINT), LOCK_EX);
}

// ─── AUDIT TRAIL ─────────────────────────────────────────────────────────────
/**
 * Log any admin action to audit_log.json
 * Schedule M / 21-CFR-Part-11 style: who, what, old value, new value, timestamp, IP
 */
function logAudit($action, $detail, $old = '', $new = '') {
    $log   = file_exists(AUDIT_LOG) ? (json_decode(file_get_contents(AUDIT_LOG), true) ?? []) : [];
    $log[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user'      => $_SESSION['user'] ?? 'system',
        'role'      => $_SESSION['role'] ?? '',
        'action'    => $action,
        'detail'    => $detail,
        'old_value' => $old,
        'new_value' => $new,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80),
    ];
    if (count($log) > 10000) $log = array_slice($log, -10000); // cap file size
    file_put_contents(AUDIT_LOG, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
}

function getAuditLog($limit = 300) {
    if (!file_exists(AUDIT_LOG)) return [];
    $log = json_decode(file_get_contents(AUDIT_LOG), true) ?? [];
    return array_slice(array_reverse($log), 0, $limit);
}

/**
 * Stream audit_log.json as downloadable CSV
 */
function downloadAuditCSV() {
    $log = file_exists(AUDIT_LOG) ? (json_decode(file_get_contents(AUDIT_LOG), true) ?? []) : [];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp','User','Role','Action','Detail','Old Value','New Value','IP']);
    foreach (array_reverse($log) as $r) {
        fputcsv($out, [$r['timestamp'],$r['user'],$r['role'],$r['action'],$r['detail'],$r['old_value'],$r['new_value'],$r['ip']]);
    }
    fclose($out);
    exit;
}

// ─── MONTHLY DATA COMPLETENESS REPORT ────────────────────────────────────────
/**
 * Returns completeness stats for all products.
 * $adminFields are excluded from end-user score but shown in admin report.
 */
function getMonthlyReport() {
    $products      = getProducts();
    $publicFields  = ['cas_number','molecular_formula','molecular_weight','purity','product_type','slug','smiles','inchi_key','synonyms','image_url','iupac_name'];
    $adminFields   = ['Company_make','Lot_number','manufacture_date','expiry_date'];
    $allFields     = array_merge($publicFields, $adminFields);
    $total         = count($products);
    $complete      = 0;
    $fieldMissing  = array_fill_keys($allFields, 0);
    $incompleteList= [];

    foreach ($products as $p) {
        $missing = [];
        foreach ($allFields as $f) {
            if (empty($p[$f])) { $missing[] = $f; $fieldMissing[$f]++; }
        }
        if (empty($missing)) {
            $complete++;
        } else {
            $incompleteList[] = ['name' => $p['product_name'] ?? '', 'cas' => $p['cas_number'] ?? '', 'missing' => $missing];
        }
    }

    return [
        'month'        => date('F Y'),
        'total'        => $total,
        'complete'     => $complete,
        'incomplete'   => count($incompleteList),
        'score'        => $total ? round(($complete / $total) * 100) : 100,
        'field_missing'=> $fieldMissing,
        'items'        => $incompleteList,
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $_SESSION['user'] ?? 'admin',
    ];
}

/**
 * Download monthly report as CSV
 */
function downloadMonthlyReportCSV() {
    $r = getMonthlyReport();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="data_report_' . date('Y_m') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Report Month', $r['month']]);
    fputcsv($out, ['Total Products', $r['total']]);
    fputcsv($out, ['Complete', $r['complete']]);
    fputcsv($out, ['Incomplete', $r['incomplete']]);
    fputcsv($out, ['Completeness Score', $r['score'] . '%']);
    fputcsv($out, ['Generated At', $r['generated_at']]);
    fputcsv($out, []);
    fputcsv($out, ['Product Name', 'CAS Number', 'Missing Fields']);
    foreach ($r['items'] as $i) {
        fputcsv($out, [$i['name'], $i['cas'], implode(', ', $i['missing'])]);
    }
    fclose($out);
    exit;
}

// ─── ZOHO BOOKS INTEGRATION HOOK ─────────────────────────────────────────────
/**
 * Trigger Zoho invoice creation from an inquiry.
 * Requires zoho_helper.php (OAuth2 + API calls).
 * Call this after an inquiry is marked 'Replied' and you want to raise an invoice.
 */
function triggerZohoInvoice($query) {
    if (!file_exists(__DIR__ . '/zoho_helper.php')) return ['error' => 'zoho_helper.php not found'];
    require_once __DIR__ . '/zoho_helper.php';
    return ZohoBooks::createInvoiceFromInquiry($query);
}
