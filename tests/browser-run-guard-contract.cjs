// Only CLI preflight checks, with a deliberately missing browser module.
// No browser, sockets, quota consumption or production request is possible.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {spawnSync}=require('node:child_process');
const script=path.join(__dirname,'browser-smoke.cjs');
const missing=path.join(__dirname,'__missing_playwright_for_guard_test__');
function run(args=[],extra={}) {
  return spawnSync(process.execPath,[script,...args],{
    env:{...process.env,PLAYWRIGHT_MODULE:missing,BROWSER_RESULT:'',...extra},
    encoding:'utf8',timeout:5000,
  });
}
let result=run();
assert.equal(result.status,2);
assert(result.stderr.includes('--allow-quota-consumption required'));
assert(!result.stderr.includes('Cannot find module'));
result=run(['--help']);
assert.equal(result.status,0);
assert(result.stdout.includes('normal quotas'));
result=run(['--allow-quota-consumption'],{BROWSER_RESULT:'/tmp/browser-test.json'});
assert.equal(result.status,2);
assert(result.stderr.includes('before any request'));
result=run(['--allow-quota-consumption'],{BROWSER_RESULT:path.join(__dirname,'not-json.txt')});
assert.equal(result.status,2);
const fixture=path.join(__dirname,`.browser-guard-${process.pid}.json`);
let created=false;
try {
  fs.writeFileSync(fixture,'owned fixture\n',{flag:'wx',mode:0o600});
  created=true;
  result=run(['--allow-quota-consumption'],{BROWSER_RESULT:fixture});
  assert.equal(result.status,2);
  assert(result.stderr.includes('refusing overwrite'));
  assert.equal(fs.readFileSync(fixture,'utf8'),'owned fixture\n');
} finally {
  if(created) fs.unlinkSync(fixture);
}
// An explicit permitted invocation reaches the deliberately missing loader,
// not a browser. This is gate validation, not another real Worker test.
result=run(['--allow-quota-consumption']);
assert.equal(result.status,1);
assert(result.stderr.includes('Cannot find module'));
const source=fs.readFileSync(script,'utf8');
assert.equal((source.match(/flag:'wx',mode:0o600/g)||[]).length,2);
console.log('PASS: explicit real-traffic gate and help before browser import; evidence confined to new tests JSON with exclusive writes; no network');
