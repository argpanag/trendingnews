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
// SEO
const SITE_URL = 'https://thetools.com'; // change to your domain or GitHub Pages URL (e.g. https://user.github.io/thetools.com)
const SEO_DIR = __DIR__ . '/../articles'; // static HTML per article: articles/<slug>/index.html

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

function cleanContentHtml(string $entryHtml, string $baseUrl, string $featuredImage = ''): string {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // wrap
    $wrapped = '<div id="root">'.$entryHtml.'</div>';
    @$dom->loadHTML(mb_encode_numericentity($wrapped, [0x80,0x10FFFF,0,0x1FFFFF], 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xp = new DOMXPath($dom);
    $root = $dom->getElementById('root');
    if (!$root) return '';

    // Replace Glomex videos with featured image + play button linking to iframe source
    $iframes = $xp->query('//iframe');
    for ($i = $iframes->length - 1; $i >= 0; $i--) {
        $iframe = $iframes->item($i);
        $src = $iframe->getAttribute('src') ?: $iframe->getAttribute('data-src') ?: $iframe->getAttribute('data-srcset') ?: '';
        if ($src !== '' && stripos($src, 'glomex') !== false) {
            $a = $dom->createElement('a');
            $a->setAttribute('href', $src);
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer');
            $a->setAttribute('class', 'glomex-replacement');
            $a->setAttribute('style', 'display:block;position:relative;text-decoration:none;margin:16px 0;border-radius:10px;overflow:hidden;');

            $imgSrc = $featuredImage ?: 'https://via.placeholder.com/800x450?text=Video';
            $img = $dom->createElement('img');
            $img->setAttribute('src', $imgSrc);
            $img->setAttribute('alt', 'Video thumbnail');
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('style', 'width:100%;height:auto;display:block;');
            $a->appendChild($img);

            $overlay = $dom->createElement('span');
            $overlay->setAttribute('style', 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:68px;background:rgba(0,0,0,0.65);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px;line-height:1;box-shadow:0 2px 10px rgba(0,0,0,0.4);');
            $overlay->textContent = '▶';
            $a->appendChild($overlay);

            $wrapper = $iframe->parentNode;
            if ($wrapper && $wrapper->nodeName === 'div' && stripos($wrapper->getAttribute('style'), 'aspect-ratio') !== false) {
                $wrapper->parentNode->replaceChild($a, $wrapper);
            } elseif ($iframe->parentNode) {
                $iframe->parentNode->replaceChild($a, $iframe);
            }
            continue;
        }
        // Clean empty/lazy iframes without src (e.g., YouTube placeholder with no data-src)
        $hasSrc = trim($src) !== '' || trim($iframe->getAttribute('srcdoc') ?? '') !== '';
        if (!$hasSrc) {
            // Check if iframe is likely a video placeholder (has title or is inside aspect-ratio wrapper)
            $isVideoPlaceholder = $iframe->hasAttribute('title') || $iframe->hasAttribute('allowfullscreen');
            $wrapper = $iframe->parentNode;
            $isInVideoWrapper = $wrapper && $wrapper->nodeName === 'div' && (stripos($wrapper->getAttribute('style'), 'aspect-ratio') !== false || stripos($wrapper->getAttribute('class'), 'video') !== false || stripos($wrapper->getAttribute('class'), 'intext-video') !== false);
            if ($isVideoPlaceholder || $isInVideoWrapper) {
                // Remove empty video iframe entirely (or replace with featured image if you prefer)
                $target = $isInVideoWrapper ? $wrapper : $iframe;
                if ($target->parentNode) $target->parentNode->removeChild($target);
            }
        }
    }

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
    // Convert lazy data-src to src for remaining iframes (e.g., YouTube)
    foreach ($xp->query('//iframe[@data-src]') as $iframe) {
        if (!$iframe->getAttribute('src')) {
            $iframe->setAttribute('src', $iframe->getAttribute('data-src'));
        }
        $iframe->removeAttribute('data-src');
        $iframe->removeAttribute('data-srcset');
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

    $cleaned = cleanContentHtml($entryHtml, $url, $img);
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
    // try per-day files first (new split), then legacy aggregated
    if (is_dir(API_DATA_DIR)) {
        foreach (glob(API_DATA_DIR . '/20*.json') as $path) {
            if (basename($path) === 'index.json') continue;
            if (basename($path) === 'articles.json') continue;
            $j = json_decode(file_get_contents($path), true);
            if (isset($j['articles']) && is_array($j['articles'])) {
                foreach ($j['articles'] as $a) if (isset($a['source_url'])) $existing[$a['source_url']] = $a;
            } elseif (isset($j['source_url'])) {
                if (isset($j['source_url'])) $existing[$j['source_url']] = $j;
            }
        }
        if (!empty($existing)) {
            // also load history to fill gaps
            if (is_dir(HISTORY_DIR)) {
                foreach (glob(HISTORY_DIR.'/*.json') as $f) {
                    $j = json_decode(file_get_contents($f), true);
                    if (isset($j['source_url']) && !isset($existing[$j['source_url']])) $existing[$j['source_url']]=$j;
                }
            }
            return $existing;
        }
    }
    // fallback: try api/data/articles.json + legacy
    foreach ([OUTPUT_JSON, LEGACY_JSON] as $path) {
        if (file_exists($path)) {
            $j = json_decode(file_get_contents($path), true);
            if (isset($j['articles']) && is_array($j['articles'])) {
                foreach ($j['articles'] as $a) {
                    if (isset($a['source_url'])) $existing[$a['source_url']] = $a;
                }
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

function dateKey(string $iso): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $iso, $m)) return $m[1];
    try { return (new DateTime($iso))->format('Y-m-d'); } catch (Throwable $e) { return date('Y-m-d'); }
}

function escHtml(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildSeoHtml(array $a): string {
    $rawTitle = $a['title'] ?? 'Untitled';
    $rawExcerpt = $a['excerpt'] ?? mb_substr(strip_tags($a['content'] ?? ''), 0, 155, 'UTF-8');
    $rawExcerpt = trim(preg_replace('/\s+/', ' ', $rawExcerpt));
    $title = escHtml($rawTitle);
    $excerpt = escHtml($rawExcerpt);
    $slug = $a['slug'] ?? 'unknown';
    $url = rtrim(SITE_URL, '/') . '/articles/' . $slug . '/';
    $urlEsc = escHtml($url);
    $img = escHtml($a['image_url'] ?? '');
    $author = escHtml($a['author'] ?? 'thetools.com');
    $published = $a['published_at'] ?? date('c');
    try { $iso = (new DateTime($published))->format('c'); } catch (Throwable $e) { $iso = $published; }
    $isoEsc = escHtml($iso);
    $humanDate = escHtml(date('d.m.Y H:i', strtotime($published) ?: time()));
    $content = $a['content'] ?? ''; // already cleaned HTML, trust
    $category = escHtml($a['category'] ?? 'general');
    $sourceUrl = escHtml($a['source_url'] ?? '#');
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $rawTitle,
        'description' => $rawExcerpt,
        'image' => $a['image_url'] ? [$a['image_url']] : [],
        'datePublished' => $iso,
        'dateModified' => $iso,
        'author' => ['@type'=>'Person','name'=>$a['author'] ?? 'thetools.com'],
        'publisher' => ['@type'=>'Organization','name'=>'thetools.com','logo'=>['@type'=>'ImageObject','url'=> rtrim(SITE_URL,'/').'/css/style.css']],
        'mainEntityOfPage' => ['@type'=>'WebPage','@id'=>$url],
    ];
    $jsonLdStr = json_encode($jsonLd, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title} | thetools.com</title>
  <meta name="description" content="{$excerpt}" />
  <link rel="canonical" href="{$urlEsc}" />
  <meta property="og:type" content="article" />
  <meta property="og:locale" content="el_GR" />
  <meta property="og:title" content="{$title}" />
  <meta property="og:description" content="{$excerpt}" />
  <meta property="og:url" content="{$urlEsc}" />
  <meta property="og:site_name" content="thetools.com" />
  <meta property="og:image" content="{$img}" />
  <meta property="article:published_time" content="{$isoEsc}" />
  <meta property="article:author" content="{$author}" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="{$title}" />
  <meta name="twitter:description" content="{$excerpt}" />
  <meta name="twitter:image" content="{$img}" />
  <link rel="stylesheet" href="../../css/style.css" />
  <script type="application/ld+json">{$jsonLdStr}</script>
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="logo" href="../../">thetools<span>.com</span></a>
      <nav class="nav">
        <a href="../../" class="filter-btn">← Αρχική</a>
        <span class="badge">{$category}</span>
      </nav>
    </div>
  </header>
  <main class="wrap">
    <article class="detail">
      <a href="../../">← Πίσω</a>
      <img src="{$img}" alt="" loading="lazy" onerror="this.style.display='none'" style="width:100%;max-height:420px;object-fit:cover;border-radius:10px;margin-top:12px" />
      <div class="badge">{$category}</div>
      <h1>{$title}</h1>
      <div style="color:#6b7280;font-size:.9rem">{$author} · {$humanDate} · <a href="{$sourceUrl}" target="_blank" rel="noopener">πηγή</a> · <a href="{$urlEsc}">μόνιμος σύνδεσμος</a></div>
      <div class="content">{$content}</div>
      <hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb" />
      <p style="color:#6b7280;font-size:.85rem">Άρθρο από <a href="{$sourceUrl}" target="_blank" rel="noopener">enimerotiko.gr</a> · αρχειοθετήθηκε στο thetools.com</p>
    </article>
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p>Built with vanilla JS · PHP scraper → JSON · split by day · SEO static</p>
      <p><a href="../../">thetools.com</a> · <a href="../../api/data/index.json">API index</a></p>
    </div>
  </footer>
</body>
</html>
HTML;
}

function buildSitemap(array $articles, string $siteUrl): string {
    $base = rtrim($siteUrl, '/');
    $now = date('c');
    $urls = [];
    $urls[] = "  <url><loc>{$base}/</loc><lastmod>{$now}</lastmod><changefreq>hourly</changefreq><priority>1.0</priority></url>";
    foreach ($articles as $a) {
        $slug = $a['slug'] ?? '';
        if (!$slug) continue;
        $loc = $base . '/articles/' . $slug . '/';
        $lastmod = $a['published_at'] ?? $now;
        try { $lastmod = (new DateTime($lastmod))->format('c'); } catch (Throwable $e) {}
        $locEsc = escHtml($loc);
        $urls[] = "  <url><loc>{$locEsc}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>";
    }
    $body = implode("\n", $urls);
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{$body}
</urlset>
XML;
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

        // Retroactively fix existing articles that still contain glomex iframes (migrate old data)
        foreach ($articles as &$art) {
            if (isset($art['content']) && stripos($art['content'], 'glomex') !== false) {
                $art['content'] = cleanContentHtml($art['content'], $art['source_url'] ?? '', $art['image_url'] ?? '');
            }
        }
        unset($art);
        foreach ($existing as $k => &$v) {
            if (isset($v['content']) && stripos($v['content'], 'glomex') !== false) {
                $v['content'] = cleanContentHtml($v['content'], $v['source_url'] ?? $k, $v['image_url'] ?? '');
                // sync to articles
                foreach ($articles as &$a2) if (($a2['source_url'] ?? '') === ($v['source_url'] ?? $k)) { $a2['content'] = $v['content']; break; }
                unset($a2);
            }
        }
        unset($v);

        // Ensure dirs
        @mkdir(API_DATA_DIR,0777,true);
        @mkdir(HISTORY_DIR,0777,true);
        @mkdir(dirname(LEGACY_JSON),0777,true);

        // Group by day
        $byDay = [];
        foreach ($articles as $a) {
            $d = dateKey($a['published_at'] ?? date('c'));
            $byDay[$d][] = $a;
        }
        krsort($byDay); // newest day first

        $payload = [
            'generated_at' => date('c'),
            'count' => count($articles),
            'articles' => $articles,
        ];

        // Write per-day files: api/data/YYYY-MM-DD.json
        foreach ($byDay as $date => $list) {
            $dayPayload = [
                'generated_at' => date('c'),
                'date' => $date,
                'count' => count($list),
                'articles' => array_values($list),
            ];
            file_put_contents(API_DATA_DIR . "/$date.json", json_encode($dayPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        }

        // Write index: api/data/index.json
        $index = [
            'generated_at' => date('c'),
            'total' => count($articles),
            'days' => array_map(fn($date) => [
                'date' => $date,
                'count' => count($byDay[$date]),
                'file' => "$date.json"
            ], array_keys($byDay)),
        ];
        file_put_contents(API_DATA_DIR . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

        // Keep aggregated for backward compat (but now split is primary)
        file_put_contents(OUTPUT_JSON, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        file_put_contents(LEGACY_JSON, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

        // Write per-article history
        foreach ($articles as $a) {
            $slug = $a['slug'] ?? slugify($a['title'],$a['source_url']);
            $path = HISTORY_DIR . '/' . $slug . '.json';
            if (!file_exists($path)) {
                file_put_contents($path, json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
            }
        }

        // Generate SEO static HTML per article (full HTML, no JS needed for indexing)
        @mkdir(SEO_DIR, 0777, true);
        foreach ($articles as $a) {
            $slug = $a['slug'] ?? slugify($a['title'],$a['source_url']);
            $dir = SEO_DIR . '/' . $slug;
            @mkdir($dir, 0777, true);
            $html = buildSeoHtml($a);
            file_put_contents($dir . '/index.html', $html);
        }

        // Generate sitemap.xml and robots.txt at root
        $sitemap = buildSitemap($articles, SITE_URL);
        file_put_contents(__DIR__ . '/../sitemap.xml', $sitemap);
        $robots = "User-agent: *\nAllow: /\nSitemap: " . rtrim(SITE_URL,'/') . "/sitemap.xml\n";
        if (!file_exists(__DIR__ . '/../robots.txt') || strpos(file_get_contents(__DIR__ . '/../robots.txt'), 'Sitemap:') === false) {
            file_put_contents(__DIR__ . '/../robots.txt', $robots);
        }

        $result = ['ok'=>true,'found'=>count($urls),'new'=>$new,'skipped'=>$skipped,'total'=>count($articles),'days'=>count($byDay),'seo'=>count($articles),'generated_at'=>$payload['generated_at']];
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
