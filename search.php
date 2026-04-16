<?php 
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'functions.php'; 
// ... rest of code
include 'functions.php'; 
$q = $_GET['q'] ?? '';
$searchType = $_GET['search_type'] ?? 'auto';
$meta = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search Results | AB Chem India</title>
<meta name="description" content="<?=e($meta['description'])?>">
<link rel="stylesheet" href="/styles.css">
<link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<main class="main" style="max-width:1200px; margin:0 auto; padding:20px;">
    <div style="background:var(--accent-lt); padding:20px; border-radius:8px; margin-bottom:24px; border-left:4px solid var(--accent);">
        <h1 style="margin:0 0 8px 0; color:var(--primary);">
            Search Results <?php if($q): ?>for "<?=e($q)?>"<?php endif; ?>
        </h1>
        <p style="margin:0; color:var(--muted);">
            Search Type: <strong><?=ucfirst(e($searchType))?></strong>
            <?php 
            $typeLabels = [
                'cas' => '🔬 CAS Number',
                'inchikey' => '🔬 InChIKey',
                'iupac_name' => '🔬 IUPAC Name',
                'synonym' => '🔬 Synonym',
                'mol_formula' => '🔬 Molecular Formula',
                'smiles' => '🔬 SMILES Structure',
                'keyword' => '📝 Keyword Search'
            ];
            $label = $typeLabels[$searchType] ?? '📝 Keyword Search';
            ?>
            <span style="margin-left:12px; color:var(--accent);"><?=$label?></span>
        </p>
    </div>

    <div id="search-results-grid" class="grid">
        <p style="text-align:center; padding:40px; color:var(--muted);">Loading results...</p>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
const urlParams = new URLSearchParams(window.location.search);
const query = urlParams.get('q') || '';
const searchType = urlParams.get('search_type') || 'auto';

fetch(`/api_data.php?${urlParams.toString()}`)
.then(r => r.json())
.then(data => {
    const grid = document.getElementById('search-results-grid');
    
    if (data.total === 0) {
        grid.innerHTML = `
        <div style="text-align:center; padding:60px 20px; background:var(--surface); border-radius:var(--radius); border:1px solid var(--border);">
            <h3 style="color:var(--primary); margin-bottom:12px;">No results found</h3>
            <p style="color:var(--muted); margin-bottom:20px;">Try adjusting your search or browse our full catalog.</p>
            <a href="/catalog" class="btn btn-primary">Browse All Products</a>
            <a href="/structure-search" class="btn btn-outline" style="margin-left:10px;">Try Structure Search</a>
        </div>`;
        return;
    }
    
    // Render products with images
    grid.innerHTML = data.data.map(p => {
        const imgHtml = p.image_url 
            ? `<img src="${e(p.image_url)}" alt="${e(p.product_name)}" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">`
            : '';
        const fallback = !p.image_url 
            ? '<div style="font-size:3rem; color:var(--muted);">🧪</div>' 
            : '<div style="display:none; font-size:3rem; color:var(--muted);">🧪</div>';
        
        return `
        <article class="card">
            <div class="card-image-container" style="height:160px; background:#f8fafc; display:flex; align-items:center; justify-content:center; position:relative; border-bottom:1px solid #e2e8f0;">
                ${imgHtml}${fallback}
                <span class="availability-badge ${(p.availability||'').toLowerCase()==='in stock'?'in-stock':'backorder'}" style="position:absolute; top:8px; right:8px; font-size:0.7rem; padding:4px 8px; border-radius:12px; font-weight:700; text-transform:uppercase; background:${(p.availability||'').toLowerCase()==='in stock'?'#dcfce7':'#fef3c7'}; color:${(p.availability||'').toLowerCase()==='in stock'?'#166534':'#92400e'};">
                    ${e(p.availability || 'Check')}
                </span>
            </div>
            <div class="card-body" style="padding:14px;">
                <h3 class="card-title" style="font-size:0.95rem; font-weight:700; line-height:1.4; color:var(--text); margin-bottom:8px;">
                    <a href="/product.php?slug=${e(p.slug||'')}">${e(p.product_name)}</a>
                </h3>
                <div class="card-meta" style="display:grid; grid-template-columns:1fr 1fr; gap:6px; background:#f8fafc; padding:8px; border-radius:6px; font-size:0.75rem; margin-bottom:12px;">
                    <div><span class="meta-label" style="color:var(--muted); font-weight:500; display:block;">CAS</span><span class="meta-value" style="font-family:'DM Mono',monospace; font-weight:600;">${e(p.cas_number||'N/A')}</span></div>
                    <div><span class="meta-label" style="color:var(--muted); font-weight:500; display:block;">Purity</span><span class="meta-value" style="font-family:'DM Mono',monospace; font-weight:600;">${e(p.purity||'N/A')}</span></div>
                    <div><span class="meta-label" style="color:var(--muted); font-weight:500; display:block;">MW</span><span class="meta-value" style="font-family:'DM Mono',monospace; font-weight:600;">${e(p.molecular_weight||'N/A')}</span></div>
                    <div><span class="meta-label" style="color:var(--muted); font-weight:500; display:block;">Formula</span><span class="meta-value" style="font-family:'DM Mono',monospace; font-weight:600;">${e(p.molecular_formula||'N/A')}</span></div>
                </div>
                <a href="/product.php?slug=${e(p.slug||'')}" class="btn btn-primary" style="display:block; text-align:center; padding:10px; border-radius:6px; background:var(--accent); color:#fff; font-weight:600; text-decoration:none;">
                    View Details →
                </a>
            </div>
        </article>`;
    }).join('');
})
.catch(err => {
    console.error('Search error:', err);
    document.getElementById('search-results-grid').innerHTML = 
        '<p style="text-align:center; padding:40px; color:var(--danger);">Search failed. Please try again.</p>';
});

function e(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}
</script>
</body>
</html>