<?php
require_once __DIR__ . '/functions.php';
$q = strtolower(trim($_GET['q'] ?? ''));
if (strlen($q) < 2) { echo json_encode([]); exit; }

$products = getProducts();
$results = array_filter($products, fn($p) => 
    stripos($p['product_name'] ?? '', $q) !== false || 
    stripos($p['cas_number'] ?? '', $q) !== false
);
$results = array_slice(array_map(fn($p) => [
    'name' => $p['product_name'],
    'cas' => $p['cas_number'],
    'slug' => $p['slug']
], $results), 0, 8);

header('Content-Type: application/json');
echo json_encode($results);
?>