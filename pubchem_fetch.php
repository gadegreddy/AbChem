<?php
/**
* PubChem Auto-Fetch Script - SECURED + TIMEOUT
*/
require_once 'functions.php';

// 🔒 Web-only: Enforce 15-min inactivity timeout + Admin gate
if (php_sapi_name() !== 'cli') {
    enforceSessionTimeout(900); // 900 sec = 15 minutes
    if (!isset($_SESSION['role']) || !checkRole('Admin')) {
        header('Location: signin.php');
        exit;
    }
}

// Enable error reporting (turn off display_errors in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

class PubChemFetcher {
    private $csvPath;
    private $jsonPath;
    private $imageDir;
    
    public function __construct() {
        $this->csvPath = __DIR__ . '/products_master.csv';
        $this->jsonPath = __DIR__ . '/products_data.json';
        $this->imageDir = __DIR__ . '/compound_images/';
        if (!is_dir($this->imageDir)) {
            mkdir($this->imageDir, 0755, true);
        }
    }

    /**
     * ✅ Generate slug from IUPAC name with collision safety
     */
    private function generateSlugFromName($name, $existingSlugs) {
        if (empty($name)) return 'pubchem-' . uniqid();
        
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name)));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 80); // Keep it reasonable
        
        // Handle collisions
        $base = $slug;
        $i = 1;
        while (in_array($slug, $existingSlugs)) {
            $slug = $base . '-' . $i++;
        }
        
        return $slug;
    }

    /**
     * ✅ De-duplicate using InChIKey & normalized non-standard InChI
     * Handles labeled (1S) vs non-labeled (1) compounds
     */
    private function isDuplicate($inchiKey, $inchi, $existingProducts) {
        foreach ($existingProducts as $p) {
            $existKey  = $p['inchi_key']  ?? '';
            $existInch = $p['inchi']      ?? '';
            
            // Exact InChIKey match
            if (!empty($inchiKey) && !empty($existKey) && $inchiKey === $existKey) {
                return true;
            }
            
            // Normalized InChI match (handles 1S vs 1 for labeled compounds)
            if (!empty($inchi) && !empty($existInch)) {
                $norm1 = preg_replace('/^InChI=1[SD]?\//', 'InChI=1/', $inchi);
                $norm2 = preg_replace('/^InChI=1[SD]?\//', 'InChI=1/', $existInch);
                if ($norm1 === $norm2) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * ✅ Fallback to PubChem PNG if OPSIN fails
     */
    private function downloadPubChemImage($cid, $slug) {
        $url  = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$cid}/PNG";
        $path = $this->imageDir . $slug . '.png';
        $data = $this->fetchURL($url, true);
        
        // Validate actual PNG header to avoid saving error pages
        if ($data && strlen($data) > 500 && strpos($data, "\x89PNG") === 0) {
            file_put_contents($path, $data);
            return '/compound_images/' . $slug . '.png';
        }
        return '';
    }

    /**
     * ✅ Save fetched results to CSV & clear cache
     */
    public function saveFetchResultsToCatalog(array $results, array $defaults = []) {
        $existing = $this->loadCSV();
        $existingSlugs = array_column($existing, 'slug');
        $added = []; 
        $skipped = []; 
        $errors = [];

        foreach ($results as $r) {
            if (!empty($r['error']) || empty($r['cid'])) {
                $errors[] = $r['input'] . ': ' . ($r['error'] ?? 'No CID');
                continue;
            }

            if ($this->isDuplicate($r['inchi_key'] ?? '', $r['inchi'] ?? '', $existing)) {
                $skipped[] = $r['input'] . ' (already in catalog)';
                continue;
            }

            $name = $r['iupac_name'] ?: $r['input'];
            $slug = $this->generateSlugFromName($name, $existingSlugs);
            $existingSlugs[] = $slug;

            // Image: OPSIN → PubChem fallback
            $img = $this->generateImage($r['iupac_name'] ?? '', $slug);
            if (empty($img)) {
                $img = $this->downloadPubChemImage($r['cid'], $slug);
            }

            // Map exactly to your CSV headers
            $newProduct = [
                'slug'              => $slug,
                'Company_make'      => $defaults['Company_make'] ?? 'AB Chem India',
                'cas_number'        => $r['cas_number'] ?? '',
                'smiles'            => $r['smiles'] ?? '',
                'inchi'             => $r['inchi'] ?? '',
                'inchi_key'         => $r['inchi_key'] ?? '',
                'iupac_name'        => $r['iupac_name'] ?? '',
                'molecular_formula' => $r['molecular_formula'] ?? '',
                'molecular_weight'  => $r['molecular_weight'] ?? '',
                'purity'            => $defaults['purity'] ?? '95%',
                'product_type'      => $defaults['product_type'] ?? 'Reference Standard',
                'availability'      => $defaults['availability'] ?? 'In Stock',
                'product_name'      => $name,
                'image_url'         => $img,
                'lead_time'         => $defaults['lead_time'] ?? '3-5 days',
                'pubchem_cid'       => $r['cid'],
                'synonyms'          => $r['synonyms'] ?? '',
                'Lot_number'        => '',
                'manufacture_date'  => '',
                'expiry_date'       => '',
            ];

            $existing[] = $newProduct;
            $added[]    = $slug;
        }

        if (!empty($added)) {
            // Atomic CSV update
            $this->saveAllCSV($existing);
            // ✅ Instantly reflect on website
            clearProductCache(); 
        }

        return ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * ✅ Generate structure image using OPSIN
     */
    private function generateImage($iupacName, $slug) {
        if (empty($iupacName)) return '';
        
        $opsinUrl = "https://www.ebi.ac.uk/opsin/ws/" . urlencode($iupacName) . ".png";
        $localPath = $this->imageDir . $slug . '.png';
        
        // Check if already exists and valid
        if (file_exists($localPath) && filesize($localPath) > 100) {
            return '/compound_images/' . $slug . '.png';
        }
        
        $imageData = $this->fetchURL($opsinUrl, true);
        
        // OPSIN returns 404 or HTML error if name invalid - validate PNG header
        if ($imageData && strlen($imageData) > 500 && strpos($imageData, "\x89PNG") === 0) {
            file_put_contents($localPath, $imageData);
            return '/compound_images/' . $slug . '.png';
        }
        
        return '';
    }

    /**
     * Lazy-fetch single product & APPEND to CSV (atomic)
     */
    public function lazyFetchProduct($slug) {
        $products = $this->loadCSV();
        $index = array_search($slug, array_column($products, 'slug'));
        
        if ($index === false) {
            return ['error' => "Product with slug '$slug' not found"];
        }
        
        $product = &$products[$index];
        
        // Skip if already has key identifiers
        if (!empty($product['smiles']) && !empty($product['inchi_key'])) {
            return ['status' => 'already_complete', 'product' => $product];
        }
        
        // Try fetch from PubChem (name priority → CAS fallback)
        $cid = $this->searchByPriority($product['product_name'], $product['cas_number'], $product['inchi_key'] ?? null);
        if (!$cid) {
            return ['error' => 'PubChem lookup failed', 'product' => $product];
        }
        
        $props = $this->fetchProperties($cid);
        if (!$props) {
            return ['error' => 'Failed to fetch properties', 'product' => $product];
        }
        
        // Merge only missing fields (preserve existing data)
        foreach ($props as $key => $val) {
            if (empty($product[$key]) || $product[$key] === 'NA' || $product[$key] === '') {
                $product[$key] = $val;
            }
        }
        
        $product['pubchem_cid'] = $cid;
        
        // Synonyms (fixed endpoint)
        if (empty($product['synonyms'])) {
            $syns = $this->fetchSynonyms($cid);
            if (!empty($syns)) $product['synonyms'] = implode('; ', array_slice($syns, 0, 10));
        }
        
        // Generate image if IUPAC available
        if (!empty($props['iupac_name']) && empty($product['image_url'])) {
            $img = $this->generateImage($props['iupac_name'], $slug);
            if ($img) $product['image_url'] = $img;
        }
        
        // ✅ Atomic CSV update
        $this->updateCSVRow($index, $product);
        
        // Update JSON cache
        $this->saveJSON($products);
        
        return ['status' => 'updated', 'product' => $product];
    }

    /**
     * Update single row in CSV atomically
     */
    private function updateCSVRow($index, $updatedProduct) {
        $tempFile = $this->csvPath . '.tmp';
        $handle = fopen($this->csvPath, 'r');
        
        if (!$handle) {
            throw new RuntimeException("Cannot open CSV for reading");
        }
        
        $temp = fopen($tempFile, 'w');
        if (!$temp) {
            fclose($handle);
            throw new RuntimeException("Cannot create temp CSV file");
        }
        
        $headers = fgetcsv($handle);
        
        // Add 'synonyms' column if missing
        if (!in_array('synonyms', $headers)) {
            $headers[] = 'synonyms';
        }
        
        fputcsv($temp, $headers);
        
        $rowIndex = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($rowIndex === $index) {
                // Map existing row to headers for safe merging
                $rowMap = array_combine($headers, array_pad($row, count($headers), ''));
                // Merge updated fields, preserving existing if not empty
                $merged = array_merge($rowMap, $updatedProduct);
                // Ensure correct order
                $outputRow = [];
                foreach ($headers as $h) {
                    $val = $merged[$h] ?? '';
                    $outputRow[] = (string)$val;
                }
                fputcsv($temp, $outputRow);
            } else {
                // Pad short rows to match header count
                fputcsv($temp, array_pad($row, count($headers), ''));
            }
            $rowIndex++;
        }
        
        fclose($handle);
        fclose($temp);
        
        // ✅ Atomic replace
        if (!rename($tempFile, $this->csvPath)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to update CSV atomically");
        }
        
        // Clear cache
        $cacheFile = sys_get_temp_dir() . '/abchem_products.cache';
        if (file_exists($cacheFile)) @unlink($cacheFile);
    }

    /**
     * Fetch synonyms from PubChem for a given CID
     */
    private function fetchSynonyms(int $cid): array {
        $url  = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$cid}/synonyms/JSON";
        $resp = $this->fetchURL($url);
        if (!$resp) return [];
        
        $data = json_decode($resp, true);
        // Correct path: InformationList → Information[0] → Synonym (array)
        $syns = $data['InformationList']['Information'][0]['Synonym'] ?? [];
        return array_map('trim', $syns);
    }

    /**
     * Search by priority: Name → CAS → InChIKey
     */
    private function searchByPriority($name, $cas, $inchikey = null) {
        if (!empty($name) && $name !== 'NA') {
            $cid = $this->searchPubChem('name', $name);
            if ($cid) return $cid;
        }
        if (!empty($cas) && $cas !== 'NA') {
            $cid = $this->searchPubChem('xref/RN', $cas);
            if ($cid) return $cid;
        }
        if (!empty($inchikey) && $inchikey !== 'NA') {
            $cid = $this->searchPubChem('xref/InChIKey', $inchikey);
            if ($cid) return $cid;
        }
        return null;
    }

    /**
     * Search PubChem API (GET-based: name, inchikey, smiles, cid)
     */
    private function searchPubChem($type, $value) {
        $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/{$type}/" . urlencode($value) . "/cids/JSON";
        $response = $this->fetchURL($url);
        if (!$response) return null;
        
        $data = json_decode($response, true);
        return $data['IdentifierList']['CID'][0] ?? null;
    }

    /**
     * Search PubChem by InChI string (requires HTTP POST)
     */
    private function searchPubChemByInChI($inchi) {
        $url  = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/inchi/cids/JSON";
        $post = 'inchi=' . urlencode($inchi);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ABChem-Fetcher/1.4',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) return null;
        
        $data = json_decode($response, true);
        return $data['IdentifierList']['CID'][0] ?? null;
    }

    /**
     * Auto-detect the identifier type from a raw string.
     * Returns: 'cid' | 'inchikey' | 'inchi' | 'smiles' | 'name'
     */
    public function autoDetectType(string $value): string {
        $v = trim($value);
        
        // Pure integer → CID
        if (ctype_digit($v)) return 'cid';
        
        // InChI string
        if (stripos($v, 'InChI=') === 0) return 'inchi';
        
        // InChIKey: 14 uppercase + dash + 10 uppercase + dash + 1 uppercase
        if (preg_match('/^[A-Z]{14}-[A-Z]{10}-[A-Z]$/', $v)) return 'inchikey';
        
        // CAS registry number  e.g. 50-78-2  (treated via 'name' endpoint which handles CAS)
        if (preg_match('/^\d{2,7}-\d{2}-\d$/', $v)) return 'name';
        
        // SMILES heuristic: no spaces, contains typical SMILES chars
        if (!str_contains($v, ' ') && preg_match('/[=#@\[\]\/\\\\+\-]/', $v)) return 'smiles';
        
        // Default: name (covers IUPAC, trivial names, CAS synonyms)
        return 'name';
    }

    /**
     * Resolve any identifier to a PubChem CID.
     * $type: 'auto' | 'name' | 'cid' | 'inchikey' | 'inchi' | 'smiles'
     */
    public function resolveToCID(string $value, string $type = 'auto'): ?int {
        if ($type === 'auto') $type = $this->autoDetectType($value);
        
        switch ($type) {
            case 'cid':
                return ctype_digit(trim($value)) ? (int)trim($value) : null;
            case 'inchi':
                return $this->searchPubChemByInChI(trim($value));
            case 'inchikey':
                return $this->searchPubChem('inchikey', trim($value));
            case 'smiles':
                return $this->searchPubChem('smiles', trim($value));
            case 'name':
            default:
                return $this->searchPubChem('name', trim($value));
        }
    }

    /**
     * Fetch full compound data for one identifier string.
     * Returns associative array with all properties + synonyms, or error key.
     */
    public function fetchOneIdentifier(string $value, string $type = 'auto'): array {
        $detectedType = ($type === 'auto') ? $this->autoDetectType($value) : $type;
        
        $cid = $this->resolveToCID($value, $detectedType);
        if (!$cid) {
            return [
                'input'         => $value,
                'detected_type' => $detectedType,
                'error'         => 'Not found in PubChem',
            ];
        }
        
        $props = $this->fetchProperties($cid);
        if (!$props) {
            return [
                'input'         => $value,
                'detected_type' => $detectedType,
                'cid'           => $cid,
                'error'         => 'Failed to fetch properties',
            ];
        }
        
        $syns = $this->fetchSynonyms($cid);
        
        return array_merge(
            [
                'input'         => $value,
                'detected_type' => $detectedType,
                'cid'           => $cid,
            ],
            $props,
            ['synonyms' => implode('; ', array_slice($syns, 0, 8))]
        );
    }

    /**
     * Fetch multiple identifiers (one per line from textarea).
     * $type: 'auto' | 'name' | 'cid' | 'inchikey' | 'inchi' | 'smiles'
     * Returns array of per-identifier results.
     */
    public function fetchMultiple(string $rawInput, string $type = 'auto'): array {
        $lines   = array_filter(array_map('trim', explode("\n", $rawInput)));
        $results = [];
        
        foreach ($lines as $i => $line) {
            if ($i > 0) usleep(250000); // 250 ms rate-limit between requests
            $results[] = $this->fetchOneIdentifier($line, $type);
        }
        
        return $results;
    }

    /**
     * Fetch compound properties
     */
    private function fetchProperties($cid) {
        $props = ['MolecularFormula','MolecularWeight','SMILES','CanonicalSMILES','IsomericSMILES','IUPACName','InChI','InChIKey'];
        $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$cid}/property/" . implode(',', $props) . "/JSON";
        
        $response = $this->fetchURL($url);
        if (!$response) return null;
        
        $data = json_decode($response, true);
        if (!isset($data['PropertyTable']['Properties'][0])) return null;
        
        $p = $data['PropertyTable']['Properties'][0];
        
        return [
            'molecular_formula' => $p['MolecularFormula'] ?? '',
            'molecular_weight'  => $p['MolecularWeight'] ?? '',
            'smiles'            => $p['IsomericSMILES'] ?? $p['CanonicalSMILES'] ?? $p['SMILES'] ?? '',
            'inchi'             => $p['InChI'] ?? '',
            'inchi_key'         => $p['InChIKey'] ?? '',
            'iupac_name'        => $p['IUPACName'] ?? ''
        ];
    }

    /**
     * HTTP fetch with cURL
     */
    private function fetchURL($url, $binary = false) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ABChem-Fetcher/1.3',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && $response && empty($error)) {
            return $response;
        }
        
        error_log("PubChem fetch failed: $url - HTTP $httpCode - $error");
        return null;
    }

    /**
     * Load CSV products (made public for CLI/Web export)
     */
    public function loadCSV() {
        if (!file_exists($this->csvPath)) return [];
        
        $data = array_map('str_getcsv', file($this->csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if (empty($data)) return [];
        
        $headers = array_map('trim', array_shift($data));
        $products = [];
        
        foreach ($data as $row) {
            if (count($row) === count($headers)) {
                $products[] = array_combine($headers, array_map('trim', $row));
            }
        }
        
        return $products;
    }

    /**
     * Save products as JSON (made public for CLI/Web export)
     */
    public function saveJSON($products) {
        $jsonData = [
            'metadata' => [
                'total_products' => count($products),
                'last_updated'   => date('Y-m-d H:i:s'),
                'source'         => 'PubChem + OPSIN'
            ],
            'products' => $products
        ];
        
        file_put_contents($this->jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * ✅ Batch fetch all missing data (optimized for memory & I/O)
     */
    public function fetchAllMissing($limit = 0) {
        $products = $this->loadCSV();
        $updated = 0; $errors = 0;
        
        echo "🔄 Starting batch fetch...\n";
        echo "📊 Total products: " . count($products) . "\n";
        
        foreach ($products as $index => &$product) {
            if ($limit > 0 && $updated >= $limit) break;
            
            if (!empty($product['smiles']) && !empty($product['inchi_key'])) {
                continue;
            }
            
            echo "[" . ($index + 1) . "] " . ($product['product_name'] ?? 'Unknown') . "... ";
            
            $cid = $this->searchByPriority($product['product_name'], $product['cas_number'], $product['inchi_key'] ?? null);
            if (!$cid) { $errors++; echo "❌ Lookup failed\n"; continue; }
            
            $props = $this->fetchProperties($cid);
            if (!$props) { $errors++; echo "❌ Fetch props failed\n"; continue; }
            
            foreach ($props as $key => $val) {
                if (empty($product[$key]) || $product[$key] === 'NA' || $product[$key] === '') {
                    $product[$key] = $val;
                }
            }
            
            $product['pubchem_cid'] = $cid;
            
            if (empty($product['synonyms'])) {
                $syns = $this->fetchSynonyms($cid);
                if (!empty($syns)) {
                    $product['synonyms'] = implode(';', array_slice($syns, 0, 10));
                }
            }
            
            if (!empty($props['iupac_name']) && empty($product['image_url'])) {
                $img = $this->generateImage($props['iupac_name'], $product['slug']);
                if ($img) $product['image_url'] = $img;
            }
            
            $updated++;
            echo "✅\n";
            
            usleep(250000); // 250ms rate limit (~4 req/sec)
        }
        
        // Save once at the end
        if ($updated > 0) {
            $this->saveJSON($products);
            $this->saveAllCSV($products);
        }
        
        echo "\n🎉 Complete: $updated updated, $errors errors\n";
        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Save entire product array to CSV atomically
     */
    private function saveAllCSV($products) {
        if (empty($products)) return;
        
        $headers = array_keys($products[0]);
        $tempFile = $this->csvPath . '.tmp';
        $handle = fopen($tempFile, 'w');
        
        if (!$handle) throw new RuntimeException("Cannot create temp CSV for batch save");
        
        fputcsv($handle, $headers);
        
        foreach ($products as $row) {
            $output = [];
            foreach ($headers as $h) {
                $output[] = $row[$h] ?? '';
            }
            fputcsv($handle, $output);
        }
        
        fclose($handle);
        
        if (!rename($tempFile, $this->csvPath)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to replace CSV atomically");
        }
    }

    /**
     * ✅ Convert fetched results to CSV format for download
     */
    public function resultsToCSV(array $results): string {
        $headers = [
            'Input', 'Detected Type', 'CID', 'Molecular Formula', 'Molecular Weight',
            'smiles', 'InChIKey', 'InChI', 'IUPAC Name', 'Synonyms', 'Error'
        ];
        
        $output = fopen('php://temp', 'w');
        fputcsv($output, $headers);
        
        foreach ($results as $r) {
            $row = [
                $r['input'] ?? '',
                $r['detected_type'] ?? '',
                $r['cid'] ?? '',
                $r['molecular_formula'] ?? '',
                $r['molecular_weight'] ?? '',
                $r['smiles'] ?? '',
                $r['inchi_key'] ?? '',
                $r['inchi'] ?? '',
                $r['iupac_name'] ?? '',
                $r['synonyms'] ?? '',
                $r['error'] ?? ''
            ];
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}

// ============= CLI vs WEB EXECUTION =============
if (php_sapi_name() === 'cli') {
    // ===== CLI MODE =====
    $fetcher = new PubChemFetcher();
    
    if (isset($argv[1]) && $argv[1] === '--lazy' && isset($argv[2])) {
        $result = $fetcher->lazyFetchProduct($argv[2]);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        
    } elseif (isset($argv[1]) && $argv[1] === '--multi') {
        // --multi [type] "identifier1\nidentifier2\n..."
        if (isset($argv[3])) {
            $type  = $argv[2];          // explicit type
            $input = $argv[3];
        } elseif (isset($argv[2])) {
            $type  = 'auto';
            $input = $argv[2];
        } else {
            echo "Usage: php pubchem_fetch.php --multi [type] \"identifier1\nidentifier2\"\n";
            echo "  type: auto | name | cid | inchikey | inchi | smiles\n";
            exit(1);
        }
        
        $input   = str_replace('\\n', "\n", $input); // allow literal \n in shell args
        $results = $fetcher->fetchMultiple($input, $type);
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        
    } elseif (isset($argv[1]) && $argv[1] === '--batch') {
        $limit = isset($argv[2]) ? (int)$argv[2] : 0;
        $fetcher->fetchAllMissing($limit);
        
    } elseif (isset($argv[1]) && $argv[1] === '--export') {
        $products = $fetcher->loadCSV();
        $fetcher->saveJSON($products);
        echo "✅ Exported " . count($products) . " products to /products_data.json\n";
        
    } else {
        echo "Usage:\n";
        echo "  php pubchem_fetch.php --multi [type] \"id1\nid2\"  # Multi-identifier lookup\n";
        echo "  php pubchem_fetch.php --lazy <slug>               # Fetch single CSV product\n";
        echo "  php pubchem_fetch.php --batch [limit]             # Fetch all missing\n";
        echo "  php pubchem_fetch.php --export                    # Export CSV→JSON\n";
        echo "\nIdentifier types: auto | name | cid | inchikey | inchi | smiles\n";
    }
    
} else {
    // ===== WEB MODE =====
    header('Content-Type: text/html; charset=utf-8');
    
    // ── Resolve multi-fetch results before any output ──────────────────────
    $multiResults  = null;
    $multiError    = null;
    $catalogResult = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fetcher = new PubChemFetcher();
        
        // Multi-fetch identifiers
        if (($_POST['action'] ?? '') === 'multi') {
            $rawInput    = $_POST['identifiers'] ?? '';
            $idType      = $_POST['id_type'] ?? 'auto';
            $allowedTypes = ['auto','name','cid','inchikey','inchi','smiles'];
            
            if (!in_array($idType, $allowedTypes)) $idType = 'auto';
            
            try {
                $multiResults = $fetcher->fetchMultiple($rawInput, $idType);
            } catch (Exception $e) {
                $multiError = $e->getMessage();
                error_log("PubChem multi-fetch error: " . $e->getMessage());
            }
        }
        
        // ✅ Add fetched results to catalog
        if (($_POST['action'] ?? '') === 'add_to_catalog') {
            $rawData = $_POST['fetched_data'] ?? '[]';
            $results = json_decode($rawData, true) ?: [];
            
            $defaults = [
                'purity'       => $_POST['purity'] ?? '95%',
                'product_type' => $_POST['product_type'] ?? 'Reference Standard',
                'Company_make' => 'AB Chem India',
                'availability' => 'In Stock',
                'lead_time'    => '3-5 days'
            ];
            
            try {
                $catalogResult = $fetcher->saveFetchResultsToCatalog($results, $defaults);
            } catch (Exception $e) {
                $multiError = 'Failed to save to catalog: ' . $e->getMessage();
                error_log("Catalog save error: " . $e->getMessage());
            }
        }
        
        // 📥 Download fetched results as CSV
        if (($_GET['action'] ?? '') === 'download_csv' && isset($_GET['data'])) {
            $rawData = $_GET['data'];
            $results = json_decode($rawData, true) ?: [];
            
            $csv = $fetcher->resultsToCSV($results);
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="pubchem_fetched_' . date('Ymd_His') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $csv;
            exit;
        }
    }
    
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PubChem Fetcher | AB Chem</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; background: #f8fafc; }
.card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
h1 { color: #0e7abf; margin: 0 0 8px 0; }
h3 { margin: 0 0 14px 0; color: #1e293b; }
.muted { color: #64748b; }
.btn { display: inline-block; background: #0e7abf; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; margin: 5px 5px 5px 0; font-size: 14px; font-weight: 500; }
.btn:hover { background: #0a5a8c; }
.btn-outline { background: white; color: #0e7abf; border: 2px solid #0e7abf; }
.btn-outline:hover { background: #f1f5f9; }
.btn-green { background: #16a34a; }
.btn-green:hover { background: #15803d; }
.btn-amber { background: #f59e0b; }
.btn-amber:hover { background: #d97706; }
.input-group { margin: 14px 0; }
.input-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #334155; }
.input-group input, .input-group select { padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
.input-group input:focus, .input-group select:focus, textarea:focus { outline: none; border-color: #0e7abf; }
textarea { width: 100%; padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-family: 'Courier New', monospace; resize: vertical; min-height: 130px; line-height: 1.6; }
pre { background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; max-height: 400px; }
.success { color: #16a34a; font-weight: 500; }
.error-msg { color: #ef4444; font-weight: 500; }
.info-box { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 14px 0; font-size: 14px; }
.type-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.type-row .input-group { margin: 0; flex: 0 0 auto; }
/* Results table */
.results-table-wrap { overflow-x: auto; margin-top: 8px; }
table.res { border-collapse: collapse; width: 100%; font-size: 13px; }
table.res th { background: #0e7abf; color: white; padding: 9px 12px; text-align: left; white-space: nowrap; }
table.res td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: top; word-break: break-all; }
table.res tr:nth-child(even) td { background: #f8fafc; }
table.res tr:hover td { background: #eff6ff; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
.badge-auto   { background:#dbeafe; color:#1d4ed8; }
.badge-name   { background:#dcfce7; color:#15803d; }
.badge-cid    { background:#fef9c3; color:#854d0e; }
.badge-inchikey { background:#ede9fe; color:#6d28d9; }
.badge-inchi  { background:#fce7f3; color:#9d174d; }
.badge-smiles { background:#ffedd5; color:#c2410c; }
.badge-error  { background:#fee2e2; color:#b91c1c; }
.cid-link { color: #0e7abf; text-decoration: none; font-weight: 600; }
.cid-link:hover { text-decoration: underline; }
.divider { border: none; border-top: 2px solid #e2e8f0; margin: 24px 0; }
.export-btn-row { margin-top: 12px; }
/* Catalog result boxes */
.result-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-top:10px; }
.result-box { padding:14px; border-radius:8px; text-align:center; }
.result-box.added { background:#dcfce7; border-left:4px solid #16a34a; }
.result-box.skipped { background:#fef9c3; border-left:4px solid #f59e0b; }
.result-box.errors { background:#fee2e2; border-left:4px solid #ef4444; }
.result-box h4 { margin:0 0 6px; font-size:1.5rem; }
.result-box p { margin:0; font-size:0.85rem; color:#64748b; }
</style>
</head>
<body>
<div class="card">
<h1>🔬 PubChem Data Fetcher</h1>
<p class="muted">Fetch SMILES, InChI, InChIKey, MW and more from PubChem • Multiple identifiers at once • Auto-add to catalog</p>

<div class="info-box">
<strong>Supported identifier types (one per line):</strong><br>
<strong>Name / CAS</strong> — e.g. <code>aspirin</code>, <code>50-78-2</code>, <code>caffeine</code> &nbsp;|&nbsp;
<strong>CID</strong> — e.g. <code>2244</code> &nbsp;|&nbsp;
<strong>InChIKey</strong> — e.g. <code>BSYNRYMUTXBXSQ-UHFFFAOYSA-N</code><br>
<strong>InChI</strong> — e.g. <code>InChI=1S/C9H8O4/…</code> &nbsp;|&nbsp;
<strong>SMILES</strong> — e.g. <code>CC(=O)Oc1ccccc1C(=O)O</code>
</div>

<!-- ═══════════════════════════════════════════════
MULTI-IDENTIFIER LOOKUP
═══════════════════════════════════════════════ -->
<h3 style="margin:24px 0 12px;">🔍 Multi-Identifier Lookup</h3>
<form method="post">
<div class="type-row">
<div class="input-group">
<label for="id_type">Identifier type</label>
<select name="id_type" id="id_type">
<option value="auto"    <?= ($_POST['id_type']??'auto')==='auto'    ?'selected':'' ?>>🤖 Auto-detect</option>
<option value="name"    <?= ($_POST['id_type']??'')==='name'    ?'selected':'' ?>>📛 Name / CAS</option>
<option value="cid"     <?= ($_POST['id_type']??'')==='cid'     ?'selected':'' ?>>🔢 CID</option>
<option value="inchikey"<?= ($_POST['id_type']??'')==='inchikey'?'selected':'' ?>>🔑 InChIKey</option>
<option value="inchi"   <?= ($_POST['id_type']??'')==='inchi'   ?'selected':'' ?>>🧬 InChI string</option>
<option value="smiles"  <?= ($_POST['id_type']??'')==='smiles'  ?'selected':'' ?>>🔗 SMILES</option>
</select>
</div>
</div>

<textarea
name="identifiers"
placeholder="Enter one identifier per line, e.g.&#10;aspirin&#10;50-78-2&#10;2244&#10;BSYNRYMUTXBXSQ-UHFFFAOYSA-N&#10;InChI=1S/C9H8O4/c1-6(10)13-8-5-3-2-4-7(8)9(11)12/h2-5H,1H3,(H,11,12)&#10;CC(=O)Oc1ccccc1C(=O)O"
><?= htmlspecialchars($_POST['identifiers'] ?? '') ?></textarea>

<div style="margin-top:10px;">
<button type="submit" name="action" value="multi" class="btn btn-green">🚀 Fetch All</button>
<span class="muted" style="font-size:13px; margin-left:8px;">250 ms rate-limit between requests</span>
</div>
</form>

<hr class="divider">

<!-- Batch Fetch -->
<h3>🔄 Batch Fetch Missing (CSV Products)</h3>
<form method="post">
<div class="input-group">
<label>Max items to process (0 = all)</label>
<input type="number" name="limit" placeholder="Limit (0 = all)" min="0" max="1000" value="10" style="width:130px;">
<button type="submit" name="action" value="batch" class="btn" style="vertical-align:middle;">🔄 Fetch Missing</button>
</div>
</form>

<!-- Export Only -->
<h3 style="margin-top:20px;">📦 Export</h3>
<form method="post" style="display:inline;">
<button type="submit" name="action" value="export" class="btn btn-outline">📥 Export CSV → JSON</button>
</form>
<a href="/products_data.json" target="_blank" class="btn btn-outline">👁️ View JSON</a>
</div>

<?php
/* ─── Multi-Fetch Results ─────────────────────────────────── */
if ($multiError !== null): ?>
<div class="card">
<h3>📋 Results</h3>
<p class="error-msg">❌ <?= htmlspecialchars($multiError) ?></p>
</div>

<?php elseif ($multiResults !== null):
$cols = ['input','detected_type','cid','molecular_formula','molecular_weight',
'smiles','inchi_key','inchi','iupac_name','synonyms'];
$labels = ['Input','Type','CID','Formula','MW (g/mol)',
'SMILES','InChIKey','InChI','IUPAC Name','Synonyms (first 8)'];
?>

<div class="card">
<h3>📋 Results <span class="muted" style="font-weight:400; font-size:14px;">(<?= count($multiResults) ?> identifier<?= count($multiResults)!==1?'s':'' ?>)</span></h3>

<div class="results-table-wrap">
<table class="res">
<thead>
<tr><?php foreach($labels as $l): ?><th><?= htmlspecialchars($l) ?></th><?php endforeach; ?></tr>
</thead>
<tbody>
<?php foreach ($multiResults as $r):
$isError = !empty($r['error']);
$typeClass = 'badge-' . ($r['detected_type'] ?? 'auto');
?>
<tr>
<?php foreach ($cols as $col):
$val = $r[$col] ?? '';
echo '<td>';
if ($col === 'detected_type') {
$t = htmlspecialchars($val ?: 'auto');
echo "<span class=\"badge badge-{$t}\">{$t}</span>";
if ($isError) echo " <span class=\"badge badge-error\">error</span>";
} elseif ($col === 'cid' && !empty($val)) {
echo '<a class="cid-link" href="https://pubchem.ncbi.nlm.nih.gov/compound/' . (int)$val
. '" target="_blank" title="View on PubChem">' . (int)$val . ' ↗</a>';
} elseif ($col === 'input') {
echo '<strong>' . htmlspecialchars($val) . '</strong>';
if ($isError) {
echo '<br><span class="error-msg" style="font-size:12px;">⚠ ' . htmlspecialchars($r['error']) . '</span>';
}
} else {
echo htmlspecialchars($val);
}
echo '</td>';
endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ✅ Action Buttons: Download CSV & Add to Catalog -->
<div style="background:#f0fdf4; border-left:4px solid #22c55e; padding:16px; border-radius:0 8px 8px 0; margin-top:16px;">
<h4 style="margin:0 0 12px; color:#15803d;">📦 What would you like to do with these results?</h4>

<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px;">
<!-- Download CSV Button -->
<a href="?action=download_csv&data=<?=urlencode(json_encode($multiResults))?>" 
   class="btn btn-amber">📥 Download as CSV</a>

<!-- Add to Catalog Form -->
<form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
<input type="hidden" name="action" value="add_to_catalog">
<input type="hidden" name="fetched_data" value='<?=htmlspecialchars(json_encode($multiResults))?>'>

<div class="input-group" style="margin:0;">
<label style="display:inline; font-weight:normal; font-size:0.85rem;">Default Purity</label>
<input type="text" name="purity" value="95%" style="width:90px; padding:8px;">
</div>

<div class="input-group" style="margin:0;">
<label style="display:inline; font-weight:normal; font-size:0.85rem;">Product Type</label>
<select name="product_type" style="padding:8px;">
<option>Reference Standard</option>
<option>Impurity</option>
<option>Metabolite</option>
<option>Intermediate</option>
<option>Degradant</option>
</select>
</div>

<button type="submit" class="btn btn-green">✅ Add to Live Catalog</button>
</form>
</div>

<p style="margin:0; font-size:0.85rem; color:#166534;">
<strong>Note:</strong> Adding to catalog will:
• Generate slugs from IUPAC names<br>
• Check for duplicates using InChIKey & InChI<br>
• Download structure images (OPSIN → PubChem fallback)<br>
• Make products instantly visible on your website
</p>
</div>

<!-- JSON export of results -->
<details style="margin-top:16px;">
<summary style="cursor:pointer; color:#0e7abf; font-weight:600;">📄 View raw JSON</summary>
<pre><?= htmlspecialchars(json_encode($multiResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</details>
</div>

<?php endif; ?>

<?php
/* ─── Catalog Add Results ────────────────────────────────── */
if ($catalogResult !== null): ?>
<div class="card" style="border-left:4px solid #22c55e;">
<h3>🎉 Catalog Update Complete</h3>

<div class="result-grid">
<div class="result-box added">
<h4 style="color:#15803d;"><?= count($catalogResult['added']) ?></h4>
<p>✅ Added to Catalog</p>
<?php if (!empty($catalogResult['added'])): ?>
<small style="display:block; margin-top:6px; color:#166534;">
<?= implode(', ', array_slice($catalogResult['added'], 0, 5)) ?>
<?= count($catalogResult['added']) > 5 ? '...' : '' ?>
</small>
<?php endif; ?>
</div>

<div class="result-box skipped">
<h4 style="color:#d97706;"><?= count($catalogResult['skipped']) ?></h4>
<p>⏭ Skipped (Duplicates)</p>
</div>

<div class="result-box errors">
<h4 style="color:#dc2626;"><?= count($catalogResult['errors']) ?></h4>
<p>❌ Errors</p>
</div>
</div>

<?php if (!empty($catalogResult['added'])): ?>
<div style="margin-top:16px; padding:12px; background:#f0fdf4; border-radius:6px;">
<strong style="color:#15803d;">✅ New products are now live!</strong><br>
<span class="muted" style="font-size:0.85rem;">
Visit: <code>/catalog</code> or <code>/product/<?= htmlspecialchars($catalogResult['added'][0]) ?></code>
</span>
</div>
<?php endif; ?>

<?php if (!empty($catalogResult['skipped'])): ?>
<div style="margin-top:10px; font-size:0.85rem; color:#92400e;">
<strong>Skipped:</strong> <?= htmlspecialchars(implode(', ', array_slice($catalogResult['skipped'], 0, 5))) ?>
<?= count($catalogResult['skipped']) > 5 ? '...' : '' ?>
</div>
<?php endif; ?>

<?php if (!empty($catalogResult['errors'])): ?>
<div style="margin-top:10px; font-size:0.85rem; color:#dc2626;">
<strong>Errors:</strong> <?= htmlspecialchars(implode(', ', array_slice($catalogResult['errors'], 0, 5))) ?>
<?= count($catalogResult['errors']) > 5 ? '...' : '' ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<?php
/* ─── Batch / Export Results ──────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['batch','export'])):
$fetcher = new PubChemFetcher();
$action  = $_POST['action'];

echo '<div class="card"><h3>📋 Output</h3><pre>';
try {
if ($action === 'batch') {
$limit  = max(0, min(1000, (int)($_POST['limit'] ?? 10)));
$result = $fetcher->fetchAllMissing($limit);
echo "Result: " . json_encode($result, JSON_PRETTY_PRINT);
} elseif ($action === 'export') {
$products = $fetcher->loadCSV();
$fetcher->saveJSON($products);
echo "✅ Exported " . count($products) . " products to /products_data.json";
}
} catch (Exception $e) {
echo '<span class="error-msg">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
error_log("PubChem fetcher error: " . $e->getMessage());
}
echo '</pre></div>';
endif;
?>

<div class="card">
<p style="margin:0;">
<a href="/catalog" class="btn btn-outline">← Back to Catalog</a>
<a href="/pubchem_fetch.php?test=1" class="btn btn-outline" style="margin-left:8px;">🧪 Test Connection</a>
<a href="/admin?tab=pubchem" class="btn btn-outline" style="margin-left:8px;">⚙️ Admin Tools</a>
</p>
</div>

<?php
// Quick test endpoint
if (isset($_GET['test'])) {
echo '<script>
fetch("https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/name/water/cids/JSON")
.then(r => r.json())
.then(d => console.log("✅ PubChem OK:", d))
.catch(e => console.error("❌ PubChem Error:", e));
fetch("https://www.ebi.ac.uk/opsin/ws/benzene.png", {method: "HEAD"})
.then(r => console.log("✅ OPSIN OK:", r.status))
.catch(e => console.error("❌ OPSIN Error:", e));
</script>';
}
?>
</body>
</html>
<?php
}
?>