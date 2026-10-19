// Inert adapters: no browser, requests, cookies or quota are created.
const assert=require('node:assert/strict');
const {EventEmitter}=require('node:events');
const {installBrowserStop}=require('./browser-stop.cjs');
(async()=>{
  for(const hasCookie of [false,true]) {
    const signals=new EventEmitter();let aborted=0,finished=0,closed=0;
    const stop=installBrowserStop({signals,base:'https://test.invalid',
      page:{evaluate:async()=>{aborted++;}},
      context:{cookies:async()=>hasCookie?[{name:'ST_SESSION'}]:[],request:{post:async(url,opts)=>{
        assert.equal(url,'https://test.invalid/backend/getIP.php');
        assert.equal(opts.headers.Origin,'https://test.invalid');assert.equal(opts.headers['X-Speedtest-Action'],'finish');
        assert.equal(opts.timeout,3000);finished++;
      }}},browser:{close:async()=>{closed++;}}});
    signals.emit('SIGTERM');await stop.stop();await stop.stop();
    assert(stop.requested());assert.equal(aborted,hasCookie?1:0);assert.equal(finished,hasCookie?1:0);assert.equal(closed,1);
    stop.dispose();assert.equal(signals.listenerCount('SIGTERM'),0);assert.equal(signals.listenerCount('SIGINT'),0);
  }
  const signals=new EventEmitter();let closed=false;
  const stop=installBrowserStop({signals,base:'https://test.invalid',page:{evaluate:async()=>{throw Error('closed')}},
    context:{cookies:async()=>{throw Error('closed')}},browser:{close:async()=>{closed=true;}}});
  await stop.stop();stop.dispose();assert(closed);
  const source=require('node:fs').readFileSync(require('node:path').join(__dirname,'browser-smoke.cjs'),'utf8');
  assert(source.includes('handleSIGTERM:false,handleSIGINT:false'));
  console.log('PASS: owned Worker abort, same-origin cookie finish, bounded request, once-only closure and closed-context handling; inert adapters only');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
