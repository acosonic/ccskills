// Helpers for a narrated screen recording with Playwright.
// const demo = await require('./demo').start({ name: 'panel-en', viewport: { width: 1280, height: 800 } });
// Every scene starts with `await demo.step('scene name')`: it waits until the previous scene's narration has finished,
// then writes a cue line ("T <ms> <clip>") that mix.sh uses to place the voice clip. Scenes without narration are fine.
const fs = require('fs'), path = require('path');

exports.start = async function ({ name, viewport, mobile = false, locale = 'en-GB', dir = process.cwd(), playwright = 'playwright', gap = 500 }) {
  const { chromium } = require(playwright);
  const durations = (() => { try { return JSON.parse(fs.readFileSync(path.join(dir, 'voice', name, 'durations.json'), 'utf8')); } catch (_) { return {}; } })();
  const browser = await chromium.launch();
  const tmp = path.join(dir, 'raw', '.tmp-' + name);
  const ctx = await browser.newContext(Object.assign({ viewport, locale, recordVideo: { dir: tmp, size: viewport } },
    mobile ? { deviceScaleFactor: 2, isMobile: true, hasTouch: true } : { deviceScaleFactor: 1 }));
  const page = await ctx.newPage();
  const t0 = Date.now(); let busyUntil = 0; const cues = [];
  const wait = ms => page.waitForTimeout(ms);

  const demo = {
    page, wait,
    // Start a scene. Blocks until the previous narration clip is over, so speech never overlaps.
    async step(scene) {
      const left = busyUntil - Date.now(); if (left > 0) await wait(left);
      console.log('scene: ' + scene);
      const clip = durations[scene];
      if (clip) { cues.push('T ' + (Date.now() - t0 + 300) + ' ' + clip.file); busyUntil = Date.now() + 300 + clip.seconds * 1000 + gap; }
    },
    async scrollTo(y, ms = 1700) { await page.evaluate(top => window.scrollTo({ top, behavior: 'smooth' }), y); await wait(ms); },
    async scrollBy(dy, ms = 2400) { await demo.scrollTo(await page.evaluate(d => window.scrollY + d, dy), ms); },
    // Smooth-scroll an element into view, `offset` pixels below the top edge.
    async show(selector, offset = 90, ms = 2200) {
      await page.evaluate(([s, o]) => { const e = document.querySelector(s); window.scrollTo({ top: e.getBoundingClientRect().top + window.scrollY - o, behavior: 'smooth' }); }, [selector, offset]);
      await wait(ms);
    },
    // Type like a person, so the viewer can follow.
    async type(selector, text, delay = 35) { await page.click(selector); await page.type(selector, text, { delay }); },
    // Full-screen title card: brand line, title, a lead line and up to four points. numbered=false gives bullets.
    async titleCard({ color = '#234d3c', brand = '', title, lead = '', points = [], foot = '', numbered = true }) {
      const big = !mobile, esc = s => String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
      await page.setContent('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<style>html,body{height:100%;margin:0}body{display:flex;flex-direction:column;justify-content:center;box-sizing:border-box;padding:0 9%;background:' + color + ';color:#fff;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}'
        + 'small{font-size:' + (big ? 22 : 17) + 'px;letter-spacing:.14em;text-transform:uppercase;opacity:.8}h1{font-size:' + (big ? 62 : 40) + 'px;line-height:1.1;margin:.25em 0 .7em}p{font-size:' + (big ? 26 : 20) + 'px;margin:0 0 .5em;opacity:.85}'
        + 'ol{margin:0;padding:0;list-style:none;counter-reset:n}li{counter-increment:n;display:flex;gap:.7em;align-items:baseline;font-size:' + (big ? 32 : 23) + 'px;line-height:1.3;margin:0 0 .65em}'
        + 'li::before{content:' + (numbered ? 'counter(n)' : "'•'") + ';flex:none;width:1.5em;height:1.5em;line-height:1.5em;border-radius:50%;background:#fff;color:' + color + ';text-align:center;font-weight:700;font-size:.8em}'
        + 'footer{margin-top:1.4em;font-size:' + (big ? 24 : 19) + 'px;opacity:.85}</style>'
        + '<small>' + esc(brand) + '</small><h1>' + esc(title) + '</h1><p>' + esc(lead) + '</p><ol>' + points.map(x => '<li>' + esc(x) + '</li>').join('') + '</ol>' + (foot ? '<footer>' + esc(foot) + '</footer>' : ''));
      await wait(500);
    },
    // Cover a canvas (for example a payment QR code built from made-up data) with a label, so nobody acts on it.
    async stampCanvas(selector, label = 'SAMPLE') {
      await page.evaluate(([s, text]) => { const c = document.querySelector(s), g = c.getContext('2d'); g.fillStyle = 'rgba(255,255,255,0.93)'; g.fillRect(0, c.height * 0.36, c.width, c.height * 0.28); g.fillStyle = '#a02828'; g.font = 'bold ' + Math.round(c.height * 0.17) + 'px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(text, c.width / 2, c.height / 2); }, [selector, label]);
    },
    // Wait for the last narration, close, and leave raw/<name>.webm and raw/<name>.cues for mix.sh.
    async finish() {
      await demo.step('(end)');
      await ctx.close();
      const video = await page.video().path();
      await browser.close();
      fs.renameSync(video, path.join(dir, 'raw', name + '.webm')); fs.rmSync(tmp, { recursive: true, force: true });
      fs.writeFileSync(path.join(dir, 'raw', name + '.cues'), cues.join('\n') + '\n');
      console.log('raw/' + name + '.webm, ' + cues.length + ' cues');
    },
  };
  return demo;
};
