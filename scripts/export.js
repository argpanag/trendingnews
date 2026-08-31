import { getDb } from './db.js';
import { writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_JSON = join(__dirname, '..', 'data', 'articles.json');

function exportJson() {
  const db = getDb();
  const rows = db.prepare(`
    SELECT id, slug, title, excerpt, content, category, image_url, source_url, author, published_at, created_at
    FROM articles
    ORDER BY datetime(published_at) DESC
  `).all();

  const payload = {
    generated_at: new Date().toISOString(),
    count: rows.length,
    articles: rows
  };

  mkdirSync(dirname(OUT_JSON), { recursive: true });
  writeFileSync(OUT_JSON, JSON.stringify(payload, null, 2), 'utf8');
  console.log(`[export] wrote ${rows.length} articles -> ${OUT_JSON}`);
  db.close();
}

exportJson();
