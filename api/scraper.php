<?php
/**
 * Enimerotiko.gr Scraper → JSON API
 * Fetches https://www.enimerotiko.gr/eidiseis/, extracts article URLs + content,
 * cleans ads/internal links, saves to api/data/articles.json + history + data/articles.json
 *
 * Usage: php scraper.php       (CLI)
 *        GET /api/scraper.php  (HTTP, cron)
 */

declare(strict_types=1);

const LISTING_URL = 'https://www.enimerotiko.gr/eidiseis/';
const MAX_ARTICLES = 15; // per run
const API_DATA_DIR = __DIR__ . '/data';
const HISTORY_DIR = __DIR__ . '/history';
const OUTPUT_JSON = __DIR__ . '/data/articles.json';
const LEGACY_JSON = __DIR__ . '/../data/articles.json';
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function fetchUrl(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept-Language: el-GR,el;q=0.9,en;q=0.8', 'Accept: text/html,application/xhtml+xml'],
        CURLOPT_ENCODING => '',
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $err) throw new RuntimeException("fetch failed $url: $err");
    if ($code >= 400) throw new RuntimeException("HTTP $code for $url");
    return $html;
}

function slugify(string $title, string $fallbackUrl): string {
    $slug = mb_strtolower($title, 'UTF-8');
    // Greek transliteration map (basic)
    $map = [
        'ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o','ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y',
        'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o',
        'Ά'=>'a','Έ'=>'e','Ή'=>'i','Ί'=>'i','Ό'=>'o','Ύ'=>'y','Ώ'=>'o',
        'Α'=>'a','Β'=>'v','Γ'=>'g','Δ'=>'d','Ε'=>'e','Ζ'=>'z','Η'=>'i','Θ'=>'th','Ι'=>'i','Κ'=>'k','Λ'=>'l','Μ'=>'m','Ν'=>'n','Ξ'=>'x','Ο'=>'o','Π'=>'p','Ρ'=>'r','Σ'=>'s','Τ'=>'t','Υ'=>'y','Φ'=>'f','Χ'=>'ch','Ψ'=>'ps','Ω'=>'o',
    ];
    $slug = strtr($slug, $map);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 60);
    if (strlen($slug) < 3) {
        // fallback: use url path
        $path = parse_url($fallbackUrl, PHP_URL_PATH) ?? '';
        $base = basename(rtrim($path,'/'));
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($base));
        $base = trim(substr($base,0,40), '-');
        $slug = $base ?: substr(md5($fallbackUrl),0,12);
    }
    return $slug;
}

function extractListingUrls(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_encode_numericentity($html, [0x80,0x10FFFF,0,0x1FFFFF], 'UTF-8'));
    $xp = new DOMXPath($dom);
    $nodes = $xp->query('//div[contains(@class,"nx-article")]//a[@href]');
    $urls = [];
    foreach ($nodes as $a) {
        $href = trim($a->getAttribute('href'));
        if (!$href) continue;
        if (strpos($href,'/')===0) $href = 'https://www.enimerotiko.gr'.$href;
        if (!str_starts_with($href, 'https://www.enimerotiko.gr/')) continue;
        // filter out pagination, category-only, etc.: need at least 2 path segments and not /eidiseis/ itself
        if ($href === LISTING_URL) continue;
        if (str_contains($href, '/page/')) continue;
        // ensure it's an article: path depth >=2 and slug-like
        $path = parse_url($href, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/',$path)));
        if (count($parts) < 2) continue;
        $urls[] = strtok($href,'?#');
    }
    $urls = array_values(array_unique($urls));
    // also fallback: regex on raw html if DOM yields <5
    if (count($urls) < 5) {
        preg_match_all('/https:\/\/www\.enimerotiko\.gr\/[^"\']+\/[^"\']+\//', $html, $m);
        foreach ($m[0] as $u) {
            if (str_contains($u,'/page/')) continue;
            if ($u === LISTING_URL) continue;
            $urls[] = $u;
        }
        $urls = array_values(array_unique($urls));
    }
    return array_slice($urls, 0, MAX_ARTICLES);
}

function cleanContentHtml(string $entryHtml, string $baseUrl): string {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // wrap
    $wrapped = '<div id="root">'.$entryHtml.'</div>';
    @$dom->loadHTML(mb_encode_numericentity($wrapped, [0x80,0x10FFFF,0,0x1FFFFF], 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xp = new DOMXPath($dom);
    $root = $dom->getElementById('root');
    if (!$root) return '';

    // Remove unwanted nodes
    $removeQueries = [
        '//script','//style','//noscript','//ins','//amp-ad','//iframe[contains(@src,"doubleclick") or contains(@src,"googlesyndication")]',
        '//div[contains(@id,"div-gpt-ad")]','//div[contains(@class,"afw")]','//div[@data-ad-id]','//div[@data-widget-id]','//div[contains(@class,"nxAds")]','//div[contains(@class,"gAd")]',
        '//div[contains(@class,"sharedaddy")]','//div[contains(@class,"sharedaddy")]','//div[contains(@class,"author-social")]','//div[contains(@class,"post-tags")]','//div[contains(@class,"comments")]',
        '//div[contains(@style,"Advertisement")]','//p[contains(text(),"Advertisement")]','//div[text()="Advertisement"]',
        '//figure[contains(@class,"wp-block-image")]//figcaption', // keep fig but remove if needed?
    ];
    foreach ($removeQueries as $q) {
        $nodes = $xp->query($q);
        if (!$nodes) continue;
        for ($i=$nodes->length-1;$i>=0;$i--) {
            $n=$nodes->item($i);
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }
    // Remove divs with revive / cleon ad server
    foreach ($xp->query('//div[ins[@data-revive-zoneid]] | //div[script[contains(text(),"revive-asyncjs")]]') as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    // Unwrap internal links: <a href="enimerotiko.gr/...">text</a> -> text
    foreach ($xp->query('//a[@href]') as $a) {
        $href = $a->getAttribute('href');
        $isInternal = str_contains($href,'enimerotiko.gr') || str_starts_with($href,'/') || str_starts_with($href,'#');
        $isPharma = false;
        // keep external links? Requirement says clean internal links
        if ($isInternal) {
            // replace <a> with its text content, keep bold if inside
            $text = $dom->createTextNode($a->textContent);
            $a->parentNode->replaceChild($text, $a);
        } else {
            // external: keep but add target blank + clean
            $a->setAttribute('target','_blank');
            $a->setAttribute('rel','noopener noreferrer');
            // remove tracking params
        }
    }
    // Remove empty divs / p with no text and no img
    foreach ($xp->query('//div[not(*) and normalize-space(text())=""] | //p[not(*) and normalize-space(text())=""]') as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    // Serialize innerHTML of root
    $html = '';
    foreach ($root->childNodes as $child) {
        $html .= $dom->saveHTML($child);
    }
    $html = trim($html);
    // Final cleanup: collapse whitespace, remove Advertisement leftovers, empty comments
    $html = preg_replace('/<!--.*?-->/s','',$html);
    $html = preg_replace('/<p>\s*Advertisement\s*<\/p>/i','',$html);
    $html = preg_replace('/\s+data-[a-z-]+="[^"]*"/i','',$html); // strip leftover data attrs from ads
    // Ensure at least one <p>
    if (!str_contains($html,'<p') && strlen(strip_tags($html))>20) {
        $html = '<p>'.htmlspecialchars(strip_tags($html),ENT_QUOTES,'UTF-8').'</p>';
    }
    // Wrap loose text? already html
    return $html;
}

function extractArticleData(string $html, string $url): ?array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_encode_numericentity($html, [0x80,0x10FFFF,0,0x1FFFFF], 'UTF-8'));
    $xp = new DOMXPath($dom);

    $titleNode = $xp->query('//h1[contains(@class,"entry-title")]')->item(0);
    if (!$titleNode) return null;
    $title = trim($titleNode->textContent);
    if (!$title) return null;

    // category
    $catNode = $xp->query('//div[contains(@class,"post-category")]//a')->item(0);
    $category = $catNode ? strtolower(trim($catNode->textContent)) : 'general';
    // normalize greek categories to our 3 buckets
    $catMap = ['politiki'=>'general','news'=>'general','media-tv'=>'general','kosmos'=>'general','oikonomia'=>'business','epicheiriseis'=>'business','plus'=>'general','lifestyle'=>'general'];
    // try map, else keep general/tech/business
    if (!in_array($category, ['tech','business','general'])) {
        $catKey = strtolower(preg_replace('/[^a-z]/','',$category));
        $category = $catMap[$catKey] ?? 'general';
    }

    // image
    $img = '';
    $og = $xp->query('//meta[@property="og:image"]/@content')->item(0);
    if ($og) $img = trim($og->nodeValue);
    if (!$img) {
        $n = $xp->query('//div[contains(@class,"post-thumbnail")]//img')->item(0);
        if ($n) $img = $n->getAttribute('src') ?: $n->getAttribute('data-src');
    }
    // handle relative
    if ($img && str_starts_with($img,'/')) $img = 'https://www.enimerotiko.gr'.$img;

    // published_at
    $published = '';
    $metaPub = $xp->query('//meta[@property="article:published_time"]/@content')->item(0);
    if ($metaPub) $published = trim($metaPub->nodeValue);
    if (!$published) {
        $jsonLd = $xp->query('//script[@type="application/ld+json"]');
        foreach ($jsonLd as $s) {
            $data = json_decode($s->textContent, true);
            if (isset($data['datePublished'])) { $published = $data['datePublished']; break; }
            if (isset($data['@graph'])) {
                foreach ($data['@graph'] as $g) if (isset($g['datePublished'])) { $published=$g['datePublished']; break 2; }
            }
        }
    }
    if (!$published) $published = date('c');

    // author
    $author = 'Enimerotiko.gr';
    // try author vcard
    $authorNodes = $xp->query('//span[contains(@class,"author")] | //a[@rel="author"] | //meta[@name="author"]/@content');
    foreach ($authorNodes as $a) {
        $txt = trim($a->textContent ?? $a->nodeValue ?? '');
        if ($txt && mb_strlen($txt)>2 && !str_contains($txt,'2026')) { $author = $txt; break; }
    }
    // entry-content raw html segment
    $entryHtml = '';
    // prefer DOM extraction
    $entryNode = $xp->query('//div[contains(@class,"entry-content") and @id="main-content"]')->item(0);
    if (!$entryNode) $entryNode = $xp->query('//div[contains(@class,"entry-content")]')->item(0);
    if ($entryNode) {
        $inner = '';
        foreach ($entryNode->childNodes as $child) $inner .= $dom->saveHTML($child);
        $entryHtml = $inner;
    } else {
        // regex fallback
        if (preg_match('/<div class="column p-0 entry-content[^"]*"[^>]*>(.*?)<\/div>\s*<div class="tags/is',$html,$m)) $entryHtml=$m[1];
    }

    $cleaned = cleanContentHtml($entryHtml, $url);
    // excerpt: first 180 chars of plain text or meta description
    $excerpt = '';
    $metaDesc = $xp->query('//meta[@name="description"]/@content')->item(0);
    if ($metaDesc) $excerpt = trim($metaDesc->nodeValue);
    if (!$excerpt) {
        $plain = trim(strip_tags($cleaned));
        $excerpt = mb_substr($plain,0,180,'UTF-8');
        if (mb_strlen($plain,'UTF-8')>180) $excerpt.='…';
    } else {
        // ensure not too long
        $excerpt = mb_substr($excerpt,0,220,'UTF-8');
    }

    $slug = slugify($title, $url);

    return [
        'title' => $title,
        'slug' => $slug,
        'excerpt' => $excerpt,
        'content' => $cleaned,
        'category' => $category,
        'image_url' => $img,
        'source_url' => $url,
        'author' => $author,
        'published_at' => $published,
    ];
}

function loadExisting(): array {
    $existing = [];
    // try api/data first, then legacy
    foreach ([OUTPUT_JSON, LEGACY_JSON] as $path) {
        if (file_exists($path)) {
            $j = json_decode(file_get_contents($path), true);
            if (isset($j['articles']) && is_array($j['articles'])) {
                foreach ($j['articles'] as $a) {
                    if (isset($a['source_url'])) $existing[$a['source_url']] = $a;
                }
                // prefer most recent file for ordering
                if (!empty($existing)) break;
            }
        }
    }
    // also load history files
    if (is_dir(HISTORY_DIR)) {
        foreach (glob(HISTORY_DIR.'/*.json') as $f) {
            $j = json_decode(file_get_contents($f), true);
            if (isset($j['source_url']) && !isset($existing[$j['source_url']])) $existing[$j['source_url']]=$j;
        }
    }
    return $existing;
}

function main(): void {
    $isCli = php_sapi_name()==='cli';
    if (!$isCli) header('Content-Type: application/json; charset=utf-8');

    try {
        echo $isCli ? "[scraper] fetching ".LISTING_URL." ...\n" : '';
        $listingHtml = fetchUrl(LISTING_URL);
        $urls = extractListingUrls($listingHtml);
        if (!$isCli) {} else echo "[scraper] found ".count($urls)." urls\n";

        $existing = loadExisting();
        $new = 0; $updated=0; $skipped=0;

        foreach ($urls as $url) {
            if (isset($existing[$url])) { $skipped++; continue; } // history dedupe
            try {
                $html = fetchUrl($url);
                $data = extractArticleData($html, $url);
                if (!$data) { $skipped++; continue; }
                // ensure history & existing
                $existing[$url] = $data;
                $new++;
                // delay politely
                usleep(200000); // 0.2s
                if ($isCli) echo "  + {$data['slug']} | {$data['title']}\n";
            } catch (Throwable $e) {
                if ($isCli) echo "  ! failed $url : ".$e->getMessage()."\n";
                $skipped++;
            }
        }

        // Sort by published_at DESC
        uasort($existing, fn($a,$b)=> strcmp($b['published_at'],$a['published_at']));
        $articles = array_values($existing);

        // Ensure dirs
        @mkdir(API_DATA_DIR,0777,true);
        @mkdir(HISTORY_DIR,0777,true);
        @mkdir(dirname(LEGACY_JSON),0777,true);

        $payload = [
            'generated_at' => date('c'),
            'count' => count($articles),
            'articles' => $articles,
        ];

        // Write api/data/articles.json
        file_put_contents(OUTPUT_JSON, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        // Write legacy for backward compat
        file_put_contents(LEGACY_JSON, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

        // Write per-article history
        foreach ($articles as $a) {
            $slug = $a['slug'] ?? slugify($a['title'],$a['source_url']);
            $path = HISTORY_DIR . '/' . $slug . '.json';
            if (!file_exists($path)) {
                file_put_contents($path, json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
            }
        }

        $result = ['ok'=>true,'found'=>count($urls),'new'=>$new,'skipped'=>$skipped,'total'=>count($articles),'generated_at'=>$payload['generated_at']];
        if ($isCli) echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        else echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        $err = ['ok'=>false,'error'=>$e->getMessage()];
        if ($isCli) fwrite(STDERR, json_encode($err)."\n");
        else { http_response_code(500); echo json_encode($err,JSON_UNESCAPED_UNICODE); }
        exit(1);
    }
}

main();
