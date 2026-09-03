<?php
/**
 * Remove articles with non-Latin characters from JSON data and HTML files.
 * Usage: php cleanup_nonlatin.php            (dry-run)
 *        php cleanup_nonlatin.php --apply    (apply cleanup)
 */
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/api/data';
const TRENDS_DIR = __DIR__ . '/api/data/trends';
const ARTICLES_DIR = __DIR__ . '/articles';
const HISTORY_DIR = __DIR__ . '/api/history_trends';

function isLatinText(string $text): bool {
    $text = trim($text);
    if (strlen($text) === 0) return false;
    $cleaned = preg_replace('/[\s\d\p{P}\p{S}\p{Sc}]+/u', '', $text);
    if (strlen($cleaned) === 0) return true;
    $latin = preg_match_all('/[\p{Latin}\p{Greek}\p{Common}]/u', $cleaned);
    $total = preg_match_all('/./u', $cleaned);
    if ($total === 0) return true;
    return ($latin / $total) > 0.6;
}

function isApply(): bool {
    global $argv;
    return in_array('--apply', $argv ?? []);
}

$apply = isApply();
$deleted = 0;
$skipped = 0;

echo "=== Non-Latin Cleanup ===\n";
echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n\n";

// 1. Clean trend JSON files
echo "--- Cleaning JSON data files ---\n";
foreach (glob(TRENDS_DIR . '/*/20*.json') as $file) {
    $j = json_decode(file_get_contents($file), true);
    if (!isset($j['articles'])) continue;

    $original = count($j['articles']);
    $filtered = array_values(array_filter($j['articles'], function ($a) {
        return isLatinText($a['title'] ?? '');
    }));
    $removed = $original - count($filtered);

    if ($removed > 0) {
        echo "  [FILE] " . basename(dirname($file)) . "/" . basename($file) . ": removing {$removed} non-Latin articles\n";
        if ($apply) {
            $j['articles'] = $filtered;
            $j['count'] = count($filtered);
            file_put_contents($file, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        $deleted += $removed;
    }
}

// 2. Clean country index.json files
echo "\n--- Cleaning country index files ---\n";
foreach (glob(TRENDS_DIR . '/*/index.json') as $file) {
    $j = json_decode(file_get_contents($file), true);
    $changed = false;

    foreach ($j['days'] ?? [] as $idx => $day) {
        $dayFile = dirname($file) . '/' . ($day['file'] ?? ($day['date'] . '.json'));
        if (!file_exists($dayFile)) continue;

        $dayData = json_decode(file_get_contents($dayFile), true);
        if (!isset($dayData['articles'])) continue;

        $original = count($dayData['articles']);
        $filtered = array_values(array_filter($dayData['articles'], function ($a) {
            return isLatinText($a['title'] ?? '');
        }));

        if (count($filtered) !== $original) {
            $j['days'][$idx]['count'] = count($filtered);
            $changed = true;
        }
    }

    if ($changed && $apply) {
        file_put_contents($file, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

// 3. Delete article HTML directories with non-Latin titles
echo "\n--- Cleaning article HTML files ---\n";
foreach (glob(ARTICLES_DIR . '/*/index.html') as $file) {
    $html = file_get_contents($file);
    if (preg_match('/<title>(.*?)<\/title>/s', $html, $m)) {
        $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
        $title = str_replace(' | trends-online.com', '', $title);

        if (!isLatinText($title)) {
            $slug = basename(dirname($file));
            echo "  [DELETE] articles/{$slug}/ (title: " . mb_substr($title, 0, 50) . ")\n";
            if ($apply) {
                @unlink($file);
                @rmdir(dirname($file));
            }
            $deleted++;
        }
    }
}

// 4. Clean history files
echo "\n--- Cleaning history files ---\n";
foreach (glob(HISTORY_DIR . '/*.json') as $file) {
    $j = json_decode(file_get_contents($file), true);
    if (isset($j['title']) && !isLatinText($j['title'])) {
        echo "  [DELETE] " . basename($file) . "\n";
        if ($apply) @unlink($file);
        $deleted++;
    }
}

echo "\n=== Summary ===\n";
echo "Deleted: {$deleted}\n";
if (!$apply && $deleted > 0) {
    echo "\nRun with --apply to apply cleanup.\n";
}
