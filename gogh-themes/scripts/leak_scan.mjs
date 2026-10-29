// leak_scan.mjs — find colours that ignore the active scheme (hard-coded in the app, its
// framework or plugins). For every page, with a scheme applied, collects each visible
// element's background / text / border colour and SVG icon stroke, and reports the ones
// that are not one of the app's tokens (or a color-mix() of them).
//
//   BASE=http://localhost:8000 PAGES=/,/settings,/users SCHEME=dracula DARK=1 \
//   CHROME=/path/to/chrome node leak_scan.mjs
//
// Run it for the default palette too (SCHEME=) — colours that also leak there are the
// app's own hard-coded greys; they only *show* once a tinted scheme is active.
// EXCLUDE: selector whose subtree is skipped — default the picker itself, whose previews
// deliberately show other schemes' colours. Login / scheme env: see _browser.mjs.
// Needs a current Chrome (color-mix()).
import { open, applyScheme } from './_browser.mjs';

const PAGES = (process.env.PAGES || '/').split(',');
const TOKEN_PREFIX = process.env.TOKEN_PREFIX || '--';   // which custom properties count as tokens
const EXCLUDE = process.env.EXCLUDE || '.gs-picker, .gs-recent';
const { b, p } = await open();
await applyScheme(p);
const found = {};
for (const path of PAGES) {
  await p.goto(process.env.BASE + path, { waitUntil: 'networkidle2' });
  const res = await p.evaluate((prefix, exclude) => {
    const probe = document.createElement('div'); document.body.appendChild(probe);
    const norm = v => { probe.style.color = ''; probe.style.color = v; return getComputedStyle(probe).color; };
    const root = getComputedStyle(document.documentElement);
    const allowed = new Set(['rgba(0, 0, 0, 0)', norm('white'), norm('black')]);
    for (const n of root) if (n.startsWith(prefix)) { const v = root.getPropertyValue(n).trim(); if (v && !/url\(/.test(v)) allowed.add(norm(v)); }
    probe.remove();
    const desc = e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') +
      (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
    const out = [];
    document.querySelectorAll('body *').forEach(e => {
      const r = e.getBoundingClientRect(); if (r.width < 2 || r.height < 2) return;
      if (e.closest('svg') && e.tagName.toLowerCase() !== 'svg') return;
      if (exclude && e.closest(exclude)) return;
      const c = getComputedStyle(e);
      if (c.visibility === 'hidden' || c.display === 'none' || c.opacity === '0') return;
      const checks = [['bg', c.backgroundColor]];
      if ([...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) checks.push(['text', c.color]);
      if (parseFloat(c.borderTopWidth) > 0 && c.borderTopStyle !== 'none') checks.push(['border', c.borderTopColor]);
      if (e.tagName.toLowerCase() === 'svg' && c.stroke !== 'none') checks.push(['stroke', c.stroke]);
      for (const [k, v] of checks) {
        if (allowed.has(v) || v.startsWith('color(srgb') || /, 0\)$/.test(v)) continue;  // color-mix() results come back as color(srgb …)
        if (k === 'bg' && /^(input|select|textarea|img|canvas|video)$/i.test(e.tagName) && e.type !== 'text') continue;
        out.push([k, v, desc(e)]);
      }
    });
    return out;
  }, TOKEN_PREFIX, EXCLUDE);
  for (const [k, v, d] of res) {
    const key = `${k} ${v}`;
    (found[key] ||= { n: 0, where: new Set() }).n++;
    if (found[key].where.size < 4) found[key].where.add(`${path} ${d}`);
  }
}
const rows = Object.entries(found).sort((a, c) => c[1].n - a[1].n);
console.log(`${process.env.SCHEME || 'default'} (dark=${process.env.DARK || 0}): ${rows.length} colours outside the tokens`);
for (const [k, v] of rows.slice(0, 50)) console.log(String(v.n).padStart(5), k.padEnd(36), [...v.where].join(' | '));
await b.close();
