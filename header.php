<?php 
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'functions.php'; 
?>
<header class="site-header" role="banner">
<div class="header-container">
    <!-- Logo -->
<a href="/" class="logo" style="display:flex; flex-direction:column; align-items:flex-start; gap:2px; text-decoration:none;">
    <div style="display:flex; align-items:center; gap:10px;">
        <img src="/logo.png" alt="AB Chem Logo" style="height:40px; width:auto;">
        <span style="color:#ffffff; font-size:1.5rem; font-weight:800; line-height:1; letter-spacing:-0.5px;">
            AB<span style="color:#0ea5e9;">Chem</span>
        </span>
    </div>
    <span style="color:#f1f5f9; font-size:0.75rem; font-weight:500; letter-spacing:0.8px; margin-left:1px; opacity:0.95;">
        Specialty <span style="color:#38bdf8;">Chemicals</span> & APIs
    </span>
</a>
    <!-- Search Bar -->
    <div class="search-container" style="position:relative; flex:1; max-width:500px; margin:0 20px;">
        <form action="/search.php" method="get" id="header-search-form" style="display:flex; width:100%;">
            <input type="text" name="q" id="smart-search-input" placeholder="Search by Name, CAS, SMILES..." autocomplete="off" style="flex:1; padding:10px 14px; border:1px solid var(--border); border-radius:6px 0 0 6px; font-size:0.9rem;">
            <button type="submit" class="btn btn-primary" style="border-radius:0 6px 6px 0; padding:10px 16px;">🔍</button>
        </form>
        <div id="search-ac-dropdown" style="position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:1000; display:none; max-height:300px; overflow-y:auto;"></div>
    </div>
    
    <!-- Desktop Navigation -->
    <nav class="main-nav" role="navigation" aria-label="Main">
        <a href="/catalog">Catalog</a>
        <a href="/custom-synthesis">Custom Synthesis</a>
        <a href="/purification">Purification</a>
        <a href="/structure-search">Structure Search</a>
        <a href="/about">About Us</a>
        <a href="/contact">Contact</a>
        <?php if(isset($_SESSION['user'])): ?>
            <?php if($_SESSION['role'] === 'Admin'): ?>
                <a href="/admin" class="btn btn-outline">Admin</a>
            <?php else: ?>
                <a href="/account" class="btn btn-outline">Dashboard</a>
            <?php endif; ?>
            <a href="/logout" class="btn btn-outline">Logout</a>
        <?php else: ?>
            <a href="/signin" class="btn btn-primary">Sign In</a>
        <?php endif; ?>
    </nav>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" aria-label="Toggle navigation menu" style="display:none; background:transparent; border:none; cursor:pointer; padding:8px;">
        <span class="hamburger-line" style="display:block; width:24px; height:3px; background:#fff; margin:5px 0; border-radius:2px;"></span>
        <span class="hamburger-line" style="display:block; width:24px; height:3px; background:#fff; margin:5px 0; border-radius:2px;"></span>
        <span class="hamburger-line" style="display:block; width:24px; height:3px; background:#fff; margin:5px 0; border-radius:2px;"></span>
    </button>
</div>

<!-- Mobile Menu -->
<div id="mobile-menu" class="mobile-menu" hidden style="display:none; width:100%; margin-top:16px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.1);">
    <a href="/catalog" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Catalog</a>
    <a href="/custom-synthesis" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Custom Synthesis</a>
    <a href="/purification" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Purification</a>
    <a href="/structure-search" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Structure Search</a>
    <a href="/about" style="display:block; color:#fff; padding:12px 0; font-weight:500;">About Us</a>
    <a href="/contact" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Contact</a>
    <?php if(isset($_SESSION['user'])): ?>
        <a href="/logout" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Logout</a>
    <?php else: ?>
        <a href="/signin" style="display:block; color:#fff; padding:12px 0; font-weight:500;">Sign In</a>
    <?php endif; ?>
</div>
</header>

<style>
.search-container { position: relative; }
#smart-search-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(14, 122, 191, 0.2); }
.ac-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
.ac-item:hover { background: #f8fafc; }
@media (max-width: 768px) {
    .main-nav { display: none; }
    .mobile-menu-toggle { display: block !important; }
    .search-container { margin: 10px 0 !important; max-width: 100%; }
}
</style>

<script>
// Mobile menu toggle
document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() { 
    const menu = document.getElementById('mobile-menu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});

// Autocomplete for search
const searchInput = document.getElementById('smart-search-input');
const acDropdown = document.getElementById('search-ac-dropdown');
if (searchInput && acDropdown) {
    let debounceTimer;
    searchInput.addEventListener('input', function(e) {
        clearTimeout(debounceTimer);
        const q = e.target.value.trim();
        if (q.length < 2) { acDropdown.style.display = 'none'; return; }
        debounceTimer = setTimeout(async () => {
            try {
                const res = await fetch(`/api_autocomplete.php?q=${encodeURIComponent(q)}`);
                const data = await res.json();
                if (data.length === 0) { acDropdown.style.display = 'none'; return; }
                acDropdown.innerHTML = data.map(d => 
                    `<div class="ac-item" data-slug="${d.slug}"><strong>${escapeHtml(d.name)}</strong> <span style="color:var(--muted); font-size:0.8rem;">| CAS: ${escapeHtml(d.cas)}</span></div>`
                ).join('');
                acDropdown.style.display = 'block';
                acDropdown.querySelectorAll('.ac-item').forEach(item => {
                    item.onclick = () => {
                        searchInput.value = item.querySelector('strong').textContent;
                        acDropdown.style.display = 'none';
                        window.location.href = `/product.php?slug=${item.dataset.slug}`;
                    };
                });
            } catch(err) { console.error('Autocomplete error:', err); }
        }, 300);
    });
    document.addEventListener('click', e => {
        if (!acDropdown.contains(e.target) && e.target !== searchInput) acDropdown.style.display = 'none';
    });
}
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}
// In header.php JS, improve detection:
if (/^[A-Z]{14}-[A-Z]{10}-[A-Z]$/.test(value) || /^[A-Z]{14}/.test(value.toUpperCase())) {
    return { type: 'inchikey', confidence: 'high' };
}
// Global fallback: replace any broken/missing image with logo
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', function() {
            if (this.src !== window.location.origin + '/logo.png') {
                this.src = '/logo.png';
                this.style.objectFit = 'contain';
                this.style.padding   = '8px';
                this.style.opacity   = '0.5';
            }
        });
        // Also catch already-broken images (loaded before JS ran)
        if (img.complete && img.naturalWidth === 0) {
            img.src = '/logo.png';
            img.style.objectFit = 'contain';
            img.style.padding   = '8px';
            img.style.opacity   = '0.5';
        }
    });
});
</script>