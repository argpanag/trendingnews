<?php
/**
 * Site configuration — edit these values when deploying or rebranding.
 * All PHP scripts (run.php, build_index.php, trends_scraper.php, etc.) should include this file.
 */
return [
    // Site identity
    'site_name'    => 'trends-online.com',
    'site_url'     => 'https://trends-online.com',
    'site_locale'  => 'el',
    'site_lang'    => 'en',

    // Scraping
    'max_trends'        => 15,
    'scrape_delay_tier1' => 5,
    'scrape_delay_tier2' => 3,
    'scrape_delay_tier3' => 2,

    // Pagination
    'articles_per_page' => 9,

    // Allowed character sets (articles with other scripts are skipped)
    'allowed_scripts' => ['Latin', 'Greek', 'Common'],
    'latin_threshold' => 0.6,

    // Analytics — leave empty to disable
    'google_analytics_id' => '',   // e.g. 'G-XXXXXXXXXX'
    'microsoft_clarity_id' => '',  // e.g. 'xxxxxxxxxx'

    // Tier definitions
    'tiers' => [
        1 => ['US', 'GB', 'DE', 'FR', 'JP'],
        2 => ['AU', 'CA', 'IT', 'ES', 'BR', 'IN', 'KR', 'MX', 'NL', 'SE'],
        3 => ['PL', 'PT', 'CH', 'AT', 'BE', 'DK', 'NO', 'FI', 'IE', 'NZ',
              'SG', 'HK', 'TW', 'TH', 'VN', 'ID', 'MY', 'PH', 'CZ', 'RO',
              'HU', 'BG', 'HR', 'SK', 'SI', 'LT', 'LV', 'EE'],
    ],
];
