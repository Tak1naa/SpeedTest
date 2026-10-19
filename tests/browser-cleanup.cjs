// A finished Worker aborts its outstanding uploads. Do not confuse protection
// responses received after successful owner cleanup with measurement failures.
// Any rejection during measurement, on new requests or on other paths fails.
function classifyCleanupRejections(rejected, diagnostics) {
  const finish=diagnostics.slice().reverse().find(event=>event.event==='finish204');
  const isCleanup=entry=>Boolean(finish &&
    entry.endpoint==='/backend/empty.php' && entry.method==='POST' &&
    entry.status===429 && entry.responseState===4 &&
    Number.isFinite(entry.requestStartedMs) && entry.requestStartedMs<finish.elapsedMs &&
    Number.isFinite(entry.responseMs) && entry.responseMs>=finish.elapsedMs &&
    (entry.reason==='' || entry.reason==='上传阶段已结束'));
  return {cleanupResponses:rejected.filter(isCleanup),unexpected:rejected.filter(entry=>!isCleanup(entry))};
}
module.exports={classifyCleanupRejections};
