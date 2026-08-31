# thetools.com — Project Documentation

A fully static news site built with **PHP scrapers → JSON → static HTML pages**.
No database server, no frameworks, no build step. Every article becomes its own
standalone `.html` page so search engines index content directly without running
JavaScript.

---

## Overview

```
                    ┌──────────────────────────────────────────────┐
   Public sources ──▶│  PHP scrapers (api/*.php)                    │
                    │    fetch listing + article pages             │
                    │    clean HTML (ads, share buttons, links)     │
                    └───────────────────┬──────────────────────────┘
                                        │ saves JSON
                                        ▼
   ┌─────────────────────────────────────────────────────────────┐
   │  api/data/  (day-split JSON)   api/history/  (per-article)   │
   └───────────────────────┬─────────────────────────────────────┘
                           │
                           ▼
   ┌─────────────────────────────────────────────────────────────┐
   │  api/build_index.php  →  index.html                          │
   │  api/scraper.php      →  articles/<slug>/index.html          │
   │  api/trends_scraper.php → articles/<slug>/index.html         │
   │                         sitemap.xml · robots.txt · .htaccess │
   └─────────────────────────────────────────────────────────────┘
```

Every run of a scraper re-generates its article pages; `build_index.php` then
rebuilds the static home page that links to every article.

---

## Directory structure

```
thetools.com/
  index.html                     # static home (rebuilt): grid of all article links
  sitemap.xml                    # all article URLs (rebuilt)
  robots.txt                     # Allow: / + Sitemap URL
  .htaccess                      # no-cache headers
  css/style.css                  # shared styles
  js/app.js                      # optional JS UI (search/filter/detail)
  js/config.js                   # API endpoint config
  articles/                      # one folder per article
    <slug>/index.html            # standalone full HTML article page (+ no-cache)
    .htaccess                    # no-cache headers
  api/
    scraper.php                  # primary scraper (listing → articles)
    trends_scraper.php          # additional/trending sources scraper
    build_index.php             # rebuilds index.html + sitemap + robots + htaccess
    index.php                    # JSON API endpoint w/ CORS + filters
    .htaccess                    # CORS + cache rules
    data/
      index.json                 # day index for primary feed
      YYYY-MM-DD.json            # articles grouped by publication day
      articles.json              # aggregated fallback (all in one)
      trends/                    # additional source articles (day-split)
        index.json
        YYYY-MM-DD.json
    history/                     # per-article archive of the primary feed
    history_trends/              # per-article archive of the extra feed
```

---

## Pipeline

### 1. Scrape

`api/scraper.php` and `api/trends_scraper.php`:

- fetch the source listing page with `cURL` (spoofed UA, `Accept-Language`)
- extract article URLs (DOM-manipulation + regex fallback)
- fetch each article and parse:
  - title (`h1.entry-title` / `og:title`)
  - featured image (`og:image`)
  - published date (`article:published_time` / JSON-LD)
  - author (or falls back to a generic author)
  - content from the article container
- **Content cleaning** removes what is not wanted:
  - `<script>`, `<style>`, `<noscript>`, ad iframes/doubleclick
  - share buttons, social widgets, breadcrumbs, bylines
  - comments, "recommended"/"related"/outbrain widgets
  - newsletters, subscribe prompts, sidebars
  - internal links (unwrapped to plain text)
  - empty containers
- video embeds (`glomex` player iframes) are replaced with a featured-image
  thumbnail + play button linking to the original player URL

### 2. Store

- Day-split JSON: `api/data/YYYY-MM-DD.json` grouped by `published_at`
- `api/data/index.json` — list of days, counts, file names
- Aggregated `api/data/articles.json` kept for backward compatibility
- Per-article archive files in `api/history/` and `api/history_trends/`
- Deduplication by `source_url`

### 3. Publish static pages

- Each article → `articles/<slug>/index.html` — a complete standalone HTML page
  with `<title>`, `meta description`, `canonical`, Open Graph, Twitter cards,
  JSON-LD `Article` schema, and the cleaned article content. No JS required.
- `api/build_index.php` rebuilds:
  - `index.html` — static grid linking every article page
  - `sitemap.xml` — home + every article URL
  - `robots.txt` — points at the sitemap
  - `.htaccess` — forces `no-store, no-cache` so pages are never served stale

### 4. No caching

All generated pages (index + every article) include:

```html
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
```

And `.htaccess` sends the same headers for `.html` / `.json` files.

---

## Forcing a refresh

Articles are skipped if they already exist. To force a re-scrape of existing
articles:

```bash
# CLI
php api/scraper.php --force        # also --reload, -f
php api/trends_scraper.php --force

# HTTP
GET /api/scraper.php?force=1       # also ?reload=1, ?refresh=1
GET /api/trends_scraper.php?force=1
```

With `--force`, existing articles are fetched again, `~` marks reloaded rows,
and history files are overwritten when content changes.

---

## Frontend

`index.html` is fully usable **without JavaScript** — all cards are plain
`<a href="articles/<slug>/">` links to static pages.

`js/app.js` (optional enhancement) adds client-side search, category filtering,
and an AJAX detail view. It fetches day-split JSON through `js/config.js`
endpoints, with aggregated fallback. Card clicks are intercepted for a smooth
SPA experience, while crawlers and users without JS still navigate the static
pages.

---

## API endpoints

Served when running under any PHP-capable web server (Apache, XAMPP, nginx+FPM):

| Endpoint | Description |
|----------|-------------|
| `GET /api/data/index.json` | Day index (`{generated_at, total, days[]}`) |
| `GET /api/data/YYYY-MM-DD.json` | Articles for one day |
| `GET /api/data/articles.json` | Aggregated all articles |
| `GET /api/index.php?category=&search=&limit=&date=` | Filtered JSON with CORS |
| `GET /api/scraper.php` | Run primary scrape (optionally `?force=1`) |
| `GET /api/trends_scraper.php` | Run extra scrape (optionally `?force=1`) |
| `GET /sitemap.xml` | XML sitemap of every article |
| `GET /articles/<slug>/` | Standalone article HTML page |

---

## Automation (cron / task scheduler)

Run the scrapers hourly, then rebuild the index:

```bash
# Unix cron
0 * * * * php /path/to/thetools.com/api/scraper.php  >> /var/log/thetools-scrape.log 2>&1
30 * * * * php /path/to/thetools.com/api/trends_scraper.php >> /var/log/thetools-trends.log 2>&1
45 * * * * php /path/to/thetools.com/api/build_index.php >> /var/log/thetools-build.log 2>&1

# Windows (Task Scheduler)
C:\xampp2024\php\php.exe C:\...\thetools.com\api\scraper.php
```

Each scraper already invokes `buildIndex()` when it finishes, so the extra
`build_index.php` cron line is optional.

---

## Requirements

- **PHP >= 8.0** with `curl`, `dom`, `mbstring`, `json` extensions (all bundled
  in XAMPP / standard distributions)
- A PHP-capable web server (Apache/XAMPP, nginx+PHP-FPM) — or `php -S` for dev
- No database, no Node, no build tooling

For local development on XAMPP:

```bash
php api/scraper.php --force
php api/trends_scraper.php --force
php api/build_index.php
# open http://localhost/thetools.com/index.html
```

---

## Deployment to a static host (GitHub Pages, Cloudflare Pages, Netlify)

The site is 100% static after a scrape run, so you can:

1. Run the scrapers + `build_index.php` locally (or on any PHP host via cron).
2. Commit/publish the entire folder.
3. The static host serves `index.html`, `articles/*/index.html`, `sitemap.xml`
   without needing PHP at runtime. Only the scrapers need PHP.

> Note: realtime re-scraping requires the PHP host; static hosts serve the
> already-generated files.