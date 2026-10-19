const assert=require('node:assert/strict');
const {classifyCleanupRejections}=require('./browser-cleanup.cjs');
const finish=[{event:'finish204',elapsedMs:1000}];
const late={endpoint:'/backend/empty.php',method:'POST',status:429,responseState:4,requestStartedMs:900,responseMs:1005,reason:''};
assert.equal(classifyCleanupRejections([late],finish).cleanupResponses.length,1);
for(const change of [{responseMs:999},{requestStartedMs:1001},{responseState:3},{status:403},{method:'GET'},{endpoint:'/backend/garbage.php'},{reason:'本次测速已达到单方向流量上限，请稍后重试'},{requestStartedMs:undefined}])
  assert.equal(classifyCleanupRejections([{...late,...change}],finish).unexpected.length,1);
assert.equal(classifyCleanupRejections([late],[]).unexpected.length,1);
assert.equal(classifyCleanupRejections([late],[{event:'finish204',elapsedMs:500},...finish]).cleanupResponses.length,1);
console.log('PASS: only owner-finished, previously started POST upload cleanup may be classified; active-phase/new-request/quota failures still fail');
