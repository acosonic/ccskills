#!/usr/bin/env node
/*
 * Smoke test for an app built from the corp-webapp starter (headless Chrome, non-destructive).
 *
 * node ui-smoke.mjs --base http://localhost:8080 --user admin --pass admin123 \
 *   [--modal /procurements] [--sheet /equipment] [--out /tmp/smoke] [--ldap-probe]
 *
 * Checks: login page, local login, mandatory tour (Esc/click-outside cannot close, completes,
 * stored in localStorage + cookie), "?" replays, optional modal/sheet from a list page, JS errors.
 * --ldap-probe tries AD login with a NON-EXISTENT username (safe: cannot lock a real account).
 * puppeteer-core: $PUPPETEER_CORE (path to its entry file) or `npm install` in this scripts/ folder.
 */
import fs from 'fs';
import path from 'path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => {
    if (a.startsWith('--')) acc.push([a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]);
    return acc;
}, []));
const B = (args.base || 'http://localhost:8080').replace(/\/$/, '');
const OUT = args.out || '/tmp/ui-smoke';
fs.mkdirSync(OUT, { recursive: true });

const pp = process.env.PUPPETEER_CORE ||
    new URL('./node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js', import.meta.url).href;
const { default: puppeteer } = await import(pp);
const chrome = process.env.CHROME_PATH || ['/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/snap/bin/chromium', '/usr/bin/chromium'].find(p => fs.existsSync(p));

const browser = await puppeteer.launch({ executablePath: chrome, headless: 'new', args: ['--no-sandbox'] });
const page = await browser.newPage();
const errors = []; let failed = 0;
page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
await page.setViewport({ width: 1440, height: 900 });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const ok = (name, cond, extra = '') => { if (!cond) failed++; console.log(`${cond ? 'PASS' : 'FAIL'} ${name}${extra ? ' — ' + extra : ''}`); };
// Set values via the DOM (page.type is unreliable with some selectors).
const setVal = (sel, v) => page.evaluate((s, v) => { const el = document.querySelector(s); if (el) el.value = v; return !!el; }, sel, v);

async function login(user, pass, ldap) {
    await page.goto(B + '/login', { waitUntil: 'networkidle0' });
    const field = (await page.$('#login')) ? '#login' : (await page.$('input[name="email"]')) ? 'input[name="email"]' : 'input[name="username"]';
    await setVal(field, user);
    await setVal('input[type="password"]', pass);
    const chk = await page.$('#use_ldap, input[name="use_ldap"]');
    if (chk) { const c = await page.evaluate(e => e.checked, chk); if (c !== !!ldap) await page.evaluate(e => e.click(), chk); }
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);
}

await page.goto(B + '/login', { waitUntil: 'networkidle0' });
ok('login page', !!(await page.$('input[type="password"]')), 'AD option: ' + !!(await page.$('#use_ldap, input[name="use_ldap"]')));
await page.screenshot({ path: `${OUT}/login.png` });

if (args['ldap-probe']) {
    await login('nepostojeci.korisnik.smoke', 'x', true);
    ok('AD login with unknown account refused', /\/login/.test(page.url()));
}

await login(args.user || 'admin', args.pass || 'admin123', false);
ok('local login', !/\/login/.test(page.url()), page.url());

await sleep(1500);
const tour = !!(await page.$('.driver-popover'));
ok('mandatory tour starts', tour);
if (tour) {
    await page.keyboard.press('Escape'); await sleep(400);
    await page.mouse.click(5, 890); await sleep(400);
    ok('tour cannot be dismissed', !!(await page.$('.driver-popover')));
    await page.screenshot({ path: `${OUT}/tour.png` });
    let n = 0;
    while (await page.$('.driver-popover') && n < 40) { await page.click('.driver-popover-next-btn'); await sleep(400); n++; }
    ok('tour completes', !(await page.$('.driver-popover')), n + ' steps');
    const stored = await page.evaluate(() => [
        Object.keys(localStorage).some(k => /tutorial-done$/.test(k) && localStorage.getItem(k) === '1'),
        /_tutorial_done=1/.test(document.cookie),
    ]);
    ok('completion stored (localStorage, cookie)', stored[0] && stored[1], JSON.stringify(stored));
    await page.reload({ waitUntil: 'networkidle0' }); await sleep(1200);
    ok('no tour after completion', !(await page.$('.driver-popover')));
}
if (await page.$('[data-tour-start]')) {
    await page.click('[data-tour-start]'); await sleep(800);
    const again = !!(await page.$('.driver-popover'));
    await page.keyboard.press('Escape'); await sleep(400);
    ok('"?" replays and can be closed', again && !(await page.$('.driver-popover')));
}

if (args.modal) {
    await page.goto(B + args.modal, { waitUntil: 'networkidle0' });
    const link = await page.$('.app-content a[href$="/create"]');
    if (link) {
        await link.click();
        await page.waitForSelector('#appModal.show .fragment form', { timeout: 10000 }).catch(() => {});
        ok('form opens in modal', !!(await page.$('#appModal.show .fragment form')));
        await page.screenshot({ path: `${OUT}/modal.png` });
        await page.keyboard.press('Escape'); await sleep(500);
    } else ok('form opens in modal', false, 'no create link on ' + args.modal);
}

if (args.sheet) {
    await page.goto(B + args.sheet, { waitUntil: 'networkidle0' });
    const link = await page.$('.app-content table tbody tr td a[href]');
    if (link) {
        await link.click();
        await page.waitForSelector('#appSheet.show .fragment', { timeout: 10000 }).catch(() => {});
        await sleep(1200);
        ok('record opens in side sheet', !!(await page.$('#appSheet.show .fragment')),
            'map tiles: ' + (await page.$$eval('#appSheet .leaflet-tile-loaded', t => t.length)));
        await page.screenshot({ path: `${OUT}/sheet.png` });
    } else ok('record opens in side sheet', false, 'no row link on ' + args.sheet);
}

ok('no JavaScript errors', errors.length === 0, errors.slice(0, 3).join(' | '));
console.log(`screenshots: ${OUT}`);
await browser.close();
process.exit(failed ? 1 : 0);
