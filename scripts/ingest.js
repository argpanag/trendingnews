import { getDb, slugify } from './db.js';
import { getMockArticles } from './mock-api.js';

const API_URL = process.env.API_URL || null; // set to your real API when ready

async function fetchFromApi() {
  if (!API_URL) {
    console.log('[ingest] No API_URL set -> using mock API (replace with your real API later)');
    return getMockArticles();
  }
  console.log(`[ingest] Fetching from ${API_URL} ...`);
  const res = await fetch(API_URL);
  if (!res.ok) throw new Error(`API fetch failed: ${res.status} ${res.statusText}`);
  const data = await res.json();
  // Accept both {articles: [...]} and [...] shapes
  return Array.isArray(data) ? data : data.articles || [];
}

function upsertArticles(articles) {
  const db = getDb();
  let inserted = 0, skipped = 0;

  const insert = db.prepare(`
    INSERT OR IGNORE INTO articles (slug, title, excerpt, content, category, image_url, source_url, author, published_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `);

  for (const a of articles) {
    if (!a.title || !a.source_url) { skipped++; continue; }
    const slug = a.slug || slugify(a.title);
    const published = a.published_at || new Date().toISOString();
    const result = insert.run(
      slug,
      a.title,
      a.excerpt || '',
      a.content || '',
      a.category || 'general',
      a.image_url || '',
      a.source_url,
      a.author || 'Unknown',
      published
    );
    if (result.changes === 0) skipped++; else inserted++;
  }

  const count = db.prepare('SELECT COUNT(*) as c FROM articles').get().c;
  console.log(`[ingest] inserted=${inserted} skipped=${skipped} total=${count}`);
  db.close();
}

const articles = await fetchFromApi();
console.log(`[ingest] fetched ${articles.length} articles`);
upsertArticles(articles);
