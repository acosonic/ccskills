/* gogh-schemes.js — selectable Gogh colour schemes for any web app. No dependencies.
 *
 * Works with three things the app provides:
 *   1. CSS that derives the app's own colour tokens from --s-* inputs under [data-scheme]
 *      (start from gogh-derive.css), plus the generated curated blocks
 *      html[data-scheme="<id>"] { --s-bg: …; … } from gen_schemes.py.
 *   2. The curated manifest (schemes.json from gen_schemes.py) — passed to configure().
 *   3. Optionally palettes.json (all ~1,250 Gogh palettes) for the "any scheme" search.
 *
 * The inline snippet gogh-early.js must run in <head> before first paint; this file can
 * load later (defer). build() is pure and also runs in Node, for parity tests against
 * gen_schemes.py: node -e "require('./gogh-schemes.js').build(row, 5)".
 *
 * Palettes: Gogh — https://github.com/Gogh-Co/Gogh — MIT / Apache-2.0.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.GoghSchemes = api;
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var cfg = {
    prefix: 'gogh',            // localStorage keys: <prefix>-scheme, <prefix>-scheme-gogh, <prefix>-recent
    darkClass: 'dark',         // class the app uses for dark mode (null = schemes don't touch it)
    darkStorageKey: null,      // app's own dark-mode localStorage key, kept in step (optional)
    darkStorageOn: '1', darkStorageOff: '0',
    curated: [],               // [{id, name, dark, swatch:[bg, fg, accent]}] from schemes.json
    defaultScheme: { id: '', name: 'Default', dark: null, swatch: ['#FFFFFF', '#111111', '#2563EB'] },
    palettesUrl: null,         // string, or a function returning one (see integration.md)
    recentMax: 4,
    onChange: null             // function (scheme) — e.g. re-render the app's own toggle icon
  };
  var GOGH_ID = 'gogh';
  var S_VARS = ['--s-bg', '--s-fg', '--s-muted', '--s-accent', '--s-on-accent',
                '--s-red', '--s-green', '--s-yellow', '--s-blue'];
  var ACCENT_SLOTS = [[5, 'Blue'], [7, 'Cyan'], [6, 'Magenta'], [3, 'Green'], [4, 'Yellow'], [2, 'Red']];

  /* ── colour maths: sRGB, identical to CSS color-mix(in srgb, …) and gen_schemes.py ── */
  var WHITE = [1, 1, 1], BLACK = [0, 0, 0];
  function hexRgb(h) {
    return [0, 2, 4].map(function (i) { return parseInt(h.replace('#', '').substr(i, 2), 16) / 255; });
  }
  function rgbHex(c) {
    return '#' + c.map(function (v) {
      var n = Math.round(Math.max(0, Math.min(1, v)) * 255);
      return (n < 16 ? '0' : '') + n.toString(16).toUpperCase();
    }).join('');
  }
  function mix(a, b, pa) { return a.map(function (x, i) { return x * pa + b[i] * (1 - pa); }); }
  function luminance(c) {
    var l = c.map(function (v) { return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
  }
  function contrast(a, b) {
    var x = luminance(a), y = luminance(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }
  function worst(c, bgs) { return Math.min.apply(null, bgs.map(function (b) { return contrast(c, b); })); }

  /* Gogh palette row → --s-* values.
     row = [name, dark(1/0), background, foreground, color_01 … color_08] (hex, no '#').
     Surfaces mirror gogh-derive.css: dark schemes lift cards towards the text colour by
     6% / 10%, light schemes towards white by 65% / 35%. Text must reach 7:1 on all three,
     muted text, accent (links) and red (errors) 4.5:1, text on the accent 4.5:1. Colours
     that fail are pulled towards white (dark) or black (light) in 5% steps; if even pure
     white/black text cannot reach 7:1 (a mid-grey background), the background moves too.
     Nothing is ever dropped — `adjusted` / `strong` tell the UI what changed. */
  function build(row, accentSlot) {
    var slotNo = accentSlot || 5;
    var dark = !!row[1], bg0 = hexRgb(row[2]), bg = bg0, fg0 = hexRgb(row[3]);
    var toward = dark ? WHITE : BLACK, adjusted = [];
    var slot = function (n) { return hexRgb(row[3 + n]); };
    var backgrounds = function (fg) {
      return dark ? [bg, mix(fg, bg, 0.06), mix(fg, bg, 0.10)]
                  : [bg, mix(WHITE, bg, 0.65), mix(WHITE, bg, 0.35)];
    };
    var nudge = function (c0, bgs) {
      for (var st = 0; st <= 100; st += 5) {
        var c = mix(toward, c0, st / 100);
        if (worst(c, bgs) >= 4.5 || st === 100) return { c: c, step: st };
      }
    };
    var fg, bgs, fgStep, bgStep;
    for (bgStep = 0; bgStep <= 100; bgStep += 5) {
      bg = mix(dark ? BLACK : WHITE, bg0, bgStep / 100);
      for (fgStep = 0; fgStep <= 100; fgStep += 5) {
        fg = mix(toward, fg0, fgStep / 100);
        bgs = backgrounds(fg);
        if (worst(fg, bgs) >= 7) break;
      }
      if (worst(fg, bgs) >= 7) break;
    }
    if (bgStep) adjusted.push('background');
    if (fgStep) adjusted.push('text');
    var muted;
    for (var pct = 72; pct <= 100; pct += 2) {
      muted = mix(fg, bg, pct / 100);
      if (worst(muted, bgs) >= 4.5) break;
    }
    var accent = nudge(slot(slotNo), bgs), red = nudge(slot(2), bgs);
    if (accent.step) adjusted.push('accent');
    if (red.step) adjusted.push('red');
    var cands = dark ? [bg, WHITE, BLACK] : [WHITE, bg, BLACK];
    var on = cands.filter(function (c) { return contrast(c, accent.c) >= 4.5; })[0] ||
             cands.slice().sort(function (a, b) { return contrast(b, accent.c) - contrast(a, accent.c); })[0];
    return {
      name: row[0], dark: dark, accentSlot: slotNo, row: row,
      adjusted: adjusted, strong: fgStep > 40 || bgStep > 0,
      swatch: [rgbHex(bg), rgbHex(fg), rgbHex(accent.c)],
      vars: {
        '--s-bg': rgbHex(bg), '--s-fg': rgbHex(fg), '--s-muted': rgbHex(muted),
        '--s-accent': rgbHex(accent.c), '--s-on-accent': rgbHex(on), '--s-red': rgbHex(red.c),
        '--s-green': '#' + row[6], '--s-yellow': '#' + row[7], '--s-blue': '#' + row[8]
      }
    };
  }

  /* ── browser part ─────────────────────────────────────────────────────────── */
  var hasDom = typeof document !== 'undefined';
  var html = hasDom ? document.documentElement : null;
  var key = function (k) { return cfg.prefix + '-' + k; };
  var store = {
    get: function (k) { try { return localStorage.getItem(key(k)); } catch (e) { return null; } },
    set: function (k, v) { try { v === null ? localStorage.removeItem(key(k)) : localStorage.setItem(key(k), v); } catch (e) {} }
  };

  function supported() {
    return !!(hasDom && window.CSS && CSS.supports('color', 'color-mix(in srgb, red, white)'));
  }
  function goghPick() {
    var g = null;
    try { g = JSON.parse(store.get('scheme-gogh')); } catch (e) {}
    return g && g.vars ? { id: GOGH_ID, name: g.name, dark: g.dark, swatch: g.swatch, gogh: g } : null;
  }
  function find(id) {
    if (!id) return cfg.defaultScheme;
    if (id === GOGH_ID) return goghPick();
    return cfg.curated.filter(function (s) { return s.id === id; })[0] || null;
  }
  function currentId() { return html.getAttribute('data-scheme') || ''; }
  function current() { return find(currentId()) || cfg.defaultScheme; }

  function recent() {
    var ids = null;
    try { ids = JSON.parse(store.get('recent')); } catch (e) {}
    var out = [];
    [currentId()].concat(Array.isArray(ids) ? ids : [], [''], cfg.curated.map(function (s) { return s.id; }))
      .forEach(function (id) { if (out.length < cfg.recentMax && out.indexOf(id) === -1 && find(id)) out.push(id); });
    return out.map(find);
  }

  function apply(id) {
    var s = find(id) || cfg.defaultScheme;
    S_VARS.forEach(function (k) { html.style.removeProperty(k); });
    if (s.gogh) Object.keys(s.gogh.vars).forEach(function (k) { html.style.setProperty(k, s.gogh.vars[k]); });
    if (s.id) { html.setAttribute('data-scheme', s.id); store.set('scheme', s.id); }
    else { html.removeAttribute('data-scheme'); store.set('scheme', null); }
    if (s.dark !== null && cfg.darkClass) {
      html.classList.toggle(cfg.darkClass, s.dark);
      if (cfg.darkStorageKey) {
        try { localStorage.setItem(cfg.darkStorageKey, s.dark ? cfg.darkStorageOn : cfg.darkStorageOff); } catch (e) {}
      }
    }
    var ids = [s.id].concat(recent().map(function (r) { return r.id; }).filter(function (x) { return x !== s.id; }));
    store.set('recent', JSON.stringify(ids.slice(0, cfg.recentMax)));
    pickers.forEach(function (p) { p.refresh(); });
    if (cfg.onChange) cfg.onChange(s);
    return s;
  }
  function applyGogh(row, accentSlot) {
    store.set('scheme-gogh', JSON.stringify(build(row, accentSlot)));
    return apply(GOGH_ID);
  }

  // palettes.json is ~50 KB gzipped: fetch it only when someone actually searches
  var palettes = null;
  function loadPalettes() {
    if (palettes) return palettes;
    var url = typeof cfg.palettesUrl === 'function' ? cfg.palettesUrl() : cfg.palettesUrl;
    palettes = !url ? Promise.reject(new Error('palettesUrl not configured')) :
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (d) { return d.schemes; });
    palettes.catch(function () { palettes = null; });       // let the next search retry
    return palettes;
  }
  // Read a url() from a CSS custom property — the asset-pipeline-proof way to learn a
  // digested URL (Propshaft, Vite, webpack… rewrite url() in CSS, never in JS strings)
  function urlFromCss(prop) {
    var m = getComputedStyle(html).getPropertyValue(prop).match(/url\(\s*["']?([^"')]+)/);
    return m ? m[1] : null;
  }

  /* ── optional picker UI (class names styled by gogh-picker.css) ─────────────── */
  var pickers = [];
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }
  function preview(swatch) {
    var p = el('span', 'gs-preview');
    p.setAttribute('aria-hidden', 'true');
    p.style.setProperty('--p-bg', swatch[0]); p.style.setProperty('--p-fg', swatch[1]); p.style.setProperty('--p-accent', swatch[2]);
    p.innerHTML = '<i></i><i></i><i></i>';
    return p;
  }
  function card(s, onClick, checked) {
    var b = el('button', 'gs-card');
    b.type = 'button'; b.title = s.name;
    b.setAttribute('role', 'radio');
    b.setAttribute('aria-checked', checked ? 'true' : 'false');
    b.appendChild(preview(s.swatch));
    b.appendChild(document.createTextNode(s.name));
    b.addEventListener('click', function (e) { e.stopPropagation(); onClick(); });
    return b;
  }

  /* Recent schemes as dots — for a header / account menu. */
  function renderRecent(container) {
    var row = el('div', 'gs-recent');
    row.setAttribute('role', 'radiogroup');
    row.setAttribute('aria-label', 'Recent colour schemes');
    var draw = function () {
      row.innerHTML = '';
      recent().forEach(function (s) {
        var d = el('button', 'gs-dot');
        d.type = 'button'; d.title = s.name;
        d.setAttribute('role', 'radio'); d.setAttribute('aria-label', s.name);
        d.setAttribute('aria-checked', s.id === currentId() ? 'true' : 'false');
        d.style.background = 'linear-gradient(135deg, ' + s.swatch[0] + ' 50%, ' + s.swatch[2] + ' 50%)';
        d.addEventListener('click', function (e) { e.stopPropagation(); apply(s.id); });
        row.appendChild(d);
      });
    };
    draw();
    pickers.push({ refresh: draw });
    container.appendChild(row);
    return row;
  }

  /* Full picker: curated cards grouped Default / Dark / Light, plus a search over every
     Gogh palette with an accent chooser and a readability note. */
  function renderPicker(container, opts) {
    opts = opts || {};
    var labels = Object.assign({ default: 'Default', dark: 'Dark', light: 'Light', any: 'Any Gogh scheme',
      search: 'Search all Gogh schemes by name…', hint: 'Applies immediately and is saved in this browser only. Palettes from Gogh (github.com/Gogh-Co/Gogh).',
      unsupported: 'This browser does not support colour schemes (needs CSS color-mix()).' }, opts.labels || {});
    var box = el('div', 'gs-picker');
    container.appendChild(box);
    if (!supported()) { box.appendChild(el('p', 'gs-hint', labels.unsupported)); return box; }

    var groups = [[labels.default, [cfg.defaultScheme]],
                  [labels.dark, cfg.curated.filter(function (s) { return s.dark; })],
                  [labels.light, cfg.curated.filter(function (s) { return !s.dark; })]];
    var cards = [];
    groups.forEach(function (g) {
      if (!g[1].length) return;
      box.appendChild(el('div', 'gs-group', g[0]));
      var grid = el('div', 'gs-grid');
      grid.setAttribute('role', 'radiogroup'); grid.setAttribute('aria-label', g[0]);
      g[1].forEach(function (s) {
        var c = card(s, function () { apply(s.id); }, s.id === currentId());
        c.setAttribute('data-gs-id', s.id);
        cards.push(c); grid.appendChild(c);
      });
      box.appendChild(grid);
    });

    var any = null, cur = null, results = null;
    if (cfg.palettesUrl) {
      any = el('div', 'gs-any');
      any.appendChild(el('div', 'gs-group', labels.any));
      cur = el('div', 'gs-current');
      var input = el('input', 'gs-search');
      input.type = 'search'; input.placeholder = labels.search;
      input.setAttribute('aria-label', labels.search);
      var status = el('p', 'gs-hint gs-status');
      status.setAttribute('aria-live', 'polite');
      results = el('div', 'gs-grid gs-results');
      [cur, input, status, results].forEach(function (n) { any.appendChild(n); });
      box.appendChild(any);
      var timer = null;
      input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          var q = input.value.trim().toLowerCase();
          if (!q) { results.innerHTML = ''; status.textContent = ''; return; }
          if (!palettes) status.textContent = 'Loading…';
          loadPalettes().then(function (rows) {
            if (input.value.trim().toLowerCase() !== q) return;   // a newer search is running
            results.innerHTML = '';                                // swap only now: no empty flash per keystroke
            var hits = rows.filter(function (r) { return r[0].toLowerCase().indexOf(q) !== -1; });
            var pick = goghPick();
            hits.slice(0, 24).forEach(function (r) {
              var c = card(build(r, 5), function () { applyGogh(r, 5); },
                           currentId() === GOGH_ID && pick && pick.name === r[0]);
              c.setAttribute('data-gs-gogh', r[0]);
              results.appendChild(c);
            });
            status.textContent = !hits.length ? 'No scheme matches “' + input.value.trim() + '”.' :
              hits.length > 24 ? 'Showing 24 of ' + hits.length + ' — type more to narrow it down.' :
              hits.length + (hits.length === 1 ? ' match.' : ' matches.');
          }, function () { status.textContent = 'Could not load the Gogh palettes.'; });
        }, 150);
      });
    }
    box.appendChild(el('p', 'gs-hint', labels.hint));

    var refresh = function () {
      var id = currentId();
      cards.forEach(function (c) { c.setAttribute('aria-checked', c.getAttribute('data-gs-id') === id ? 'true' : 'false'); });
      if (!cur) return;
      var pick = goghPick();
      if (results) [].forEach.call(results.children, function (c) {
        c.setAttribute('aria-checked', id === GOGH_ID && pick && pick.name === c.getAttribute('data-gs-gogh') ? 'true' : 'false');
      });
      cur.innerHTML = '';
      if (id !== GOGH_ID || !pick) { cur.hidden = true; return; }
      cur.hidden = false;
      var g = pick.gogh;
      cur.appendChild(el('div', 'gs-title', g.name));
      var dots = el('div', 'gs-recent gs-accents');
      dots.setAttribute('role', 'radiogroup'); dots.setAttribute('aria-label', 'Accent colour');
      ACCENT_SLOTS.forEach(function (a) {
        var d = el('button', 'gs-dot');
        d.type = 'button'; d.title = a[1] + ' accent';
        d.setAttribute('role', 'radio'); d.setAttribute('aria-label', a[1] + ' accent');
        d.setAttribute('aria-checked', a[0] === g.accentSlot ? 'true' : 'false');
        d.style.background = build(g.row, a[0]).vars['--s-accent'];
        d.addEventListener('click', function () { applyGogh(g.row, a[0]); });
        dots.appendChild(d);
      });
      cur.appendChild(dots);
      // red is nudged for most palettes and nobody notices — mention only what shows
      var shown = g.adjusted.filter(function (x) { return x !== 'red'; });
      if (shown.length) cur.appendChild(el('p', 'gs-hint',
        (g.strong ? 'This palette has low contrast, so its colours were changed noticeably' : 'Adjusted for readability') +
        ' (' + shown.join(', ') + ').'));
    };
    refresh();
    pickers.push({ refresh: refresh });
    return box;
  }

  function configure(o) {
    Object.keys(o || {}).forEach(function (k) { cfg[k] = o[k]; });
    // a stored scheme that no longer exists (renamed, dropped, storage cleared) would leave
    // the page without colours — fall back to the app's default palette
    if (hasDom && currentId() && !find(currentId())) apply('');
    return api;
  }

  var api = {
    configure: configure, build: build, apply: apply, applyGogh: applyGogh,
    current: current, recent: recent, find: find, supported: supported,
    loadPalettes: loadPalettes, urlFromCss: urlFromCss,
    renderPicker: renderPicker, renderRecent: renderRecent,
    contrast: function (a, b) { return contrast(hexRgb(a), hexRgb(b)); },
    GOGH_ID: GOGH_ID, ACCENT_SLOTS: ACCENT_SLOTS
  };
  return api;
}));
