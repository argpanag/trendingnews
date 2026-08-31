<?php
/**
 * Google Trends USA → JSON + SEO
 * Reads https://trends.google.com/trending/rss?geo=US (Daily Search Trends)
 * Extracts trending queries + ht:news_item URLs, scrapes each news article generically,
 * saves to api/data/trends/YYYY-MM-DD.json + history + SEO (articles/trends/<slug>/)
 *
 * Usage: php api/trends_scraper.php
 *        GET /api/trends_scraper.php?limit=12
 */
declare(strict_types=1);
const TRENDS_RSS = 'https://trends.google.com/trending/rss?geo=US';
const TRENDS_DATA_DIR = __DIR__ . '/data/trends';
const TRENDS_HISTORY_DIR = __DIR__ . '/history_trends';
const TRENDS_SEO_DIR = __DIR__ . '/../articles/trends';
const SITE_URL_TRENDS = 'https://thetools.com';
const MAX_TRENDS = 15;
const USER_AGENT_T = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

function fetchUrlT(string $url): string {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_USERAGENT=>USER_AGENT_T,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8','Accept-Language: en-US,en;q=0.9']]);
    $html=curl_exec($ch); $err=curl_error($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($html===false||$err) throw new RuntimeException("fetch $url: $err");
    if($code>=400) throw new RuntimeException("HTTP $code $url");
    return $html;
}
function slugifyT(string $title, string $url): string {
    $s=mb_strtolower($title,'UTF-8');
    $s=preg_replace('/[^a-z0-9]+/','-',$s);
    $s=trim($s,'-'); $s=substr($s,0,60);
    if(strlen($s)<3){ $p=parse_url($url,PHP_URL_PATH); $b=basename(rtrim($p,'/')); $b=preg_replace('/[^a-z0-9]+/','-',strtolower($b)); $b=trim(substr($b,0,40),'-'); $s=$b?:substr(md5($url),0,12); }
    return $s;
}
function cleanGeneric(string $html, string $imgFallback=''): string {
    libxml_use_internal_errors(true);
    $dom=new DOMDocument(); @$dom->loadHTML(mb_encode_numericentity($html,[0x80,0x10FFFF,0,0x1FFFFF],'UTF-8'));
    $xp=new DOMXPath($dom);
    // Try to find main content: article, entry-content, post-content, etc.
    $cands = $xp->query('//article | //div[contains(@class,"entry-content")] | //div[contains(@class,"post-content")] | //div[contains(@class,"article-body")] | //div[contains(@class,"story-body")] | //main');
    $best=null; $maxLen=0;
    foreach($cands as $c){
        $len = strlen(trim($c->textContent));
        // prefer article tag
        if($c->nodeName==='article') $len+=500;
        if($len>$maxLen){ $maxLen=$len; $best=$c; }
    }
    $inner='';
    if($best){
        foreach($best->childNodes as $ch) $inner.=$dom->saveHTML($ch);
    } else {
        // fallback: all <p> in body
        $ps=$xp->query('//p'); foreach($ps as $p) $inner.=$dom->saveHTML($p);
    }
    // Clean via DOM again
    $dom2=new DOMDocument(); $wrapped='<div id="root">'.$inner.'</div>';
    @$dom2->loadHTML(mb_encode_numericentity($wrapped,[0x80,0x10FFFF,0,0x1FFFFF],'UTF-8'), LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
    $xp2=new DOMXPath($dom2); $root=$dom2->getElementById('root');
    foreach(['//script','//style','//noscript','//iframe[contains(@src,"doubleclick")]','//aside','//nav','//form','//button'] as $q){
        $nodes=$xp2->query($q); for($i=$nodes->length-1;$i>=0;$i--) if($nodes->item($i)->parentNode) $nodes->item($i)->parentNode->removeChild($nodes->item($i));
    }
    // Unwrap internal glomex already handled generically: keep external links
    foreach($xp2->query('//a[@href]') as $a){ $a->setAttribute('target','_blank'); $a->setAttribute('rel','noopener noreferrer'); }
    $out=''; foreach($root->childNodes as $ch) $out.=$dom2->saveHTML($ch);
    $out=trim($out);
    if(!str_contains($out,'<p') && strlen(strip_tags($out))>50) $out='<p>'.escT(strip_tags($out)).'</p>';
    return $out;
}
function escT(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

function extractTrends(): array {
    $xml = fetchUrlT(TRENDS_RSS);
    libxml_use_internal_errors(true);
    $dom=new DOMDocument(); $dom->loadXML($xml);
    $xp=new DOMXPath($dom); $xp->registerNamespace('ht','https://trends.google.com/trending/rss');
    $items=$xp->query('//item');
    $trends=[];
    foreach($items as $it){
        $title = $xp->evaluate('string(title)', $it);
        $traffic = $xp->evaluate('string(ht:approx_traffic)', $it);
        $pub = $xp->evaluate('string(pubDate)', $it);
        $pic = $xp->evaluate('string(ht:picture)', $it);
        $news = [];
        foreach($xp->query('ht:news_item', $it) as $ni){
            $news[]=[
                'title'=>$xp->evaluate('string(ht:news_item_title)', $ni),
                'url'=>$xp->evaluate('string(ht:news_item_url)', $ni),
                'pic'=>$xp->evaluate('string(ht:news_item_picture)', $ni),
                'source'=>$xp->evaluate('string(ht:news_item_source)', $ni),
            ];
        }
        $trends[]=compact('title','traffic','pub','pic','news');
        if(count($trends)>=MAX_TRENDS) break;
    }
    return $trends;
}

function extractArticleGeneric(string $html, string $url): ?array {
    libxml_use_internal_errors(true);
    $dom=new DOMDocument(); @$dom->loadHTML(mb_encode_numericentity($html,[0x80,0x10FFFF,0,0x1FFFFF],'UTF-8'));
    $xp=new DOMXPath($dom);
    // title
    $t=$xp->query('//meta[@property="og:title"]/@content')->item(0) ?? $xp->query('//title')->item(0);
    $title = $t ? trim($t->nodeValue ?? $t->textContent) : '';
    if(!$title) return null;
    // image
    $ogImg=$xp->query('//meta[@property="og:image"]/@content')->item(0);
    $img=$ogImg ? trim($ogImg->nodeValue) : '';
    if(!$img){ $n=$xp->query('//article//img | //figure//img')->item(0); if($n) $img=$n->getAttribute('src')?:$n->getAttribute('data-src'); }
    // published
    $pub=$xp->query('//meta[@property="article:published_time"]/@content')->item(0) ?? $xp->query('//meta[@name="publish-date"]/@content')->item(0);
    $published=$pub ? trim($pub->nodeValue) : date('c');
    // desc
    $descN=$xp->query('//meta[@name="description"]/@content')->item(0) ?? $xp->query('//meta[@property="og:description"]/@content')->item(0);
    $excerpt=$descN ? trim($descN->nodeValue) : '';
    // content
    $content = cleanGeneric($html, $img);
    if(mb_strlen(strip_tags($content),'UTF-8')<80) return null;
    if(!$excerpt) $excerpt=mb_substr(trim(strip_tags($content)),0,180,'UTF-8').'…';
    else $excerpt=mb_substr($excerpt,0,220,'UTF-8');
    $slug=slugifyT($title,$url);
    return [
        'title'=>$title,
        'slug'=>$slug,
        'excerpt'=>$excerpt,
        'content'=>$content,
        'category'=>'trends',
        'image_url'=>$img,
        'source_url'=>$url,
        'author'=>'Google Trends US',
        'published_at'=>$published,
        'trend'=>true,
    ];
}

function mainT(): void {
    $isCli=php_sapi_name()==='cli';
    if(!$isCli) header('Content-Type: application/json; charset=utf-8');
    try{
        echo $isCli ? "[trends] fetching ".TRENDS_RSS." ...\n" : '';
        $trends=extractTrends();
        echo $isCli ? "[trends] found ".count($trends)." trends\n" : '';
        $toScrape=[];
        foreach($trends as $t) foreach($t['news'] as $n) if(filter_var($n['url'],FILTER_VALIDATE_URL)) $toScrape[]=$n;
        $toScrape=array_slice($toScrape,0, MAX_TRENDS);
        echo $isCli ? "[trends] scraping ".count($toScrape)." news urls\n" : '';
        $existing=[];
        // load existing trends per-day
        if(is_dir(TRENDS_DATA_DIR)) foreach(glob(TRENDS_DATA_DIR.'/20*.json') as $f){
            if(basename($f)==='index.json') continue;
            $j=json_decode(file_get_contents($f),true);
            foreach(($j['articles']??[]) as $a) if(isset($a['source_url'])) $existing[$a['source_url']]=$a;
        }
        if(is_dir(TRENDS_HISTORY_DIR)) foreach(glob(TRENDS_HISTORY_DIR.'/*.json') as $f){ $j=json_decode(file_get_contents($f),true); if(isset($j['source_url'])&&!isset($existing[$j['source_url']])) $existing[$j['source_url']]=$j; }

        $new=0; $skipped=0;
        foreach($toScrape as $item){
            $url=$item['url'];
            if(isset($existing[$url])) { $skipped++; continue; }
            try{
                $html=fetchUrlT($url);
                $data=extractArticleGeneric($html,$url);
                if(!$data){ $skipped++; continue; }
                // enrich with trend title
                $data['trend_title']=$item['title'] ?? '';
                $existing[$url]=$data; $new++;
                if($isCli) echo "  + {$data['slug']} | {$data['title']}\n";
                usleep(400000);
            }catch(Throwable $e){ if($isCli) echo "  ! $url: ".$e->getMessage()."\n"; $skipped++; }
        }
        // sort, group by day
        uasort($existing, fn($a,$b)=>strcmp($b['published_at'],$a['published_at']));
        $articles=array_values($existing);
        @mkdir(TRENDS_DATA_DIR,0777,true); @mkdir(TRENDS_HISTORY_DIR,0777,true); @mkdir(TRENDS_SEO_DIR,0777,true);
        $byDay=[]; foreach($articles as $a){ $d=preg_match('/^(\d{4}-\d{2}-\d{2})/',$a['published_at'],$m)?$m[1]:date('Y-m-d'); $byDay[$d][]=$a; } krsort($byDay);
        foreach($byDay as $date=>$list){
            $p=['generated_at'=>date('c'),'date'=>$date,'count'=>count($list),'articles'=>array_values($list)];
            file_put_contents(TRENDS_DATA_DIR."/$date.json", json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        }
        $idx=['generated_at'=>date('c'),'total'=>count($articles),'days'=>array_map(fn($d)=>['date'=>$d,'count'=>count($byDay[$d]),'file'=>"$d.json"], array_keys($byDay))];
        file_put_contents(TRENDS_DATA_DIR.'/index.json', json_encode($idx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        // history
        foreach($articles as $a){ $path=TRENDS_HISTORY_DIR.'/'.($a['slug']??md5($a['source_url'])).'.json'; if(!file_exists($path)) file_put_contents($path, json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); }
        // SEO - reuse same builder as scraper.php simple version
        foreach($articles as $a){
            $slug=$a['slug']; $dir=TRENDS_SEO_DIR."/$slug"; @mkdir($dir,0777,true);
            $title=escT($a['title']); $excerpt=escT($a['excerpt']); $img=escT($a['image_url']); $url=escT($a['source_url']);
            $fullUrl='https://thetools.com/articles/trends/'.$slug.'/';
            $html=<<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>{$title} | thetools.com</title><meta name="description" content="{$excerpt}"/><link rel="canonical" href="{$fullUrl}"/><meta property="og:title" content="{$title}"/><meta property="og:description" content="{$excerpt}"/><meta property="og:image" content="{$img}"/><link rel="stylesheet" href="../../../css/style.css"/></head><body><header class="site-header"><div class="wrap"><a class="logo" href="../../../">thetools<span>.com</span></a></div></header><main class="wrap"><article class="detail"><a href="../../../">← Back</a><img src="{$img}" alt="" style="width:100%;max-height:420px;object-fit:cover"/><h1>{$title}</h1><div class="content">{$a['content']}</div><p><a href="{$url}" target="_blank" rel="noopener">source</a></p></article></main></body></html>
HTML;
            file_put_contents("$dir/index.html",$html);
        }
        $result=['ok'=>true,'trends'=>count($trends),'scraped'=>count($toScrape),'new'=>$new,'skipped'=>$skipped,'total'=>count($articles)];
        echo $isCli ? json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n" : json_encode($result,JSON_UNESCAPED_UNICODE);
    }catch(Throwable $e){ $err=['ok'=>false,'error'=>$e->getMessage()]; if(php_sapi_name()==='cli') fwrite(STDERR, json_encode($err)."\n"); else {http_response_code(500); echo json_encode($err);} exit(1); }
}
mainT();
