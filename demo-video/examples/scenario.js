// Example scenario: a back-office panel on a computer-sized screen. Copy, rename and replace the scenes.
//   NAME=panel-en BASE=http://127.0.0.1:8000 LOGIN_EMAIL=... LOGIN_PW=... node scenario.js
const { start } = require('../scripts/demo');
(async () => {
  const demo = await start({ name: process.env.NAME || 'panel-en', viewport: { width: 1280, height: 800 }, playwright: process.env.PLAYWRIGHT || 'playwright' });
  const { page, wait, step } = demo, B = process.env.BASE;
  const card = { color: '#234d3c', brand: 'Product name', title: 'The nutritionist\'s panel' };

  // 1. Tell them what this is and who it is for.
  await demo.titleCard({ ...card, lead: 'What is this?', numbered: false, points: ['A platform for nutritionists', 'Clients, meal plans, appointments and billing in one place', 'Every client gets an app under your name'] });
  await step('about');
  // 2. Tell them what you are going to tell them. step() first, so the card changes only when the voice has finished.
  await step('(card)'); await demo.titleCard({ ...card, lead: 'In this video:', points: ['Clients', 'Meal plans', 'Invoices'] });
  await step('intro');

  // 3. Tell them.
  await step('(sign in)'); await page.goto(B + '/login'); await wait(1000);
  await demo.type('#email', process.env.LOGIN_EMAIL); await demo.type('#password', process.env.LOGIN_PW);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.goto(B + '/clients'); await step('clients'); await wait(2000); await demo.scrollBy(300);
  await page.goto(B + '/plans/1'); await step('plan'); await demo.show('.plan-grid', 20, 3000);
  await page.goto(B + '/invoices'); await step('invoices'); await wait(2500);

  // 4. Tell them what you told them.
  await step('(card)'); await demo.titleCard({ ...card, lead: 'You have seen:', points: ['Clients', 'Meal plans', 'Invoices'], foot: 'Thank you for watching' });
  await step('outro');
  await demo.finish();
})().catch(e => { console.error('FAILED:', e.message.split('\n')[0]); process.exit(1); });
