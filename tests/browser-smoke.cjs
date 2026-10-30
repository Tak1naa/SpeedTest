// Real upstream Worker, no algorithm overrides. Runs within the normal service quotas.
// Refuse BEFORE importing Playwright or opening a real production page.
const fs = require('node:fs');
const path = require('node:path');
const args=process.argv.slice(2);
if(args.length===1 && args[0]==='--help') {
  console.log('Usage: node tests/browser-smoke.cjs --allow-quota-consumption\nRuns real upstream Worker within normal quotas. BROWSER_RESULT must be a new tests/*.json file.');
  process.exit(0);
}
if(args.length!==1 || args[0]!=='--allow-quota-consumption') {
  console.error('Explicit --allow-quota-consumption required; real browser tests use traffic and session quota');
  process.exit(2);
}
if(process.env.BROWSER_RESULT) {
  const result=path.resolve(process.env.BROWSER_RESULT);
  if(path.dirname(result)!==__dirname || path.extname(result)!=='.json' || fs.existsSync(result)) {
    console.error('BROWSER_RESULT must be a new JSON file directly in tests; refusing overwrite before any request');
    process.exit(2);
  }
}
const {chromium, firefox} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const {startAndVerifyAdmission}=require('./browser-admission.cjs');
const {classifyCleanupRejections}=require('./browser-cleanup.cjs');
const base = process.env.SPEEDTEST_URL || 'https://127.0.0.1:8443';
const kind = process.env.BROWSER_KIND || 'chromium';
const type = kind === 'firefox' ? firefox : chromium;
const throttleKiB=Number(process.env.ISOLATED_THROTTLE_KIB||0);
const cancelPhase=process.env.TEST_CANCEL_PHASE || 'admission';
assert(['admission','download'].includes(cancelPhase),'Unsupported cancellation phase');
if(cancelPhase==='download') {
  const target=new URL(base);
  assert(process.env.TEST_CANCEL==='1' && ['127.0.0.1','[::1]','localhost'].includes(target.hostname) && !process.env.TEST_PROXY,
    'Download cancellation probe is restricted to an explicitly disposable loopback run');
}
if(process.env.ISOLATED_THROTTLE_KIB!==undefined){
  const target=new URL(base);
  assert(kind==='chromium' && ['127.0.0.1','[::1]'].includes(target.hostname) && !process.env.TEST_PROXY);
  assert(Number.isInteger(throttleKiB) && throttleKiB>=64 && throttleKiB<=2048,'bounded loopback-only throttle');
}
(async () => {
  // Our handler must finish the owned cookie before Playwright closes Chromium.
  const browser = await type.launch({headless:true,handleSIGTERM:false,handleSIGINT:false, ...(process.env.BROWSER_EXECUTABLE ? {executablePath:process.env.BROWSER_EXECUTABLE} : {})});
  const mobile=process.env.MOBILE_EMULATION==='1';
  // Optional test-only relay preserves LAN source when a VPN route takes precedence.
  // Such a run proves Worker behavior, not direct-path or WAN throughput.
  const testProxy=process.env.TEST_PROXY ? {proxy:{server:process.env.TEST_PROXY}} : {};
  const context = await browser.newContext({...testProxy,... (mobile ? {
    viewport:{width:390,height:844},isMobile:true,hasTouch:true,
    userAgent:'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36'
  } : {viewport:{width:1100,height:800}}),ignoreHTTPSErrors:process.env.TEST_PRIVATE_CA==='1'});
  const page = await context.newPage();
  const shutdown=require('./browser-stop.cjs').installBrowserStop({page,context,browser,base});
  if(throttleKiB){
    // Test transport only; never alter the upstream Worker or its timing knobs.
    const cdp=await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:5,
      downloadThroughput:throttleKiB*1024,uploadThroughput:throttleKiB*1024});
  }
  let errors=0;
  let phase='load';
  const browserErrors=[];
  const rejected=[];
  const requestStarted=new WeakMap();
  const responseDiagnostics=[];
  const diagnosticReads=[];
  const diagnosticStarted=Date.now();
  const pingRequests=[];
  page.on('requestfinished',request=>{
    if(request.method()!=='GET' || new URL(request.url()).pathname!=='/backend/empty.php') return;
    // Test-only timing evidence, no URL/query, source IP, headers or cookies.
    // This is HTTP resource timing, not a replacement for upstream ping/jitter.
    const timing=request.timing();
    pingRequests.push({elapsedMs:Date.now()-diagnosticStarted,
      totalMs:timing.responseEnd>=0?Number(timing.responseEnd.toFixed(3)):null,
      requestToFirstByteMs:timing.requestStart>=0&&timing.responseStart>=timing.requestStart?
        Number((timing.responseStart-timing.requestStart).toFixed(3)):null});
  });
  const initialCleanupRequests=new Set();
  page.on('request',request=>{
    requestStarted.set(request,Date.now()-diagnosticStarted);
    if(phase==='load' && request.method()==='POST' && request.url().includes('/backend/getIP.php') && request.headers()['x-speedtest-action']==='finish') initialCleanupRequests.add(request);
  });
  page.on('pageerror',e=>{errors++;browserErrors.push(e.message.replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g,'[ip]'));});
  page.on('response',r=>{
    if(r.url().includes('/backend/getIP.php') && r.request().method()==='POST' && r.status()===204)
      responseDiagnostics.push({event:'finish204',elapsedMs:Date.now()-diagnosticStarted});
    // Initial cleanup with no cookie is expected 403, not a rejected test stream.
    if(r.status()===403 && initialCleanupRequests.has(r.request())) return;
    if(r.url().includes('/backend/') && r.status()>=400) {
      const entry={endpoint:new URL(r.url()).pathname,method:r.request().method(),status:r.status(),requestStartedMs:requestStarted.get(r.request()),responseMs:Date.now()-diagnosticStarted};
      rejected.push(entry);
      // No request headers/body/cookies: retain only the protection message and state.
      diagnosticReads.push(Promise.all([r.text().catch(()=>''),page.evaluate(()=>s.getState()).catch(()=>null)]).then(([body,state])=>{
        entry.responseState=state;
        entry.reason=body.replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g,'[ip]').slice(0,160);
      }));
    }
  });
  try {
    assert.equal((await page.goto(base)).status(),200);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
    if(process.env.TEST_CANCEL === '1') {
      phase='cancel admission';
      const payload=cancelPhase==='download' ? page.waitForResponse(r=>
        new URL(r.url()).pathname==='/backend/garbage.php' && r.status()===200,{timeout:30000}) : null;
      if(payload) payload.catch(()=>{});
      await startAndVerifyAdmission(page,process.env.EXPECTED_CLIENT_IP);
      if(payload) {
        phase='cancel active download';
        await payload;
        await page.waitForFunction(()=>uiData && uiData.testState===1,null,{timeout:5000});
      }
      const finished=page.waitForResponse(r=>r.url().includes('/backend/getIP.php') && r.request().method()==='POST' && r.status()===204);
      await page.locator('#startStopBtn').click();
      await finished;
      assert.notEqual(await page.evaluate(()=>s.getState()),3);
    }
    const started=Date.now();
    phase='full admission';
    await startAndVerifyAdmission(page,process.env.EXPECTED_CLIENT_IP);
    const cookie=(await context.cookies(base)).find(c=>c.name==='ST_SESSION');
    assert(cookie && cookie.httpOnly && cookie.sameSite==='Lax');
    if(base.startsWith('https://')) assert(cookie.secure,'TLS session cookie must be Secure');
    if(process.env.TEST_QUOTAS==='1') {
      const competing=await browser.newContext({...testProxy,ignoreHTTPSErrors:process.env.TEST_PRIVATE_CA==='1'});
      try {assert.equal((await competing.request.get(base+'/backend/getIP.php',{headers:{'X-Speedtest-Action':'start'}})).status(),429);}
      finally {await competing.close();}
    }
    const finished=page.waitForResponse(r=>r.url().includes('/backend/getIP.php') && r.request().method()==='POST' && r.status()===204,{timeout:75000});
    finished.catch(()=>{});
    // Keep protection traffic small and run it alongside the unchanged Worker.
    const pressure=process.env.TEST_PRESSURE==='1' ? require('./load-pressure.cjs').runPressure(browser,base) : Promise.resolve(null);
    pressure.catch(()=>{}); // Error is propagated by the awaited result below.
    phase='full measurement';
    await page.waitForFunction(()=>s.getState()===4,null,{timeout:75000});
    await finished;
    const pressureResult=await pressure;
    const data=await page.evaluate(()=>({state:uiData.testState,downloadMbps:Number(uiData.dlStatus),uploadMbps:Number(uiData.ulStatus),pingMs:Number(uiData.pingStatus),jitterMs:Number(uiData.jitterStatus)}));
    await Promise.all(diagnosticReads);
    assert.equal(data.state,4);
    // The unchanged Worker skips IP discovery when admission already supplied it.
    // Verify both the admission value and its visible fallback, not an empty Worker field.
    if(process.env.EXPECTED_CLIENT_IP) {
      assert((await page.evaluate(()=>String(admittedIp))).includes(process.env.EXPECTED_CLIENT_IP),'admitted source must remain the intended client');
      assert((await page.locator('#ip').textContent()).includes(process.env.EXPECTED_CLIENT_IP),'displayed source must match the verified admission');
    }
    assert(data.downloadMbps>0 && data.uploadMbps>0 && data.pingMs>=0);
    assert((await page.locator('#testStatus').textContent()).includes('测速完成：'),'final metrics must be announced accessibly');
    assert.equal(errors,0);
    const {cleanupResponses,unexpected}=classifyCleanupRejections(rejected,responseDiagnostics);
    assert.deepEqual(unexpected,[]);
    assert(!(await context.cookies(base)).some(c=>c.name==='ST_SESSION'));
    if(process.env.TEST_QUOTAS==='1' && process.env.TEST_CANCEL==='1') {
      assert.equal((await context.request.get(base+'/backend/getIP.php',{headers:{'X-Speedtest-Action':'start'}})).status(),429);
    }
    const result={browser:kind,version:browser.version(),checkedAt:new Date().toISOString(),sourceVerified:Boolean(process.env.EXPECTED_CLIENT_IP),testRelay:Boolean(process.env.TEST_PROXY),privateCaTestException:process.env.TEST_PRIVATE_CA==='1',mobileEmulation:mobile,cancelAndRetest:process.env.TEST_CANCEL==='1',quotaChecks:process.env.TEST_QUOTAS==='1',pressure:pressureResult,cleanupResponses,unexpectedBackendRejections:unexpected,pingRequests,elapsedSeconds:(Date.now()-started)/1000,...data,status:'passed',path:process.env.TEST_PATH || 'network path unspecified; verify route independently'};
    result.transportThrottleKiB=throttleKiB;
    result.cancelPhase=process.env.TEST_CANCEL==='1' ? cancelPhase : null;
    if(process.env.BROWSER_RESULT) fs.writeFileSync(process.env.BROWSER_RESULT,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600});
    console.log(JSON.stringify(result));
  } catch(error) {
    await Promise.all(diagnosticReads);
    const failureMessage=String(error.message).split('\n')[0].replace(/https?:\/\/[^\s]+/g,'[URL]').replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g,'[ip]').slice(0,180);
    const result={status:shutdown.requested()?'stopped':error.admissionStatus===429?'quota_blocked':'failed',checkedAt:new Date().toISOString(),phase,failureKind:error.name,failureMessage,admissionStatus:error.admissionStatus||null,retryAfterSeconds:error.retryAfterSeconds||null,rejected,responseDiagnostics,pingRequests,browserErrors,state:await page.evaluate(()=>{
      const data=typeof uiData!=='undefined'&&uiData||{};
      return {state:typeof s!=='undefined'?s.getState():null,admitting:typeof admitting!=='undefined'?admitting:null,cancelled:typeof admissionCancelled!=='undefined'?admissionCancelled:null,
        downloadMbps:data.dlStatus===undefined?null:Number(data.dlStatus),uploadMbps:data.ulStatus===undefined?null:Number(data.ulStatus),pingMs:data.pingStatus===undefined?null:Number(data.pingStatus),jitterMs:data.jitterStatus===undefined?null:Number(data.jitterStatus)};
    }).catch(()=>null),path:process.env.TEST_PATH||'network path unspecified; measurement not accepted'};
    if(process.env.BROWSER_RESULT) fs.writeFileSync(process.env.BROWSER_RESULT,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600});
    console.error(JSON.stringify(result));
    throw error;
  } finally {
    // Do not retain a global lock after a test assertion fails mid-measurement.
    await shutdown.stop();
    shutdown.dispose();
  }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
