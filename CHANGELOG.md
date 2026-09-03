# thetools.com - Google Trends News Site

A Google Trends news aggregator that scrapes trending articles from 47 countries and generates static HTML pages for SEO indexing.

## Architecture

```
thetools.com/
├── index.html                    # Main page (auto-generated)
├── index-{2-5}.html              # Paginated pages
├── articles/{slug}/index.html    # 113+ article pages
├── api/
│   ├── data/                     # JSON data storage
│   │   ├── index.json            # Main article index
│   │   ├── articles.json         # Aggregated articles
│   │   └── trends/{country}/     # Per-country data
│   │       ├── index.json
│   │       └── YYYY-MM-DD.json
│   ├── history_trends/           # Article history
│   ├── trends_scraper.php        # Google Trends scraper
│   ├── rebuild_articles.php      # Article HTML generator
│   └── build_index.php           # Index page generator
├── .github/workflows/
│   ├── deploy.yml                # Auto-deploy to GitHub Pages
│   └── cron.yml                  # Hourly scraping cron
├── css/style.css
└── js/app.js
```

## What Was Done (Session: 2026-09-03)

### 1. Article Template Audit & Fix

**Problem**: 25 out of 113 article files had incorrect HTML template structure.

**Issues Found**:
- Single-line minified HTML instead of formatted template
- `lang="en"` instead of `lang="el"`
- Missing `<footer>` section
- Missing `<nav>` with category/country badges
- Back link said "← Back" instead of "← Πίσω"

**Solution**:
- Created `fix_articles.php` script to extract article data and regenerate files
- Fixed 25 articles with incorrect template
- All 113 articles now use consistent template

**Template Structure** (articles must have):
```html
<!DOCTYPE html>
<html lang="el">
<head>
  <link rel="stylesheet" href="../../css/style.css" />
  <meta property="og:type" content="article" />
  <meta property="og:locale" content="el_GR" />
  <script type="application/ld+json">{"@type":"Article",...}</script>
</head>
<body>
  <header class="site-header">
    <a class="logo" href="../../">thetools<span>.com</span></a>
    <nav class="nav">
      <a href="../../" class="filter-btn">← Αρχική</a>
      <span class="badge">{category}</span>
      <span class="badge">{country}</span>
    </nav>
  </header>
  <main class="wrap">
    <article class="detail">
      <a href="../../">← Πίσω</a>
      <div class="content">...</div>
    </article>
  </main>
  <footer class="site-footer">...</footer>
</body>
</html>
```

### 2. GitHub Actions Auto-Deploy

**Created**: `.github/workflows/deploy.yml`

**Triggers**:
- Push to `main` or `master` branch
- Only when these files change:
  - `index.html`
  - `index-*.html`
  - `articles/**`
  - `css/**`
  - `js/**`
  - `sitemap.xml`

**Action**: Deploys to GitHub Pages

### 3. Multi-Country Cron Job

**Updated**: `.github/workflows/cron.yml`

**Schedule**: Runs hourly (every hour UTC)

**Countries Scraped** (47 total):

| Tier | Countries | Count |
|------|-----------|-------|
| Tier-1 | US, GB, DE, FR, JP | 5 |
| Tier-2 | AU, CA, IT, ES, BR, IN, KR, MX, NL, SE | 10 |
| Tier-3 | PL, PT, CH, AT, BE, DK, NO, FI, IE, NZ, SG, HK, TW, TH, VN, ID, MY, PH, CZ, RO, HU, BG, HR, SK, SI, LT, LV, EE | 32 |

**Workflow Steps**:
1. Checkout repository
2. Setup PHP 8.2
3. Run trends scraper for tier-1 countries (5s delay)
4. Run trends scraper for tier-2 countries (3s delay)
5. Run trends scraper for tier-3 countries (2s delay)
6. Rebuild index and articles
7. Commit and push changes

## Commands

### Manual Scraping

```bash
# Scrape single country
php api/trends_scraper.php --country=US

# Scrape with force reload
php api/trends_scraper.php --country=GB --force

# Rebuild all articles from JSON
php api/rebuild_articles.php

# Rebuild index.html + sitemap
php api/build_index.php
```

### Audit Articles

```powershell
# Check all articles for template consistency
powershell -ExecutionPolicy Bypass -File audit_articles.ps1
```

## Country Codes Reference

### Tier-1 (5)
- `US` - United States
- `GB` - United Kingdom
- `DE` - Germany
- `FR` - France
- `JP` - Japan

### Tier-2 (10)
- `AU` - Australia
- `CA` - Canada
- `IT` - Italy
- `ES` - Spain
- `BR` - Brazil
- `IN` - India
- `KR` - South Korea
- `MX` - Mexico
- `NL` - Netherlands
- `SE` - Sweden

### Tier-3 (32)
- `PL` - Poland
- `PT` - Portugal
- `CH` - Switzerland
- `AT` - Austria
- `BE` - Belgium
- `DK` - Denmark
- `NO` - Norway
- `FI` - Finland
- `IE` - Ireland
- `NZ` - New Zealand
- `SG` - Singapore
- `HK` - Hong Kong
- `TW` - Taiwan
- `TH` - Thailand
- `VN` - Vietnam
- `ID` - Indonesia
- `MY` - Malaysia
- `PH` - Philippines
- `CZ` - Czech Republic
- `RO` - Romania
- `HU` - Hungary
- `BG` - Bulgaria
- `HR` - Croatia
- `SK` - Slovakia
- `SI` - Slovenia
- `LT` - Lithuania
- `LV` - Latvia
- `EE` - Estonia

## Git Commits

```
4c8af6c feat: add tier-3 countries (32 more) to hourly cron
23f84c8 fix: article template consistency + multi-country cron + auto-deploy
```

## Notes

- Article content is scraped from news sources via Google Trends RSS
- Each article gets a static HTML page for SEO indexing
- The site uses `lang="el"` (Greek) for the HTML tag
- All article paths use `../../` to reference root (CSS, logo, footer links)
- JSON-LD structured data is included in each article for rich snippets
