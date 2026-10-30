// Exercise the actual entry/cookie expressions without loading a browser.
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'browser-smoke.cjs'),'utf8');
const chunk=source.match(/const base = .*\nconst cookieScope = .*\n/)[0];
for(const input of ['https://localhost:8443','https://localhost:8443/','https://[::1]:8443/speedtest','https://[::1]:8443/speedtest/']){
  const context={process:{env:{SPEEDTEST_URL:input}}};
  vm.runInNewContext(chunk+'globalThis.result={base,cookieScope};',context);
  assert(!context.result.base.endsWith('/'));
  assert(context.result.cookieScope.endsWith('/'));
  assert.equal(new URL(context.result.cookieScope).pathname,input.includes('/speedtest')?'/speedtest/':'/');
}
assert.equal((source.match(/context\.cookies\(cookieScope\)/g)||[]).length,2);
assert(!source.includes('context.cookies(base)'));
console.log('PASS: actual root/IPv6 subpath entry and scoped-cookie URL expressions; no requests');
