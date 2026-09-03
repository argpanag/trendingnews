<?php
$j = json_decode(file_get_contents('api/data/index.json'), true);
echo "Days: " . count($j['days'] ?? []) . "\n";
foreach (($j['days'] ?? []) as $d) {
    $p = 'api/data/' . ($d['file'] ?? ($d['date'] . '.json'));
    $day = json_decode(file_get_contents($p), true);
    echo "Date: " . ($d['date'] ?? $d['file']) . " - Articles: " . ($day['count'] ?? count($day['articles'] ?? [])) . "\n";
    foreach ($day['articles'] ?? [] as $a) {
        echo "  - " . ($a['slug'] ?? 'N/A') . "\n";
    }
}
