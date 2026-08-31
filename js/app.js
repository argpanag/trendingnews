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

import { API_INDEX, API_URL, API_FALLBACK, API_DATA_BASE, TRENDS_INDEX, TRENDS_DATA_BASE } from './config.js';

async function fetchJson(url) {
  const res = await fetch(url, { cache: 'no-store' });
  if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
  return res.json();
}

async function load() {
  // 1) Try day-split API (api/data/index.json -> per-day files)
  try {
    const idx = await fetchJson(API_INDEX);
    if (idx.days && Array.isArray(idx.days) && idx.days.length > 0) {
      // fetch all day files in parallel
      const dayFiles = idx.days.map(d => API_DATA_BASE + d.file);
      const dayPayloads = await Promise.all(dayFiles.map(u => fetchJson(u).catch(() => null)));
      let merged = [];
      let latestGenerated = idx.generated_at;
      for (const p of dayPayloads) if (p && p.articles) merged = merged.concat(p.articles);
      // Optionally merge Google Trends USA (if available)
      try {
        const tIdx = await fetchJson(TRENDS_INDEX);
        if (tIdx.days && Array.isArray(tIdx.days) && tIdx.days.length>0) {
          const tFiles = tIdx.days.map(d => TRENDS_DATA_BASE + d.file);
          const tPayloads = await Promise.all(tFiles.map(u => fetchJson(u).catch(()=>null)));
          let tMerged=[];
          for(const p of tPayloads) if(p && p.articles) tMerged=tMerged.concat(p.articles);
          if(tMerged.length>0){
            merged = merged.concat(tMerged);
            console.log(`merged ${tMerged.length} trends articles`);
          }
        }
      } catch(e){ console.warn('trends not loaded', e.message); }

      // sort by published_at DESC to keep global order
      merged.sort((a,b) => new Date(b.published_at) - new Date(a.published_at));
      if (merged.length === 0) throw new Error('empty day files');
      allArticles = merged;
      els.generated.textContent = latestGenerated ? `Updated: ${new Date(latestGenerated).toLocaleString()}` : '';
      const trendsCount = merged.filter(a=>a.category==='trends').length;
      els.meta.textContent = `${allArticles.length} articles · ${idx.days.length} days · split by day` + (trendsCount ? ` · +${trendsCount} trends` : '');
      render();
      return;
    }
  } catch (e) {
    console.warn('day-split load failed, falling back', e.message);
  }

  // 2) Fallback: legacy aggregated JSON
  const urls = [API_URL, API_FALLBACK, './data/articles.json'];
  let lastErr = null;
  for (const url of urls) {
    try {
      const data = await fetchJson(url);
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
  // SEO: static HTML at /articles/<slug>/ (trends at /articles/trends/<slug>/) for crawlers, JS intercepts for SPA
  const staticHref = a.category === 'trends' ? `articles/trends/${a.slug}/` : `articles/${a.slug}/`;
  return `
  <article class="card">
    <a href="${staticHref}" data-slug="${a.slug}" class="card-link"><img src="${a.image_url || ''}" alt="" loading="lazy" onerror="this.style.display='none'"></a>
    <div class="card-body">
      <div class="badge">${escapeHtml(a.category)}</div>
      <h2><a href="${staticHref}" data-slug="${a.slug}" class="card-link">${escapeHtml(a.title)}</a></h2>
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
    const staticHref2 = a.category === 'trends' ? `articles/trends/${a.slug}/` : `articles/${a.slug}/`;
    els.detail.innerHTML = `
      <a href="#/">← Back</a> · <a href="${staticHref2}" style="font-size:.85rem;color:#6b7280">static SEO version</a>
      <img src="${a.image_url || ''}" alt="" onerror="this.style.display='none'">
      <div class="badge">${escapeHtml(a.category)}</div>
      <h1>${escapeHtml(a.title)}</h1>
      <div style="color:#6b7280;font-size:.9rem">${escapeHtml(a.author || '')} · ${new Date(a.published_at).toLocaleString()} · <a href="${a.source_url}" target="_blank" rel="noopener">source</a> · <a href="${staticHref2}" rel="canonical">permalink</a></div>
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
// Intercept SEO static links for SPA navigation (keeps fast JS, but crawlers follow static HTML)
document.addEventListener('click', e => {
  const a = e.target.closest('a[data-slug]');
  if (a && a.dataset.slug) {
    // let middle-click / ctrl-click open static page normally
    if (e.ctrlKey || e.metaKey || e.button === 1) return;
    e.preventDefault();
    location.hash = `#/article/${a.dataset.slug}`;
  }
});

load();
