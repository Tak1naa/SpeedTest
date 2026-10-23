// Own-loopback, mocked backend only: exercise recovery copy without quota use.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
if(process.argv.length!==3 || process.argv[2]!=='--allow-disposable-ui') {
  console.error('Explicit --allow-disposable-ui required before browser imports');process.exit(2);
}
const base=process.env.SPEEDTEST_URL||'https://127.0.0.1:8443';
assert(['127.0.0.1','[::1]','localhost'].includes(new URL(base).hostname));
const output=path.resolve(process.env.ERROR_SCREENSHOT_DIR||'.screenshots');
const names=['error-desktop.png','error-mobile.png'];
for(const name of names) assert(!fs.existsSync(path.join(output,name)),'Refusing screenshot overwrite');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
  const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})});
  try {
    fs.mkdirSync(output,{recursive:true});
    for(const [width,name] of [[1100,names[0]],[390,names[1]]]) {
      const context=await browser.newContext({viewport:{width,height:844},ignoreHTTPSErrors:process.env.TEST_PRIVATE_CA==='1'});
      let status=429;
      await context.route('**/backend/**',route=>{
        assert.equal(new URL(route.request().url()).origin,new URL(base).origin);
        return route.fulfill({status,body:'mocked protection error',headers:{'Retry-After':'10'}});
      });
      const page=await context.newPage();
      await page.goto(base);
      for(const [code,copy] of [[429,'访问频率限制'],[403,'访问权限'],[503,'暂时不可用']]) {
        status=code;
        await page.locator('#startStopBtn').click();
        await page.waitForFunction(()=>!admitting);
        assert((await page.locator('#testStatus').textContent()).includes(copy));
        assert(!(await page.locator('#startStopBtn').getAttribute('class')).includes('running'));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        if(code===429) await page.screenshot({path:path.join(output,name),fullPage:true});
      }
      await context.close();
    }
    console.log('PASS: mocked 403/429/503 recovery states at desktop/compact viewports; no real backend or mobile-device acceptance');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
