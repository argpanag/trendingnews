// Cloudflare Worker + D1 free-tier alternative
// This gives you a TRUE shared SQLite (D1 = SQLite) for $0
// Free tier: 5GB storage, 5M row reads/day, 100k row writes/day, 100k Worker requests/day
// Deploy: wrangler d1 create trends-online-db && wrangler deploy
// Keep vanilla JS frontend identical: fetch('/api/articles') instead of '/data/articles.json'

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // CORS for GitHub Pages frontend
    const cors = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    };
    if (request.method === 'OPTIONS') return new Response(null, { headers: cors });

    // GET /api/articles -> shared SQLite
    if (url.pathname === '/api/articles') {
      const { results } = await env.DB.prepare(
        'SELECT * FROM articles ORDER BY datetime(published_at) DESC LIMIT 100'
      ).all();
      return Response.json({ generated_at: new Date().toISOString(), count: results.length, articles: results }, { headers: cors });
    }

    // GET /api/articles/:slug
    if (url.pathname.startsWith('/api/articles/')) {
      const slug = url.pathname.split('/').pop();
      const row = await env.DB.prepare('SELECT * FROM articles WHERE slug = ?').bind(slug).first();
      if (!row) return new Response('Not found', { status: 404, headers: cors });
      return Response.json(row, { headers: cors });
    }

    // POST /api/ingest -> called by GitHub Actions cron OR Cloudflare Cron Trigger
    if (url.pathname === '/api/ingest' && request.method === 'POST') {
      const articles = await request.json(); // expect [{title, source_url, ...}]
      let inserted = 0;
      for (const a of articles) {
        const slug = (a.slug || a.title.toLowerCase().replace(/[^a-z0-9]+/g,'-')).slice(0,60);
        try {
          await env.DB.prepare(
            'INSERT OR IGNORE INTO articles (slug, title, excerpt, content, category, image_url, source_url, author, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
          ).bind(slug, a.title, a.excerpt||'', a.content||'', a.category||'general', a.image_url||'', a.source_url, a.author||'', a.published_at||new Date().toISOString()).run();
          inserted++;
        } catch {}
      }
      return Response.json({ inserted }, { headers: cors });
    }

    return new Response('Worker running. Try /api/articles', { headers: cors });
  },

  // Cloudflare Cron Trigger - runs every hour for free (no GitHub Actions needed)
  async scheduled(event, env) {
    const res = await fetch(env.API_URL);
    const articles = await res.json();
    for (const a of articles) {
      const slug = (a.slug || a.title.toLowerCase().replace(/[^a-z0-9]+/g,'-')).slice(0,60);
      await env.DB.prepare(
        'INSERT OR IGNORE INTO articles (slug, title, excerpt, content, category, image_url, source_url, author, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
      ).bind(slug, a.title, a.excerpt||'', a.content||'', a.category||'general', a.image_url||'', a.source_url, a.author||'', a.published_at||new Date().toISOString()).run();
    }
  }
}
