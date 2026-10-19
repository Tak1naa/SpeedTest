// Fast admission regressions without HTTP, Redis, cookies or quota consumption.
const assert=require('node:assert/strict');
const {startAndVerifyAdmission}=require('./browser-admission.cjs');
function fixture(status,source='expected') {
  let clicked=false,waited=false;
  const response={status:()=>status,headers:()=>({'retry-after':'60'}),json:async()=>({processedString:source})};
  return {page:{waitForResponse:async()=>response,locator:()=>({click:async()=>{clicked=true;}}),waitForFunction:async()=>{waited=true;}},state:()=>({clicked,waited})};
}
(async()=>{
  for(const status of [403,429,503]) {
    const f=fixture(status);
    await assert.rejects(startAndVerifyAdmission(f.page),error=>error.admissionStatus===status && error.retryAfterSeconds===60);
    assert.deepEqual(f.state(),{clicked:true,waited:false});
  }
  const wrong=fixture(200,'different');
  await assert.rejects(startAndVerifyAdmission(wrong.page,'expected'),/source differs/);
  assert.equal(wrong.state().waited,false);
  const ok=fixture(200);
  await startAndVerifyAdmission(ok.page,'expected');
  assert.equal(ok.state().waited,true);
  console.log('PASS: admission failures reported immediately, source mismatch rejected, valid admission proceeds; no real test or quota use');
})().catch(error=>{console.error(error);process.exitCode=1;});
