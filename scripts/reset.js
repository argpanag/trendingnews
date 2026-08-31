import { unlinkSync, existsSync } from 'node:fs';
import { DB_PATH } from './db.js';
import { getMockArticles } from './mock-api.js';
import { getDb, slugify } from './db.js';

if (existsSync(DB_PATH)) unlinkSync(DB_PATH);
console.log('[reset] removed', DB_PATH);

const db = getDb();
const articles = getMockArticles(); // seed all 4 demo articles
const insert = db.prepare(`INSERT INTO articles (slug, title, excerpt, content, category, image_url, source_url, author, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`);
for (const a of articles) {
  insert.run(slugify(a.title), a.title, a.excerpt, a.content, a.category, a.image_url, a.source_url, a.author, a.published_at);
}
console.log('[reset] seeded', db.prepare('SELECT COUNT(*) as c FROM articles').get().c);
db.close();

// also export
import('./export.js');
