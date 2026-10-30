// Bounded small-request pressure during an admitted real test. Never bypasses quotas.
const assert = require('node:assert/strict');
async function runPressure(browser,base) {
  const context=await browser.newContext({ignoreHTTPSErrors:process.env.TEST_PRIVATE_CA==='1'});
  const outcomes=[];
  const plan=[
    ...Array.from({length:24},()=>({path:'/backend/getIP.php',status:429})),
    ...Array.from({length:24},()=>({path:'/backend/garbage.php?ckSize=100',status:403})),
    ...Array.from({length:24},()=>({path:'/backend/empty.php',status:403,upload:true})),
    ...Array.from({length:64},()=>({path:'/backend/empty.php',status:200}))
  ];
  const started=Date.now();
  let next=0;
  try {
    await Promise.all(Array.from({length:8},async()=>{
      while(next<plan.length) {
        const item=plan[next++];
        const before=Date.now();
        const response=item.upload ? await context.request.post(base+item.path,{data:Buffer.alloc(1024),headers:{'Content-Type':'application/octet-stream'},timeout:10000}) : await context.request.get(base+item.path,{headers:{'X-Speedtest-Action':'start'},timeout:10000});
        const body=await response.body();
        assert.equal(response.status(),item.status,`unexpected protection response for ${item.path}`);
        assert(body.length<=4096,'Rejected payload must not generate a high-volume response');
        outcomes.push({kind:item.status===200?'ping':item.upload?'upload-rejected':item.status===429?'admission-rejected':'download-rejected',ms:Date.now()-before,status:response.status(),bytes:body.length});
        await response.dispose();
      }
    }));
    const pings=outcomes.filter(x=>x.kind==='ping').map(x=>x.ms).sort((a,b)=>a-b);
    return {status:'passed',requests:outcomes.length,concurrency:8,elapsedSeconds:(Date.now()-started)/1000,
      admission429:24,download403:24,upload403:24,ping200:64,
      pingMedianMs:pings[Math.floor(pings.length/2)],pingP95Ms:pings[Math.ceil(pings.length*0.95)-1],
      responseBytes:outcomes.reduce((sum,x)=>sum+x.bytes,0),uploadBytes:24*1024};
  } finally {await context.close();}
}
module.exports={runPressure};
