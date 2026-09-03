<?php
/**
 * Orchestrator: scrape trends + auto-rebuild + push to GitHub.
 *
 * Usage via browser/URL:
 *   /api/run.php?all=true           Scrape all countries + rebuild + push
 *   /api/run.php?tier=1             Scrape tier-1 + rebuild + push
 *   /api/run.php?tier=2             Scrape tier-2 + rebuild + push
 *   /api/run.php?tier=3             Scrape tier-3 + rebuild + push
 *   /api/run.php?country=US         Scrape single country + rebuild + push
 *   /api/run.php?all=true&force     Force reload existing data + push
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
$TIERS = $config['tiers'];
$DELAYS = [1 => $config['scrape_delay_tier1'], 2 => $config['scrape_delay_tier2'], 3 => $config['scrape_delay_tier3']];

function runScraper(string $country, bool $force): array {
    $script = __DIR__ . '/trends_scraper.php';
    $cmd = "php " . escapeshellarg($script) . " --country=" . escapeshellarg($country);
    if ($force) $cmd .= " --force";

    $output = [];
    $exitCode = 0;
    exec($cmd . " 2>&1", $output, $exitCode);

    $lastLine = end($output);
    $result = json_decode($lastLine, true);

    return [
        'country' => $country,
        'ok' => $exitCode === 0 && ($result['ok'] ?? false),
        'new' => $result['new'] ?? 0,
        'skipped' => $result['skipped'] ?? 0,
        'updated' => $result['updated'] ?? 0,
        'total' => $result['total'] ?? 0,
        'trends' => $result['trends'] ?? 0,
        'error' => $result['error'] ?? null,
    ];
}

function runBuild(): array {
    $base = __DIR__;
    $output = [];
    $exitCode = 0;

    exec("php " . escapeshellarg($base . '/build_index.php') . " 2>&1", $output, $exitCode);
    $indexOk = $exitCode === 0;

    $output = [];
    $exitCode = 0;
    exec("php " . escapeshellarg($base . '/rebuild_articles.php') . " 2>&1", $output, $exitCode);
    $rebuildOk = $exitCode === 0;

    return ['index' => $indexOk, 'articles' => $rebuildOk];
}

function pushToGithub(): array {
    $root = __DIR__ . '/..';
    $commands = [
        'git add -A',
        'git diff --cached --quiet || git commit -m "chore: manual update ' . date('Y-m-d\TH:i:s') . '"',
        'git push',
    ];

    $output = [];
    $exitCode = 0;
    $committed = false;

    foreach ($commands as $cmd) {
        $output = [];
        $exitCode = 0;
        exec("cd " . escapeshellarg($root) . " && {$cmd} 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => implode("\n", $output)];
        }
        if (str_starts_with($cmd, 'git diff')) {
            $committed = !str_contains(implode($output), 'nothing to commit');
        }
    }

    return ['ok' => true, 'committed' => $committed];
}

function main(): void {
    global $TIERS, $DELAYS;

    $isCli = php_sapi_name() === 'cli';
    if (!$isCli) {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
    }

    $force = isset($_GET['force']) || isset($_GET['reload']) || isset($_GET['refresh']);
    $countries = [];
    $mode = 'unknown';
    $tier = null;

    if (isset($_GET['all'])) {
        $mode = 'all';
        $countries = array_merge(...array_values($TIERS));
    } elseif (isset($_GET['tier']) && in_array((int)$_GET['tier'], [1, 2, 3])) {
        $tier = (int)$_GET['tier'];
        $mode = 'tier';
        $countries = $TIERS[$tier];
    } elseif (isset($_GET['country']) && preg_match('/^[A-Z]{2}$/i', $_GET['country'])) {
        $mode = 'single';
        $countries = [strtoupper($_GET['country'])];
    } else {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing parameter. Use: ?all=true | ?tier=1|2|3 | ?country=XX',
            'usage' => [
                '/api/run.php?all=true',
                '/api/run.php?tier=1',
                '/api/run.php?country=US&force',
            ]
        ]);
        return;
    }

    $startTime = time();
    $results = [];
    $totalNew = 0;
    $totalSkipped = 0;
    $totalUpdated = 0;
    $errors = [];

    foreach ($countries as $country) {
        $result = runScraper($country, $force);
        $results[] = $result;

        if ($result['ok']) {
            $totalNew += $result['new'];
            $totalSkipped += $result['skipped'];
            $totalUpdated += $result['updated'];
        } else {
            $errors[] = $country . ': ' . ($result['error'] ?? 'scrape failed');
        }

        $tierNum = 1;
        foreach ($TIERS as $t => $list) {
            if (in_array($country, $list)) { $tierNum = $t; break; }
        }
        $delay = $DELAYS[$tierNum] ?? 2;
        if ($country !== end($countries)) {
            sleep($delay);
        }
    }

    $build = runBuild();
    $push = pushToGithub();
    $elapsed = time() - $startTime;

    $response = [
        'ok' => empty($errors),
        'mode' => $mode,
        'tier' => $tier,
        'force' => $force,
        'countries_scraped' => count($countries),
        'countries' => $countries,
        'new_articles' => $totalNew,
        'skipped' => $totalSkipped,
        'updated' => $totalUpdated,
        'rebuild' => $build,
        'push' => $push,
        'errors' => $errors,
        'elapsed_seconds' => $elapsed,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

main();
