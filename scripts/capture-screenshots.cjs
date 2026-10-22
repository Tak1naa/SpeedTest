// Read-only captures of an isolated public checkout, never a live test.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const base = new URL(process.env.SCREENSHOT_URL || 'https://127.0.0.1:8443/');
assert(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Loopback preview only');
assert(['http:', 'https:'].includes(base.protocol) && !base.username && !base.password);
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const output = path.resolve(process.env.SCREENSHOT_DIR || '.screenshots');
  fs.mkdirSync(output, {recursive: true});
  for (const name of ['desktop.png', 'mobile.png', 'coffee.png']) assert(!fs.existsSync(path.join(output, name)), 'Refusing screenshot overwrite');
  const browser = await chromium.launch({headless: true, ...(process.env.BROWSER_EXECUTABLE ? {executablePath: process.env.BROWSER_EXECUTABLE} : {})});
  try {
    for (const [name, viewport] of [['desktop', {width: 1280, height: 900}], ['mobile', {width: 390, height: 844}]]) {
      const context = await browser.newContext({viewport, ignoreHTTPSErrors: process.env.TEST_PRIVATE_CA === '1'});
      await context.route('**/*', route => {
        const url = new URL(route.request().url());
        return url.origin !== base.origin || url.pathname.includes('/backend/') ? route.abort() : route.continue();
      });
      const page = await context.newPage();
      assert.equal((await page.goto(base.href)).status(), 200);
      await page.waitForFunction(() => document.querySelector('#dlMeter')?.width > 0);
      await page.screenshot({path: path.join(output, name + '.png'), fullPage: true});
      if (name === 'desktop') {
        await page.locator('#coffeeOpen').click();
        await page.locator('#coffeeQr').waitFor({state: 'visible'});
        await page.waitForFunction(() => document.querySelector('#coffeeQr').complete);
        assert((await page.locator('#coffeeQr').getAttribute('src')).endsWith('coffee-qr.svg'), 'Public placeholder required');
        await page.screenshot({path: path.join(output, 'coffee.png')});
      }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Horizontal overflow');
      await context.close();
    }
  } finally { await browser.close(); }
  console.log('PASS: real idle UI/public-placeholder screenshots; all backend and foreign-origin requests blocked');
})().catch(error => {console.error(error.message); process.exitCode = 1;});
