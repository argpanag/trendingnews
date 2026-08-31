<?php
/**
 * API endpoint: GET /api/ or /api/index.php
 * Now day-split: api/data/YYYY-MM-DD.json + api/data/index.json
 * Query: ?date=2026-08-31 | ?category=tech&limit=20&search=kypseli&slug=...
 * Legacy fallback: api/data/articles.json
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function loadArticles(?string $date = null): array {
    $dataDir = __DIR__ . '/data';
    $articles = [];
    $generated = null;

    if ($date) {
        // single day requested
        $path = $dataDir . '/' . $date . '.json';
        if (!file_exists($path)) return [null, null];
        $j = json_decode(file_get_contents($path), true);
        return [$j['articles'] ?? [], $j['generated_at'] ?? null];
    }

    // try day-split index first
    $indexPath = $dataDir . '/index.json';
    if (file_exists($indexPath)) {
        $idx = json_decode(file_get_contents($indexPath), true);
        $generated = $idx['generated_at'] ?? null;
        foreach ($idx['days'] ?? [] as $d) {
            $p = $dataDir . '/' . ($d['file'] ?? ($d['date'] . '.json'));
            if (!file_exists($p)) continue;
            $j = json_decode(file_get_contents($p), true);
            foreach (($j['articles'] ?? []) as $a) $articles[] = $a;
        }
        if (!empty($articles)) return [$articles, $generated];
    }

    // fallback: aggregated articles.json
    $path = $dataDir . '/articles.json';
    if (!file_exists($path)) $path = __DIR__ . '/../data/articles.json';
    if (!file_exists($path)) return [null, null];
    $j = json_decode(file_get_contents($path), true);
    return [$j['articles'] ?? null, $j['generated_at'] ?? null];
}

$date = $_GET['date'] ?? null;
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error'=>'invalid date format, use YYYY-MM-DD'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Special: ?index=1 returns index.json directly
if (isset($_GET['index'])) {
    $p = __DIR__ . '/data/index.json';
    if (!file_exists($p)) { http_response_code(404); echo json_encode(['error'=>'index not found'], JSON_UNESCAPED_UNICODE); exit; }
    header('Content-Type: application/json; charset=utf-8');
    readfile($p);
    exit;
}

[$articles, $generatedAt] = loadArticles($date);
if ($articles === null) {
    http_response_code(404);
    echo json_encode(['error'=> $date ? "no data for $date" : 'articles.json not found. Run scraper.php','articles'=>[],'count'=>0], JSON_UNESCAPED_UNICODE);
    exit;
}

// filters
$category = $_GET['category'] ?? null;
$search = $_GET['search'] ?? null;
$slug = $_GET['slug'] ?? null;
$limit = isset($_GET['limit']) ? max(1,min(100,(int)$_GET['limit'])) : null;

if ($slug) {
    $found = null;
    foreach ($articles as $a) if (($a['slug'] ?? '') === $slug) { $found = $a; break; }
    // also check history file directly
    if (!$found) {
        $h = __DIR__ . '/history/' . $slug . '.json';
        if (file_exists($h)) $found = json_decode(file_get_contents($h), true);
    }
    if ($found) echo json_encode($found, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    else { http_response_code(404); echo json_encode(['error'=>'not found'], JSON_UNESCAPED_UNICODE); }
    exit;
}
if ($category && $category !== 'all') {
    $articles = array_values(array_filter($articles, fn($a)=> ($a['category'] ?? 'general') === $category));
}
if ($search) {
    $q = mb_strtolower($search,'UTF-8');
    $articles = array_values(array_filter($articles, function($a) use($q){
        return str_contains(mb_strtolower($a['title']??'','UTF-8'),$q)
            || str_contains(mb_strtolower($a['excerpt']??'','UTF-8'),$q)
            || str_contains(mb_strtolower($a['category']??'','UTF-8'),$q);
    }));
}
if ($limit) $articles = array_slice($articles,0,$limit);

echo json_encode([
    'generated_at' => $generatedAt ?? date('c'),
    'count' => count($articles),
    'total' => count($articles),
    'articles' => $articles,
    'date' => $date,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
