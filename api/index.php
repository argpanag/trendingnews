<?php
/**
 * API endpoint: GET /api/ or /api/index.php
 * Serves api/data/articles.json with CORS + filtering
 * Query: ?category=tech&limit=20&search=kypseli&slug=...
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$path = __DIR__ . '/data/articles.json';
if (!file_exists($path)) {
    // fallback to legacy
    $path = __DIR__ . '/../data/articles.json';
}
if (!file_exists($path)) {
    http_response_code(404);
    echo json_encode(['error'=>'articles.json not found. Run scraper.php','articles'=>[],'count'=>0], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = json_decode(file_get_contents($path), true);
if (!$data || !isset($data['articles'])) {
    http_response_code(500);
    echo json_encode(['error'=>'invalid json','count'=>0,'articles'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
}
$articles = $data['articles'];

// filters
$category = $_GET['category'] ?? null;
$search = $_GET['search'] ?? null;
$slug = $_GET['slug'] ?? null;
$limit = isset($_GET['limit']) ? max(1,min(100,(int)$_GET['limit'])) : null;

if ($slug) {
    $found = null;
    foreach ($articles as $a) if (($a['slug'] ?? '') === $slug) { $found = $a; break; }
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
    'generated_at' => $data['generated_at'] ?? date('c'),
    'count' => count($articles),
    'total' => $data['count'] ?? count($data['articles']),
    'articles' => $articles,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
