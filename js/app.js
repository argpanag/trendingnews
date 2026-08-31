// Pure vanilla JS - no frameworks, no build step
const els = {
  grid: document.getElementById('articles'),
  detail: document.getElementById('detail'),
  meta: document.getElementById('meta'),
  generated: document.getElementById('generated'),
  search: document.getElementById('search')
};

let allArticles = [];
let activeFilter = 'all';
let searchQ = '';

import { API_URL, API_FALLBACK } from './config.js';

async function load() {
  // Try PHP scraper API first, fallback to legacy SQLite JSON
  const urls = [API_URL, API_FALLBACK, './data/articles.json'];
  let lastErr = null;
  for (const url of urls) {
    try {
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
      const data = await res.json();
      // accept both {articles:[]} and [...] and single object
      if (Array.isArray(data)) allArticles = data;
      else if (data.articles) allArticles = data.articles;
      else if (data.slug) allArticles = [data];
      else allArticles = [];
      if (allArticles.length === 0 && data.count === 0) throw new Error('empty ' + url);
      els.generated.textContent = data.generated_at ? `Updated: ${new Date(data.generated_at).toLocaleString()}` : '';
      els.meta.textContent = `${allArticles.length} articles · PHP scraper → JSON · history saved`;
      render();
      return;
    } catch (e) {
      lastErr = e;
      console.warn('load failed', url, e.message);
    }
  }
  els.meta.textContent = 'Failed to load articles. Run php api/scraper.php or npm run ingest';
  console.error(lastErr);
  els.grid.innerHTML = `<div style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px">
      <b>No data yet.</b> Run <code>php api/scraper.php</code> (or <code>node scripts/ingest.js && node scripts/export.js</code>), then refresh.<br>
      <small>${lastErr ? lastErr.message : ''}</small>
      <br><button onclick="location.reload()" style="margin-top:12px;padding:6px 12px;border:1px solid #e5e7eb;border-radius:999px;cursor:pointer">Retry</button>
      <a href="api/scraper.php" target="_blank" style="margin-left:8px">Run scraper now</a>
    </div>`;
}

function filtered() {
  return allArticles.filter(a => {
    const matchCat = activeFilter === 'all' || a.category === activeFilter;
    const q = searchQ.toLowerCase();
    const matchSearch = !q || a.title.toLowerCase().includes(q) || a.excerpt.toLowerCase().includes(q) || a.category.toLowerCase().includes(q);
    return matchCat && matchSearch;
  });
}

function cardHtml(a) {
  const date = new Date(a.published_at).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
  return `
  <article class="card">
    <a href="#/article/${a.slug}"><img src="${a.image_url || ''}" alt="" loading="lazy" onerror="this.style.display='none'"></a>
    <div class="card-body">
      <div class="badge">${escapeHtml(a.category)}</div>
      <h2><a href="#/article/${a.slug}">${escapeHtml(a.title)}</a></h2>
      <p>${escapeHtml(a.excerpt || '')}</p>
      <div class="card-meta"><span>${escapeHtml(a.author || '')}</span><span>${date}</span></div>
    </div>
  </article>`;
}

function render() {
  const hash = location.hash;
  if (hash.startsWith('#/article/')) {
    const slug = hash.replace('#/article/', '');
    const a = allArticles.find(x => x.slug === slug);
    if (!a) {
      els.detail.classList.remove('hidden'); els.grid.innerHTML = '';
      els.detail.innerHTML = `<p>Article not found. <a href="#/">Back to list</a></p>`;
      return;
    }
    els.grid.innerHTML = '';
    els.detail.classList.remove('hidden');
    els.detail.innerHTML = `
      <a href="#/">← Back</a>
      <img src="${a.image_url || ''}" alt="" onerror="this.style.display='none'">
      <div class="badge">${escapeHtml(a.category)}</div>
      <h1>${escapeHtml(a.title)}</h1>
      <div style="color:#6b7280;font-size:.9rem">${escapeHtml(a.author || '')} · ${new Date(a.published_at).toLocaleString()} · <a href="${a.source_url}" target="_blank" rel="noopener">source</a></div>
      <div class="content">${a.content || ''}</div>
    `;
    window.scrollTo(0,0);
    return;
  }

  els.detail.classList.add('hidden');
  const list = filtered();
  els.meta.textContent = `${list.length} / ${allArticles.length} articles` + (activeFilter !== 'all' ? ` · ${activeFilter}` : '') + (searchQ ? ` · search: "${searchQ}"` : '');
  els.grid.innerHTML = list.map(cardHtml).join('') || `<p>No articles match.</p>`;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// events
document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    activeFilter = btn.dataset.filter;
    location.hash = '#/';
    render();
  });
});
els.search.addEventListener('input', e => { searchQ = e.target.value; render(); });
window.addEventListener('hashchange', render);

load();
