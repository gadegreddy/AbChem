document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('filter-form');
    const grid = document.getElementById('product-grid');
    const countEl = document.getElementById('result-count');
    const rangeEl = document.getElementById('result-range');
    const pag = document.getElementById('pagination');
    const searchInput = document.getElementById('smart-search-input');
    const acDropdown = document.getElementById('search-ac-dropdown');
    const perPageSelect = document.getElementById('per-page');

    if (!grid) return;

    // Helper: Debounce function for autocomplete
    const debounce = (fn, ms) => {
        let t;
        return (...a) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...a), ms);
        };
    };

    // Helper: HTML escape
    function e(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    /**
     * Core function to load catalog data
     * Expects URLSearchParams object
     */
    async function loadCatalog(qs) {
        if (!qs) qs = new URLSearchParams();
        
        grid.innerHTML = '<div style="padding:40px; text-align:center; color:var(--muted);">Loading compounds...</div>';
        
        try {
            // ✅ FIXED: qs is already URLSearchParams, so toString() works correctly now
            const res = await fetch(`api_data.php?${qs.toString()}`);
            if (!res.ok) throw new Error('Network response was not ok');
            const data = await res.json();
            
            // Update UI counts
            const start = data.total === 0 ? 0 : ((data.page - 1) * data.limit) + 1;
            const end = Math.min(data.page * data.limit, data.total);
            if (rangeEl) rangeEl.textContent = data.total > 0 ? `Results ${start}-${end} of ${data.total}` : 'No results';
            if (countEl) countEl.textContent = `${data.total} Compound${data.total !== 1 ? 's' : ''}`;
            
            renderProducts(data.data);
            renderPagination(data.page, data.pages, qs);
            
            // Update URL without reload
            window.history.replaceState({}, '', `${location.pathname}?${qs.toString()}`);
        } catch (err) {
            console.error('Load error:', err);
            grid.innerHTML = '<p style="text-align:center; padding:20px; color:var(--danger);">Error loading data. Please try again.</p>';
        }
    }

    function renderProducts(items) {
        if (!items?.length) { 
            grid.innerHTML = '<div style="padding:40px; text-align:center; color:var(--muted); grid-column: 1/-1;"><h3>No compounds match your criteria.</h3><p>Try adjusting your filters or search query.</p></div>'; 
            return; 
        }
        
        grid.innerHTML = items.map(p => {
            const imgHtml = p.image_url 
                ? `<img src="${e(p.image_url)}" alt="${e(p.product_name)}" loading="lazy" onerror="this.src='/logo.png'; this.style.objectFit='contain'; this.style.padding='8px'; this.style.opacity='0.5';">
                   <div style="display:none; font-size:3rem; color:var(--muted);">🧪</div>`
                : `<div style="font-size:3rem; color:var(--muted);">🧪</div>`;
            
            return `
            <article class="card">
                <div class="card-image-container">
                    ${imgHtml}
                    <span class="availability-badge ${(p.availability||'').toLowerCase()==='in stock'?'in-stock':'backorder'}">${e(p.availability)}</span>
                </div>
                <div class="card-body">
                    <h3 class="card-title"><a href="product.php?slug=${e(p.slug)}">${e(p.product_name)}</a></h3>
                    <div class="card-meta">
                        <div><span class="meta-label">CAS</span><span class="meta-value">${e(p.cas_number||'N/A')}</span></div>
                        <div><span class="meta-label">Purity</span><span class="meta-value">${e(p.purity||'N/A')}</span></div>
                        <div><span class="meta-label">MW</span><span class="meta-value">${e(p.molecular_weight||'N/A')}</span></div>
                        <div><span class="meta-label">Formula</span><span class="meta-value">${e(p.molecular_formula||'N/A')}</span></div>
                    </div>
                          <div class="card-body">
        <h3 class="card-title">
          <a href="/product/${e(p.slug)}">${e(p.product_name)}</a>
        </h3>
        
        <!-- ✅ COMPOUND HEADERS -->
        <div class="compound-headers">
          <span class="h-item"><strong>CAS</strong> ${e(p.cas_number || 'N/A')}</span>
          <span class="h-item"><strong>MW</strong> ${e(p.molecular_weight || '—')}</span>
          <span class="h-item"><strong>MF</strong> ${e(p.molecular_formula || '—')}</span>
          <span class="h-item"><strong>Purity</strong> ${e(p.purity || '—')}</span>
          <span class="h-item"><strong>Type</strong> ${e(p.product_type || '—')}</span>
        </div>
                    <div class="card-footer">
                        <span class="tag" style="font-size:0.7rem; background:#f1f5f9; padding:4px 8px; border-radius:4px;">${e(p.product_type)}</span>
                        <a href="product.php?slug=${e(p.slug)}" class="btn btn-outline btn-sm">Details →</a>
                    </div>
                </div>
            </article>`;
        }).join('');
    }
    
    // In include.js, update your card template function:
function renderProductCard(p) {
  const stockClass = (p.availability || '').toLowerCase() === 'in stock' ? 'in-stock' : 'backorder';
  const imgSrc = p.image_url && p.image_url !== 'NA' ? p.image_url : '/logo.png';
  
  return `
    <article class="card">
      <div class="card-image-container">
        <img src="${imgSrc}" alt="${e(p.product_name)}" loading="lazy" onerror="this.src='/logo.png'; this.style.opacity='0.4'; this.style.padding='16px';">
        <span class="availability-badge ${stockClass}">${e(p.availability || 'Contact')}</span>
      </div>
      <div class="card-body">
        <h3 class="card-title">
          <a href="/product/${e(p.slug)}">${e(p.product_name)}</a>
        </h3>
              <div class="card-body">
        <h3 class="card-title">
          <a href="/product/${e(p.slug)}">${e(p.product_name)}</a>
        </h3>
        
        <!-- ✅ COMPOUND HEADERS -->
        <div class="compound-headers">
          <span class="h-item"><strong>CAS</strong> ${e(p.cas_number || 'N/A')}</span>
          <span class="h-item"><strong>MW</strong> ${e(p.molecular_weight || '—')}</span>
          <span class="h-item"><strong>MF</strong> ${e(p.molecular_formula || '—')}</span>
          <span class="h-item"><strong>Purity</strong> ${e(p.purity || '—')}</span>
          <span class="h-item"><strong>Type</strong> ${e(p.product_type || '—')}</span>
        </div>

        <a href="/product/${e(p.slug)}" class="btn btn-outline btn-sm" style="margin-top:10px; display:inline-block;">View Details →</a>
      </div>
    </article>
  `;
}

    function renderPagination(current, total, qs) {
        if (!pag || total <= 1) { if(pag) pag.innerHTML = ''; return; }
        
        let html = '';
        for (let i = 1; i <= total; i++) {
            const c = new URLSearchParams(qs); 
            c.set('page', i);
            // Use a temporary link to format the query string safely
            const url = `?${c.toString()}`;
            html += `<a href="${url}" class="page-btn ${i===current?'active':''}" data-page="${i}">${i}</a>`;
        }
        pag.innerHTML = html;
        
        // Attach click handlers for AJAX pagination
        pag.querySelectorAll('[data-page]').forEach(a => {
            a.onclick = e => { 
                e.preventDefault(); 
                const p = new URLSearchParams(qs); 
                p.set('page', a.dataset.page); 
                loadCatalog(p); 
            };
        });
    }

    // ===== EVENT LISTENERS =====
    if (form) {
        // Intercept Form Submit
        form.addEventListener('submit', e => {
            e.preventDefault(); // Stop the "blink" (page reload)
            // ✅ FIXED: Convert FormData to URLSearchParams correctly
            loadCatalog(new URLSearchParams(new FormData(form)));
        });

        // Listen for filter changes
        form.addEventListener('change', () => {
            loadCatalog(new URLSearchParams(new FormData(form)));
        });

        // Per-page select
        perPageSelect?.addEventListener('change', () => {
            loadCatalog(new URLSearchParams(new FormData(form)));
        });

        // Clear button
        form.onreset = () => {
            setTimeout(() => loadCatalog(new URLSearchParams()), 50);
        };
    }

    // Autocomplete for Header Search
    if (searchInput && acDropdown) {
        searchInput.addEventListener('input', debounce(async (e) => {
            const q = e.target.value.trim();
            if (q.length < 2) { acDropdown.innerHTML = ''; acDropdown.style.display = 'none'; return; }
            try {
                const res = await fetch(`api_autocomplete.php?q=${encodeURIComponent(q)}`);
                const data = await res.json();
                if (data.length === 0) { acDropdown.style.display = 'none'; return; }
                
                acDropdown.innerHTML = data.map(d => 
                    `<div class="ac-item" data-slug="${d.slug}"><strong>${e(d.name)}</strong><span style="color:var(--muted);font-size:0.8rem;">| CAS: ${e(d.cas)}</span></div>`
                ).join('');
                acDropdown.style.display = 'block';
                
                acDropdown.querySelectorAll('.ac-item').forEach(item => {
                    item.onclick = () => {
                        searchInput.value = item.querySelector('strong').textContent;
                        acDropdown.style.display = 'none';
                        // Redirect to product page
                        window.location.href = `product.php?slug=${item.dataset.slug}`;
                    };
                });
            } catch(err) { console.error('Autocomplete failed', err); }
        }, 300));
        
        document.addEventListener('click', e => { 
            if (!acDropdown.contains(e.target) && e.target !== searchInput) acDropdown.style.display = 'none'; 
        });
    }

    // Initial Load from URL params
    loadCatalog(new URLSearchParams(window.location.search));
});