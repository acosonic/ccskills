// Shared puppeteer setup for leak_scan.mjs / contrast_check.mjs.
//
// Env:
//   BASE            app URL (required)
//   CHROME          path to a CURRENT Chrome/Chromium. Old puppeteer installs bundle e.g.
//                   Chromium 80, which has no color-mix() and silently renders the
//                   fallbacks — every result would be wrong. Checked at start-up.
//   Login (pick one, or none for public pages):
//     COOKIES=file.json                     cookies saved from a logged-in session
//     LOGIN_PATH=/login USER=… PASS=…       form login; selectors default to the usual
//     USER_SEL / PASS_SEL / SUBMIT_SEL      ones, override when the form differs
//   Scheme to apply (written to localStorage before each page, like the picker does):
//     SCHEME=<curated id> | gogh  (gogh: GOGH_ROW='["Name",1,"bg","fg",…]', GOGH_ACCENT=5)
//     DARK=1|0  PREFIX=gogh  DARK_CLASS=dark  DARK_KEY=<app's dark-mode localStorage key>
import puppeteer from 'puppeteer';
import fs from 'fs';

export async function open() {
  if (!process.env.BASE) throw new Error('set BASE');
  const b = await puppeteer.launch({ executablePath: process.env.CHROME || undefined, headless: true,
    args: ['--no-sandbox'], defaultViewport: { width: Number(process.env.W || 1440), height: 900 } });
  const p = await b.newPage();
  const ok = await p.evaluate(() => CSS.supports('color', 'color-mix(in srgb, red, white)'));
  if (!ok) { console.error(`${await b.version()} has no color-mix(): set CHROME to a current Chrome`); process.exit(2); }
  if (process.env.COOKIES) await p.setCookie(...JSON.parse(fs.readFileSync(process.env.COOKIES, 'utf8')));
  else if (process.env.LOGIN_PATH) {
    await p.goto(process.env.BASE + process.env.LOGIN_PATH, { waitUntil: 'networkidle2' });
    await p.type(process.env.USER_SEL || 'input[name=email], input[name=username], #username, #email', process.env.USER);
    await p.type(process.env.PASS_SEL || 'input[type=password]', process.env.PASS);
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }),
      p.click(process.env.SUBMIT_SEL || 'button[type=submit], input[type=submit]')]);
  }
  return { b, p };
}

export async function applyScheme(p, scheme = process.env.SCHEME || '', dark = process.env.DARK === '1') {
  await p.goto(process.env.BASE + (process.env.SCHEME_PAGE || '/'), { waitUntil: 'domcontentloaded' });
  await p.evaluate((scheme, dark, prefix, darkKey, row, accent) => {
    const k = s => prefix + '-' + s;
    if (darkKey) localStorage.setItem(darkKey, dark ? '1' : '0');
    if (!scheme) { localStorage.removeItem(k('scheme')); return; }
    localStorage.setItem(k('scheme'), scheme);
    if (scheme === 'gogh') localStorage.setItem(k('scheme-gogh'), JSON.stringify(window.GoghSchemes.build(JSON.parse(row), +accent || 5)));
  }, scheme, dark, process.env.PREFIX || 'gogh', process.env.DARK_KEY || null, process.env.GOGH_ROW || 'null', process.env.GOGH_ACCENT || '5');
}
