// Mock Content API - replace with your real API later
// Your future API should implement: GET /articles -> array of articles
// Each article: {title, excerpt, content, category, image_url, source_url, author, published_at}

export function getMockArticles() {
  const now = new Date();
  return [
    {
      title: "Vanilla JS Makes a Comeback in 2026",
      excerpt: "Developers rediscover the power of zero-dependency frontends for speed and longevity.",
      content: "<p>In 2026, teams are shipping faster by dropping heavy frameworks. A single <code>app.js</code> with <code>fetch()</code> and native DOM APIs handles routing, rendering, and caching without a build step. GitHub Pages + Cloudflare proves you can scale to millions on the free tier.</p><p>This article was ingested via the hourly cron into SQLite.</p>",
      category: "tech",
      image_url: "https://picsum.photos/seed/vanilla/800/450",
      source_url: "https://example.com/vanilla-js-comeback",
      author: "Mock API",
      published_at: new Date(now - 1000 * 60 * 30).toISOString()
    },
    {
      title: "SQLite on the Edge: One File, All Users",
      excerpt: "How Cloudflare D1 and Turso bring SQLite to the edge for free.",
      content: "<p>SQLite was never meant for the web, until D1 and Turso. A single <code>.db</code> file is now replicated globally. For GitHub Pages (static), we use GitHub Actions to rebuild the DB hourly and export JSON. For live writes, D1 gives you 5GB free and 5M reads/day.</p>",
      category: "tech",
      image_url: "https://picsum.photos/seed/sqlite/800/450",
      source_url: "https://example.com/sqlite-edge",
      author: "Mock API",
      published_at: new Date(now - 1000 * 60 * 60 * 2).toISOString()
    },
    {
      title: "Hourly Cron Without Paying for a Server",
      excerpt: "GitHub Actions as your free cron daemon - 2000 minutes/month included.",
      content: "<p>Set <code>on: schedule: - cron: '0 * * * *'</code> and GitHub runs your ingest script every hour, commits new JSON to Pages, and Cloudflare caches it. No VPS, no cost.</p>",
      category: "general",
      image_url: "https://picsum.photos/seed/cron/800/450",
      source_url: "https://example.com/hourly-cron",
      author: "Mock API",
      published_at: new Date(now - 1000 * 60 * 60 * 5).toISOString()
    },
    {
      title: "The News Stack That Costs $0",
      excerpt: "GitHub Pages + Cloudflare + D1/Turso + GitHub Actions = free news website.",
      content: "<p>Compare: GitHub Pages (free static), Cloudflare (free CDN + D1 5GB), GitHub Actions (free 2000 min), Turso (free 9GB). Your news site can serve 100k users/month for $0. This demo shows how.</p><p>Upgrade path: When you outgrow Pages, move HTML to Cloudflare Pages (also free) and keep the same Worker API.</p>",
      category: "business",
      image_url: "https://picsum.photos/seed/free/800/450",
      source_url: "https://example.com/free-stack",
      author: "Mock API",
      published_at: new Date(now - 1000 * 60 * 60 * 24).toISOString()
    }
  ];
}

// If run directly: print JSON like a real API would
if (process.argv[1] && import.meta.url === `file://${process.argv[1].replace(/\\/g, '/')}`) {
  console.log(JSON.stringify(getMockArticles(), null, 2));
}
