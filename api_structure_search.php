<?php
require_once 'functions.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$query = strtolower(trim($input['query'] ?? ''));
$type  = $input['type'] ?? 'keyword';

$products = getProducts();
$results = [];

foreach ($products as $p) {

    $smiles   = strtolower($p['smiles'] ?? '');
    $inchikey = strtolower($p['inchi_key'] ?? '');
    $name     = strtolower($p['product_name'] ?? '');

    $match = false;

    if ($type === 'inchikey') {
        if ($query && strpos($inchikey, $query) !== false) {
            $match = true;
        }
    }

    elseif ($type === 'smiles') {
        if ($query && strpos($smiles, $query) !== false) {
            $match = true;
        }
    }

    else {
        if ($query && (
            strpos($name, $query) !== false ||
            strpos($smiles, $query) !== false ||
            strpos($inchikey, $query) !== false
        )) {
            $match = true;
        }
    }

    if ($match) {
        $results[] = $p;
    }
}

echo json_encode([
    'results' => array_slice($results, 0, 50)
]);