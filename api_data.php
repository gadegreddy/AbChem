<?php
require_once __DIR__ . '/functions.php';

// ===== INPUT =====
$q         = trim($_GET['q'] ?? '');
$searchType = strtolower(trim($_GET['search_type'] ?? 'keyword'));
$types     = isset($_GET['type']) ? (array)$_GET['type'] : [];
$sort      = $_GET['sort'] ?? 'default';
$page      = max(1, intval($_GET['page'] ?? 1));
$limit     = in_array($_GET['per_page'] ?? '12', ['10','20','50','100']) ? intval($_GET['per_page']) : 12;

$products = getProducts();

// ===== AUTO-DETECT SEARCH TYPE =====
if ($searchType === 'auto' && !empty($q)) {
    if (preg_match('/^\d{2,7}-\d{2,}-\d$/', $q)) {
        $searchType = 'cas';
    } elseif (preg_match('/^[A-Z]{14}-[A-Z]{10}-[A-Z]$/', strtoupper($q))) {
        $searchType = 'inchikey';
    } elseif (preg_match('/^[A-Z][a-z]?\d+([A-Z][a-z]?\d+)*$/', $q) && strlen($q) < 30) {
        $searchType = 'mol_formula';
    } elseif (preg_match('/[#\-@+\[\]\\\\%=0-9()]/', $q) && strlen($q) > 5) {
        $searchType = 'smiles';
    } else {
        $searchType = 'keyword';
    }
}
// ===== FILTER LOGIC =====
// ===== FILTER LOGIC =====
$filtered = array_filter($products, function($p) use ($q, $searchType) {
    if (empty($q)) return true;
    $qLower = strtolower(trim($q));
    
    switch ($searchType) {
        case 'cas':
            return stripos($p['cas_number'] ?? '', $q) !== false;
            
        case 'inchikey':
            // Normalize: strip hyphens/spaces, force uppercase for reliable matching
            $cleanQuery = str_replace(['-', ' '], '', strtoupper(trim($q)));
            $cleanKey   = str_replace(['-', ' '], '', strtoupper($p['inchi_key'] ?? ''));
            
            if ($cleanQuery === '' || $cleanKey === '') return false;
            if ($cleanKey === $cleanQuery) return true;
            
            // InChIKey skeleton is first 14 chars
            if (strlen($cleanQuery) >= 14 && substr($cleanKey, 0, 14) === substr($cleanQuery, 0, 14)) return true;
            
            // Fallback: substring match
            return strpos($cleanKey, $cleanQuery) !== false;
            
        case 'iupac_name':
            return stripos($p['iupac_name'] ?? '', $q) !== false;
            
        case 'synonym':
            $syns = explode(',', $p['synonyms'] ?? '');
            foreach ($syns as $syn) if (stripos(trim($syn), $q) !== false) return true;
            return false;
            
        case 'mol_formula':
            return ($p['molecular_formula'] ?? '') === $q;
            
        case 'smiles':
            return stripos($p['smiles'] ?? '', $q) !== false ||
                   stripos($p['smiles_canonical'] ?? '', $q) !== false;
            
        case 'keyword':
        default:
            $searchable = strtolower(
                ($p['product_name'] ?? '') . ' ' .
                ($p['cas_number'] ?? '') . ' ' .
                ($p['iupac_name'] ?? '') . ' ' .
                ($p['synonyms'] ?? '') . ' ' .
                ($p['molecular_formula'] ?? '') . ' ' .
                ($p['keywords'] ?? '')
            );
            return strpos($searchable, $qLower) !== false;
    }
});

// ===== TYPE FILTER (Product Type) =====
if (!empty($types)) {
    $filtered = array_filter($filtered, fn($p) => in_array($p['product_type'] ?? '', $types));
}

// ===== SORT =====
usort($filtered, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'name_asc': return strcasecmp($a['product_name'] ?? '', $b['product_name'] ?? '');
        case 'name_desc': return strcasecmp($b['product_name'] ?? '', $a['product_name'] ?? '');
        case 'purity_desc': return (float)str_replace('%', '', $b['purity'] ?? '0') <=> (float)str_replace('%', '', $a['purity'] ?? '0');
        case 'mw_asc': return (float)($a['molecular_weight'] ?? 0) <=> (float)($b['molecular_weight'] ?? 0);
        default: return 0;
    }
});

// ===== PAGINATION =====
$total = count($filtered);
$paged = array_slice($filtered, ($page - 1) * $limit, $limit);

// ===== JSON OUTPUT =====
header('Content-Type: application/json');
echo json_encode([
    'data'        => $paged,
    'total'       => $total,
    'page'        => $page,
    'limit'       => $limit,
    'pages'       => ceil($total / $limit),
    'search_type' => $searchType,
    'query'       => $q
], JSON_UNESCAPED_SLASHES);
?>