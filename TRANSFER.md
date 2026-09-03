# Transfer Guide — trends-online.com

How to move this project to another machine, USB, or new Git repo without breaking the SQLite + cron setup.

## 1. What to copy

Copy the **entire folder** `trends-online.com/` as-is. Required:

```
trends-online.com/
  index.html
  css/
  js/
  data/
    articles.db      # SQLite file - KEEP IT if you want to keep articles
    articles.json    # generated - can be regenerated
  scripts/
    schema.sql       # needed to recreate DB
    db.js
    ingest.js
    export.js
    mock-api.js
    reset.js
  .github/workflows/
    cron.yml         # hourly ingest (every hour UTC)
    pages.yml        # Pages deploy
  worker/            # optional Cloudflare D1 (free tier)
  package.json
  .gitignore
  README.md
  TRANSFER.md        # this file
```

**Do NOT copy:**
- `node_modules/` (regenerated, ignored)
- `.env` / `.dev.vars` (secrets, recreate on new machine)

## 2. Prerequisites on new machine

```bash
node --version  # must be >=22 (uses built-in node:sqlite, no native deps)
# check: v22+ includes sqlite 3.44+. Tested on v25.9.0
npm --version
```

No `better-sqlite3`, no Python, no WAMP/XAMPP needed. Pure Node + vanilla JS.

## 3. Setup after transfer (30 seconds)

```bash
cd trends-online.com

# (optional) if you copied via git clone and have package lock:
npm install   # no deps currently, but needed if you add `serve`

# rebuild DB from scratch or keep existing DB:
# Option A: keep existing articles.db - just export
node scripts/export.js

# Option B: reset + seed with mock data (4 demo articles)
node scripts/reset.js
# or: npm run ingest  (fetch mock API -> db -> json)

# Option C: use your real API (you said you'll implement later)
API_URL=https://your-api.com/articles node scripts/ingest.js
# or set API_URL in .env and: API_URL=$API_URL npm run ingest

# run locally (no Cloudflare needed):
npx serve . -l 3000
# open http://localhost:3000
# you should see 4 cards; search/filter works; click for detail via #/article/slug
```

Verify:
```bash
node -e "import {DatabaseSync} from 'node:sqlite'; const db=new DatabaseSync('data/articles.db'); console.log(db.prepare('SELECT COUNT(*) as c FROM articles').get())"
cat data/articles.json  # should have {count, generated_at, articles: [...]}
```

## 4. Git transfer (recommended for GitHub Pages)

```bash
# on old machine - initialize if not yet a repo
git init
git add .
git commit -m "initial: vanilla JS + SQLite + hourly cron"
git branch -M main
git remote add origin https://github.com/YOURUSER/trends-online.com.git
git push -u origin main

# on new machine
git clone https://github.com/YOURUSER/trends-online.com.git
cd trends-online.com
node scripts/export.js  # regenerate json if needed
```

GitHub Actions is already configured:
- `cron.yml:1` runs `0 * * * *` (hourly) -> ingests API -> commits `data/articles.json`
- `pages.yml:1` deploys to GitHub Pages on push to `main`

Enable after push:
1. GitHub repo -> Settings -> Pages -> Build: **GitHub Actions**
2. Settings -> Secrets -> New: `API_URL` = `https://your-api.com/articles` (when ready)
3. Actions will run hourly; trigger manually via Actions tab -> Hourly Ingest Cron -> Run workflow

## 5. Your API contract (for later)

When you implement the content API, make it return one of:

```json
[
  {"title": "...", "excerpt": "...", "content": "<p>html</p>", "category": "tech", "image_url": "https://...", "source_url": "https://example.com/unique", "author": "...", "published_at": "2026-08-31T12:00:00Z"}
]
```
or
```json
{"articles": [ ... ]}
```

Required: `title`, `source_url` (unique, used for dedupe `INSERT OR IGNORE`). Optional: `slug` (auto from title), `category` (tech/business/general).

## 6. Cloudflare (still local for now, per your choice)

You said to run locally for now - nothing to do. When ready:
- DNS: point domain to GitHub Pages IP, orange cloud ON.
- Cache rule: `data/articles.json` -> 5 min.
- Free live SQLite upgrade: see `README.md:53` + `worker/wrangler.toml:1` (D1 free: 5GB, 5M reads/day). No frontend rewrite except fetch URL.

## 7. Common issues after transfer

| Issue | Fix |
|-------|-----|
| `Cannot find module 'node:sqlite'` | Update Node to >=22: `winget install OpenJS.NodeJS` or https://nodejs.org |
| `data/articles.json 404` in browser | Run `node scripts/ingest.js && node scripts/export.js` then refresh |
| `articles.db` missing after zip | Check `scripts/schema.sql` exists; run `node scripts/reset.js` to recreate |
| Cron not running on new repo | Check Actions enabled + `API_URL` secret set; cron only runs on default branch `main` |
| Port 3000 busy | `npx serve . -l 3001` or `python -m http.server 8000` |

## 8. Quick checklist before unplugging old machine

- [ ] `data/articles.db` copied or `scripts/schema.sql` present?
- [ ] `node --version` >=22 on new machine?
- [ ] `node scripts/export.js` succeeds and `data/articles.json` has `count > 0`?
- [ ] `npx serve .` shows articles at `http://localhost:3000`?
- [ ] Git remote pushed if using GitHub Pages?

No build step, no framework, no database server to migrate - just Node + files.
