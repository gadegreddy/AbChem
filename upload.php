<?php
require_once 'functions.php';
session_start();
$is_admin = true; // Auth logic here
if (!$is_admin) { http_response_code(403); die("Access denied."); }

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) $err = "Upload failed.";
    elseif ($f['size'] > 10 * 1024 * 1024) $err = "File too large (10MB).";
    else {
        $h = fopen($f['tmp_name'], 'r');
        $headers = array_map('trim', fgetcsv($h));
        fclose($h);
        $required = ['product_name','cas_number','purity','product_type','availability'];
        $missing = array_diff($required, $headers);
        if (!empty($missing)) $err = "Missing: " . implode(', ', $missing);
        elseif (move_uploaded_file($f['tmp_name'], __DIR__ . '/products_master.csv')) {
            unlink(sys_get_temp_dir() . '/abchem_products_v2.cache');
            $msg = "✅ Database updated successfully!";
        } else $err = "Failed to save.";
    }
}
?>
<!-- Simple HTML form for upload -->
<h2>Admin Upload</h2>
<?php if($msg) echo "<p style='color:green'>$msg</p>"; if($err) echo "<p style='color:red'>$err</p>"; ?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="file" required>
    <button type="submit">Upload CSV</button>
</form>