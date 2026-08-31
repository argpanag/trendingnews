# thetools.com — Vanilla JS + SQLite + Hourly Cron

Pure vanilla JS news site. GitHub Pages (static) + hourly cron that ingests your API into SQLite.

## Free tier: Can it run 100% free?

**Yes.** Two options:

| Option | Hosting | DB (SQLite) | Cron | Cost | When to use |
|--------|---------|-------------|------|------|-------------|
| **A) GitHub Pages (current)** | GitHub Pages free | `data/articles.db` rebuilt hourly in Actions, exported to `data/articles.json` | GitHub Actions `0 * * * *` (2000 min/month free) | **$0** | Users only read articles (your case now) |
| **B) Cloudflare D1 (upgrade)** | Cloudflare Pages free | Cloudflare D1 - 5GB, 5M reads/day free (true shared SQLite) | Cloudflare Cron Triggers hourly free | **$0** | Need live shared writes / comments |

Other free DBs: **Turso** (9GB, libSQL/SQLite, free), **Neon** (0.5GB Postgres, free), **Supabase** (500MB, free). But **D1 is ideal** because you already want Cloudflare + SQLite.

> On GitHub Pages you cannot have one writable SQLite file shared live by all users (static only). Option A fakes it via hourly JSON snapshot (all users see same). Option B is real SQLite at edge.

## Quick start (local, no Cloudflare needed)

```bash
npm run ingest   # fetch mock API -> data/articles.db -> data/articles.json
npx serve . -l 3000
# open http://localhost:3000
```

Replace mock with your real API:
```bash
API_URL=https://your-api.com/articles node scripts/ingest.js
```
Your API should return `[{title, excerpt, content, category, image_url, source_url, author, published_at}]` or `{articles: [...]}`.

## Project structure

```
index.html + js/app.js + css/style.css  # vanilla JS, fetch JSON
data/articles.db                         # SQLite (node:sqlite built-in)
data/articles.json                       # generated, fetched by frontend
scripts/ingest.js                        # API -> SQLite
scripts/export.js                        # SQLite -> JSON
scripts/mock-api.js                      # stub until your API ready
.github/workflows/cron.yml               # hourly ingest
.github/workflows/pages.yml              # deploy to Pages
worker/index.js                          # optional Cloudflare D1 Worker (free)
```

## Deploy to GitHub Pages (when ready)

1. Push to `main` on GitHub.
2. Settings -> Pages -> Source: GitHub Actions
3. Set secret `API_URL` in repo Settings -> Secrets
4. Pages + Cloudflare: point DNS to GitHub Pages, orange cloud, cache `data/*`.

## Switch to Cloudflare D1 (free) when you need live SQLite

```bash
npm i -g wrangler
wrangler d1 create thetools-db
# paste id into worker/wrangler.toml
wrangler d1 execute thetools-db --file=scripts/schema.sql
wrangler deploy --config worker/wrangler.toml
# frontend: change fetch('./data/articles.json') -> fetch('https://your-worker.workers.dev/api/articles')
```

No frontend rewrite needed except URL.
