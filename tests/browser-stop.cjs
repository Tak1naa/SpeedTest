// Test-runner shutdown only. Abort the real Worker and finish our own cookie
// before closing Chromium; never manipulate Redis or another client's session.
function installBrowserStop({page,context,browser,base,signals=process}) {
  let requested=false;
  let pending;
  async function stop() {
    if(pending) return pending;
    requested=true;
    pending=(async()=>{
      const cookies=await context.cookies(base).catch(()=>[]);
      if(cookies.some(c=>c.name==='ST_SESSION')) {
        // A navigation failure with no cookie never owned a Worker/session;
        // avoid waiting for JavaScript in an uncommitted document during cleanup.
        await page.evaluate(()=>{if(s.getState()===3)s.abort();}).catch(()=>{});
        await context.request.post(base+'/backend/getIP.php',{
          headers:{'X-Speedtest-Action':'finish',Origin:base},timeout:3000
        }).catch(()=>{});
      }
      await browser.close().catch(()=>{});
    })();
    return pending;
  }
  const handler=()=>{void stop();};
  signals.once('SIGTERM',handler);signals.once('SIGINT',handler);
  return {requested:()=>requested,stop,
    dispose(){signals.removeListener('SIGTERM',handler);signals.removeListener('SIGINT',handler);}};
}
module.exports={installBrowserStop};
