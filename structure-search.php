<?php include 'functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chemical Structure Search | AB Chem India</title>
<link rel="stylesheet" href="/styles.css">
<link rel="icon" type="image/png" href="/logo.png">
<script src="https://unpkg.com/rdkit@2023.9.5/rdkit.min.js"></script>
<style>
.structure-search-container { max-width: 1200px; margin: 40px auto; padding: 30px; background: var(--surface); border-radius: var(--radius); }
.sketch-box { min-height: 300px; border: 2px dashed var(--border); display: flex; align-items: center; justify-content: center; margin: 20px 0; background: #f8fafc; border-radius: 8px; }
.result-card { border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 12px; background: #fff; display: flex; justify-content: space-between; align-items: center; }
.identifier-inputs { margin: 20px 0; padding: 20px; background: var(--bg); border-radius: 8px; }
.identifier-inputs input, .identifier-inputs textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: 'DM Mono', monospace; font-size: 0.85rem; margin: 8px 0; }
.toast { position: fixed; top: 20px; right: 20px; padding: 12px 24px; border-radius: 6px; color: white; z-index: 9999; animation: slideIn 0.3s ease; }
.toast.error { background: var(--danger); }
.toast.success { background: var(--success); }
.toast.info { background: var(--accent); }
@keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(400px); opacity: 0; } }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="structure-search-container">
    <h1 style="color:var(--primary); margin-bottom:8px;">🔬 Chemical Structure Search</h1>
    <p style="color:var(--muted); margin-bottom:24px;">Draw a structure or enter chemical identifiers to search our catalog.</p>
    
    <div class="sketch-box" id="sketch-container">
        <div style="text-align:center; color:var(--muted);">
            <div style="font-size:3rem; margin-bottom:10px;">🧪</div>
            <p>Structure editor loading...</p>
        </div>
    </div>
    
    <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
        <select id="search-type" class="filter-input" style="flex:1; min-width:200px;">
            <option value="exact">Exact Match</option>
            <option value="substructure">Substructure</option>
            <option value="similarity">Similarity (Tanimoto > 0.8)</option>
        </select>
        <button onclick="runSearch()" class="btn btn-primary" style="flex:1;">🔍 Search</button>
    </div>

    <div class="identifier-inputs">
        <h3 style="margin:0 0 12px 0; color:var(--primary); font-size:1.1rem;">Or Enter Chemical Identifiers</h3>
        
        <label style="font-weight:600; display:block; margin:8px 0 4px 0;">SMILES:</label>
        <input type="text" id="smiles-input" placeholder="CC(=O)Nc1ccc(O)cc1">
        
        <label style="font-weight:600; display:block; margin:12px 0 4px 0;">InChI:</label>
        <textarea id="inchi-input" rows="2" placeholder="InChI=1S/C8H9NO2/c1-6(10)9-7-2-4-8(11)5-3-7/h2-5,11H,1H3,(H,9,10)"></textarea>
        
        <label style="font-weight:600; display:block; margin:12px 0 4px 0;">InChIKey (Full or Partial):</label>
        <input type="text" id="inchikey-input" placeholder="RZVAJINKPMORJF-UHFFFAOYSA-N" maxlength="27">
        <small style="color:var(--muted); display:block; margin-top:4px;">Enter full InChIKey or first 14 characters for skeleton search</small>
        
        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
            <button onclick="searchFromText()" class="btn btn-primary">🔍 Search from Text</button>
            <button onclick="clearAll()" class="btn btn-outline">Clear All</button>
        </div>
    </div>

    <div id="loading" style="display:none; text-align:center; padding:20px; color:var(--muted);">
        ⏳ Searching... Please wait
    </div>
    <div id="results" style="margin-top:20px;"></div>
</main>
<?php include 'footer.php'; ?>
<script>
let rdkit = null;
let catalog = [];

// Initialize RDKit
RDKit().then((module) => {
    rdkit = module;
    console.log('RDKit loaded:', module.version);
    loadCatalog();
}).catch(err => {
    showToast('Failed to load RDKit. Please refresh the page.', 'error');
    console.error('RDKit error:', err);
});

// Load catalog
async function loadCatalog() {
    try {
        const res = await fetch('/api_data.php?limit=1000');
        const data = await res.json();
        catalog = data.data;
    } catch (e) {
        showToast('Failed to load catalog', 'error');
    }
}

// Toast notification (auto-dismiss in 5 seconds)
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// Validate SMILES
function isValidSmiles(smiles) {
    if (!rdkit || !smiles) return false;
    try {
        const mol = rdkit.get_mol(smiles);
        const valid = mol && mol.is_valid();
        if (mol) mol.delete();
        return valid;
    } catch (e) {
        return false;
    }
}

// Canonicalize SMILES
function canonicalizeSmiles(smiles) {
    if (!rdkit || !smiles) return null;
    try {
        const mol = rdkit.get_mol(smiles);
        if (!mol || !mol.is_valid()) return null;
        const canonical = mol.get_smiles();
        mol.delete();
        return canonical;
    } catch (e) {
        return null;
    }
}

// Search from text inputs
async function searchFromText() {
    const smiles = document.getElementById('smiles-input').value.trim();
    const inchi = document.getElementById('inchi-input').value.trim();
    const inchiKey = document.getElementById('inchikey-input').value.trim();
    
    if (!smiles && !inchi && !inchiKey) {
        showToast('Please enter at least one identifier', 'error');
        return;
    }
    
    document.getElementById('loading').style.display = 'block';
    document.getElementById('results').innerHTML = '';
    
    let results = [];
    
    // InChIKey search (full or partial)
    if (inchiKey) {
        const queryKey = inchiKey.toUpperCase();
        results = catalog.filter(p => {
            const productKey = (p.inchi_key || '').toUpperCase();
            // Full match or partial (first 14 chars for skeleton)
            return productKey === queryKey || 
                   (queryKey.length >= 14 && productKey.startsWith(queryKey.substring(0, 14)));
        });
        showToast(`Found ${results.length} match(es) by InChIKey`, 'success');
    }
    // SMILES search
    else if (smiles) {
        if (!rdkit) {
            showToast('RDKit not loaded. Please wait...', 'error');
            document.getElementById('loading').style.display = 'none';
            return;
        }
        
        if (!isValidSmiles(smiles)) {
            showToast('Invalid SMILES structure', 'error');
            document.getElementById('loading').style.display = 'none';
            return;
        }
        
        const canonical = canonicalizeSmiles(smiles);
        const searchType = document.getElementById('search-type').value;
        
        results = catalog.filter(p => {
            const pSmiles = p.smiles || '';
            if (!pSmiles) return false;
            
            if (searchType === 'exact') {
                return pSmiles === canonical;
            } else if (searchType === 'substructure') {
                try {
                    const queryMol = rdkit.get_mol(canonical);
                    const targetMol = rdkit.get_mol(pSmiles);
                    const match = targetMol.has_substruct_match(queryMol);
                    queryMol.delete();
                    targetMol.delete();
                    return match;
                } catch (e) {
                    return false;
                }
            }
            return false;
        });
        
        showToast(`Found ${results.length} match(es)`, 'success');
    }
    
    displayResults(results);
    document.getElementById('loading').style.display = 'none';
}

// Display results
function displayResults(results) {
    const resultsDiv = document.getElementById('results');
    
    if (results.length === 0) {
        resultsDiv.innerHTML = '<p style="text-align:center; color:var(--muted); padding:20px;">No matches found</p>';
        return;
    }
    
    resultsDiv.innerHTML = results.map(p => `
        <div class="result-card">
            <div>
                <strong style="font-size:1.1rem; color:var(--primary);">${e(p.product_name)}</strong>
                <div style="color:var(--muted); font-size:0.9rem;">CAS: ${e(p.cas_number)} | Type: ${e(p.product_type)}</div>
            </div>
            <a href="/product.php?slug=${e(p.slug)}" class="btn btn-outline btn-sm">View Details →</a>
        </div>
    `).join('');
}

function clearAll() {
    document.getElementById('smiles-input').value = '';
    document.getElementById('inchi-input').value = '';
    document.getElementById('inchikey-input').value = '';
    document.getElementById('results').innerHTML = '';
}

function e(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
</script>
</body>
</html>