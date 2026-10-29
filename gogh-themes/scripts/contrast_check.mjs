// contrast_check.mjs — rendered WCAG contrast per scheme, measured on real elements.
//
//   BASE=… PAGE=/settings CHROME=… SCHEMES=dracula:1,nord:1,solarized-light:0 \
//   SELECTORS='label,input[type=text],a[href],button[type=submit],h1' node contrast_check.mjs
//
// For each scheme (id:dark) loads PAGE and measures text colour against the composited
// background behind it (translucent layers included). Prints LOW for anything < 4.5:1.
// Parses colours itself — canvas round-tripping mangles color(srgb …) and alpha.
import { open, applyScheme } from './_browser.mjs';

const SCHEMES = (process.env.SCHEMES || ':0,:1').split(',').map(s => s.split(':'));
const SELECTORS = (process.env.SELECTORS || 'body,label,a[href],button,input[type=submit],h1,h2').split(',');
const { b, p } = await open();
let low = 0;
for (const [id, dark] of SCHEMES) {
  await applyScheme(p, id, dark === '1');
  await p.goto(process.env.BASE + (process.env.PAGE || '/'), { waitUntil: 'networkidle2' });
  const r = await p.evaluate(sels => {
    const parse = v => {
      let m = v.match(/color\(srgb ([\d.e-]+) ([\d.e-]+) ([\d.e-]+)(?: \/ ([\d.]+))?\)/);
      if (m) return [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]];
      m = v.match(/rgba?\(([\d.]+), ([\d.]+), ([\d.]+)(?:, ([\d.]+))?\)/);
      return m ? [m[1] / 255, m[2] / 255, m[3] / 255, m[4] === undefined ? 1 : +m[4]] : [0, 0, 0, 0];
    };
    const over = (t, u) => t.slice(0, 3).map((c, i) => c * t[3] + u[i] * (1 - t[3]));
    const L = c => { const l = c.map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4); return .2126 * l[0] + .7152 * l[1] + .0722 * l[2]; };
    const C = (a, c) => { const [x, y] = [L(a), L(c)].sort((m, n) => n - m); return (x + .05) / (y + .05); };
    const bgOf = e => { const s = []; for (; e; e = e.parentElement) s.push(parse(getComputedStyle(e).backgroundColor)); return s.reverse().reduce((a, c) => over(c, a), [1, 1, 1]); };
    return Object.fromEntries(sels.map(sel => {
      const e = [...document.querySelectorAll(sel)].find(x => x.getBoundingClientRect().width > 1);
      if (!e) return [sel, null];
      const bg = bgOf(e); return [sel, +C(over(parse(getComputedStyle(e).color), bg), bg).toFixed(2)];
    }));
  }, SELECTORS);
  const bad = Object.entries(r).filter(([, v]) => v !== null && v < 4.5);
  if (bad.length) low++;
  console.log((bad.length ? 'LOW ' : 'ok  ') + (id || 'default').padEnd(22) + (dark === '1' ? 'dark  ' : 'light ') + JSON.stringify(r));
}
console.log(`\n${SCHEMES.length - low} of ${SCHEMES.length} schemes pass AA on ${process.env.PAGE || '/'}`);
await b.close();
