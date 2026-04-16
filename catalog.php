<?php include 'functions.php'; 
$all_products = getProducts();
$unique_types = array_values(array_unique(array_filter(array_column($all_products, 'product_type'))));
sort($unique_types, SORT_NATURAL | SORT_FLAG_CASE);
$selected_types = isset($_GET['type']) ? (array)$_GET['type'] : [];
$selected_sort  = $_GET['sort'] ?? 'default';
$selected_limit = in_array($_GET['per_page'] ?? '12', ['10','20','50','100']) ? $_GET['per_page'] : '12';
?>
<!DOCTYPE html><html lang="en"><head>
   <link rel="icon" type="image/png" href="/logo.png"> 
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chemical Catalog | AB Chem</title>
<link rel="stylesheet" href="styles.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono&display=swap" rel="stylesheet">
</head><body>
<?php include 'header.php'; ?>
<div class="catalog-layout">
<aside class="catalog-sidebar">
    <h3 style="margin:0 0 16px 0; font-size:1.1rem; color:var(--primary);">Filter & Sort</h3>
    <form id="filter-form" method="get" action="catalog">
        <div class="filter-group">
            <label class="filter-label">Product Type</label>
            <?php foreach ($unique_types as $type): ?>
            <label class="cb-row"><input type="checkbox" name="type[]" value="<?= e($type) ?>" <?= in_array($type, $selected_types, true) ? 'checked' : '' ?>><?= e($type) ?></label>
            <?php endforeach; ?>
        </div>
        <div class="filter-group">
            <label class="filter-label" for="sort-select">Sort By</label>
            <select id="sort-select" class="filter-input" name="sort">
                <option value="default" <?= $selected_sort==='default'?'selected':'' ?>>Default</option>
                <option value="name_asc" <?= $selected_sort==='name_asc'?'selected':'' ?>>Name A → Z</option>
                <option value="name_desc" <?= $selected_sort==='name_desc'?'selected':'' ?>>Name Z → A</option>
                <option value="purity_desc" <?= $selected_sort==='purity_desc'?'selected':'' ?>>Purity (High → Low)</option>
                <option value="mw_asc" <?= $selected_sort==='mw_asc'?'selected':'' ?>>Molecular Weight (Low → High)</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label" for="per-page">Items per page</label>
            <select id="per-page" class="filter-input" name="per_page">
                <option value="10" <?= $selected_limit==='10'?'selected':'' ?>>10</option>
                <option value="12" <?= $selected_limit==='12'?'selected':'' ?>>12</option>
                <option value="20" <?= $selected_limit==='20'?'selected':'' ?>>20</option>
                <option value="50" <?= $selected_limit==='50'?'selected':'' ?>>50</option>
                <option value="100" <?= $selected_limit==='100'?'selected':'' ?>>100</option>
            </select>
        </div>
        <div style="display:flex; gap:8px; margin-top:12px;">
            <button type="submit" class="btn btn-primary" style="flex:1;">Apply</button>
            <button type="reset" class="btn btn-outline" style="flex:1; text-align:center;">Clear</button>
        </div>
    </form>
</aside>
<main class="catalog-content">
    <div class="catalog-header">
        <div>
            <h1 style="margin:0;">Pharma & Specialty Chemical Standards</h1>
            <p style="color:var(--muted); margin:8px 0 0; max-width:700px;">
                Explore our GMP-compliant catalog of APIs, isotopes, impurities, and advanced intermediates. 
                Fully characterized with CoA, InChIKey, and canonical SMILES for seamless R&D integration.
            </p>
        </div>
        <div style="text-align:right;">
            <span id="result-range" style="font-weight:600; color:var(--accent);">Results 0 of 0</span>
            <span id="result-count" style="color:var(--muted); margin-left:8px;">Loading...</span>
        </div>
    </div>
    <div id="product-grid" class="grid"></div>
    <nav id="pagination" class="pagination"></nav>
</main>
</div>
<?php include 'footer.php'; ?>
<script src="include.js" defer></script>
</body></html>