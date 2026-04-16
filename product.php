<?php
require_once 'functions.php';

// ── Force clear stale cache if CSV is newer ──────────────────────────────────
// Handles the case where column count changed after CSV upload
$cacheFile  = sys_get_temp_dir() . '/abchem_products_v3.cache';
$csvModTime = file_exists(CSV_PATH) ? filemtime(CSV_PATH) : 0;
if (file_exists($cacheFile) && filemtime($cacheFile) < $csvModTime) {
    @unlink($cacheFile);
}

$slug = $_GET['slug'] ?? '';
$p    = getPublicProduct($slug);
if (!$p) { http_response_code(404); include '404.php'; exit; }
$meta = get_seo_meta($p);

// ── Synonyms ─────────────────────────────────────────────────────────────────
$synonyms = [];
$rawSyn   = $p['synonyms'] ?? '';
if (!empty($rawSyn) && $rawSyn !== 'NA') {
    $synonyms = preg_split('/[,;|]+/', $rawSyn, -1, PREG_SPLIT_NO_EMPTY);
    $synonyms = array_slice(array_filter(array_map('trim', $synonyms)), 0, 8);
}
if (empty($synonyms) && !empty($p['iupac_name'])) $synonyms[] = $p['iupac_name'];

// ── Related products ──────────────────────────────────────────────────────────
$all     = getProducts();
$related = array_values(array_filter($all, fn($x) =>
    ($x['product_type'] ?? '') === ($p['product_type'] ?? '') && $x['slug'] !== $slug
));
$related = array_slice($related, 0, 4);

// ── Image fallback helper ─────────────────────────────────────────────────────
function productImg($url, $name, $size = '100%') {
    $fallback = "this.src='/logo.png'; this.style.objectFit='contain'; this.style.padding='16px'; this.style.opacity='0.45';";
    if (!empty($url) && $url !== 'NA') {
        return "<img src='" . e($url) . "' alt='" . e($name) . "' style='width:{$size}; height:100%; object-fit:contain;' loading='lazy' onerror=\"{$fallback}\">";
    }
    return "<img src='/logo.png' alt='".e($name)."' style='width:{$size}; object-fit:contain; padding:16px; opacity:0.45;'>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=e($meta['title'])?></title>
    <meta name="description" content="<?=e($meta['description'])?>">
    <link rel="canonical" href="https://www.abchem.co.in/product/<?=e($slug)?>">
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">

    <!-- Structured Data (Google) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Product",
      "name": "<?=e($p['product_name'])?>",
      "description": "<?=e($p['iupac_name'] ?? $p['product_name'])?>",
      "sku": "<?=e($p['cas_number'] ?? $slug)?>",
      "brand": { "@type": "Brand", "name": "AB Chem India" },
      "offers": {
        "@type": "Offer",
        "availability": "<?= strtolower($p['availability']??'')==='in stock' ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder' ?>",
        "priceCurrency": "INR",
        "seller": { "@type": "Organization", "name": "AB Chem India" }
      }
    }
    </script>
</head>
<body>
<?php include 'header.php'; ?>

<main style="max-width:1100px; margin:30px auto; padding:0 16px;">

    <!-- Breadcrumb -->
    <nav style="font-size:0.82rem; color:var(--muted); margin-bottom:16px;">
        <a href="/" style="color:var(--accent);">Home</a> ›
        <a href="/catalog" style="color:var(--accent);">Catalog</a> ›
        <?=e($p['product_name'])?>
    </nav>

    <!-- ── Main product card ─────────────────────────────────────────────── -->
    <div style="background:#fff; padding:28px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:24px;">
        <div style="display:grid; grid-template-columns:280px 1fr; gap:32px;">

            <!-- Image -->
            <div style="background:#f8fafc; border-radius:8px; min-height:280px; display:flex; align-items:center; justify-content:center; overflow:hidden; border:1px solid #e2e8f0;">
                <?= productImg($p['image_url'] ?? '', $p['product_name'], '100%') ?>
            </div>

            <!-- Details -->
            <div>
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <h1 style="color:var(--primary); margin:0 0 6px; font-size:1.5rem;"><?=e($p['product_name'])?></h1>
                    <span class="availability-badge <?= strtolower($p['availability']??'')==='in stock' ? 'in-stock' : 'backorder' ?>" style="flex-shrink:0;">
                        <?=e($p['availability'] ?? 'Contact Us')?>
                    </span>
                </div>

                <p style="color:var(--muted); font-family:monospace; font-size:0.875rem; margin-bottom:20px;">
                    MF: <?=e($p['molecular_formula'] ?? 'N/A')?> &nbsp;|&nbsp; MW: <?=e($p['molecular_weight'] ?? 'N/A')?>
                </p>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; margin-bottom:20px; font-size:0.9rem;">
                    <div><span style="color:var(--muted);">CAS</span><br><strong><?=e($p['cas_number'] ?? 'N/A')?></strong></div>
                    <div><span style="color:var(--muted);">Purity</span><br><strong><?=e($p['purity'] ?? 'N/A')?></strong></div>
                    <div><span style="color:var(--muted);">Type</span><br><strong><?=e($p['product_type'] ?? 'N/A')?></strong></div>
                    <div><span style="color:var(--muted);">Lead Time</span><br><strong><?=e($p['lead_time'] ?? 'On Request')?></strong></div>
                </div>

                <?php if (!empty($p['iupac_name']) && $p['iupac_name'] !== 'NA'): ?>
                <div style="margin-bottom:18px; font-size:0.875rem;">
                    <span style="color:var(--muted);">IUPAC Name</span><br>
                    <span><?=e($p['iupac_name'])?></span>
                </div>
                <?php endif; ?>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;">
                    <a href="/contact?subject=<?=urlencode($p['product_name'])?>&cas=<?=urlencode($p['cas_number']??'')?>"
                       class="btn btn-primary">Request Quote</a>
                    <?php if (!empty($p['pubchem_cid'])): ?>
                    <a href="https://pubchem.ncbi.nlm.nih.gov/compound/<?=e($p['pubchem_cid'])?>"
                       target="_blank" rel="noopener" class="btn btn-outline">View on PubChem →</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Synonyms ──────────────────────────────────────────────────────── -->
    <?php if (!empty($synonyms)): ?>
    <div style="background:#fff; padding:20px 28px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:24px;">
        <h3 style="color:var(--primary); margin:0 0 12px; font-size:1rem;">Synonyms</h3>
        <div style="display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach ($synonyms as $syn): ?>
            <span style="background:#e0f2fe; color:#0369a1; padding:5px 12px; border-radius:20px; font-size:0.82rem;">
                <?=e($syn)?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Chemical Identifiers ───────────────────────────────────────────── -->
    <?php if (!empty($p['smiles']) || !empty($p['inchi']) || !empty($p['inchi_key'])): ?>
    <div style="background:#fff; padding:20px 28px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:24px;">
        <h3 style="color:var(--primary); margin:0 0 16px; font-size:1rem;">Chemical Identifiers</h3>

        <?php
        $identifiers = [
            'SMILES'    => $p['smiles']     ?? '',
            'InChI'     => $p['inchi']      ?? '',
            'InChIKey'  => $p['inchi_key']  ?? '',
            'PubChem CID' => $p['pubchem_cid'] ?? '',
        ];
        foreach ($identifiers as $label => $val):
            if (empty($val) || $val === 'NA') continue;
        ?>
        <div style="margin-bottom:14px;">
            <label style="font-size:0.72rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:4px;"><?=e($label)?></label>
            <code style="display:block; padding:8px 12px; background:#f8fafc; border-radius:6px; font-size:0.82rem; word-break:break-all; border:1px solid #e2e8f0;"><?=e($val)?></code>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Related Products ───────────────────────────────────────────────── -->
    <?php if (!empty($related)): ?>
    <div style="margin-top:8px;">
        <h3 style="color:var(--primary); margin-bottom:16px;">Related Products</h3>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px;">
        <?php foreach ($related as $r): ?>
        <article class="card">
            <div class="card-image-container">
                <?= productImg($r['image_url'] ?? '', $r['product_name']) ?>
                <span class="availability-badge <?= strtolower($r['availability']??'')==='in stock' ? 'in-stock' : 'backorder' ?>">
                    <?=e($r['availability'] ?? '')?>
                </span>
            </div>
            <div class="card-body">
                <h3 class="card-title">
                    <a href="/product/<?=e($r['slug'])?>"><?=e($r['product_name'])?></a>
                </h3>
                <div class="card-meta" style="margin-top:8px;">
                    <div><span class="meta-label">CAS</span><span class="meta-value"><?=e($r['cas_number'] ?? 'N/A')?></span></div>
                    <div><span class="meta-label">Purity</span><span class="meta-value"><?=e($r['purity'] ?? 'N/A')?></span></div>
                </div>
                <a href="/product/<?=e($r['slug'])?>" class="btn btn-outline btn-sm" style="margin-top:10px;">View →</a>
            </div>
        </article>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<?php include 'footer.php'; ?>
</body>
</html>
