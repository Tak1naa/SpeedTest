// Observe the existing browser admission, never send an extra session request.
async function startAndVerifyAdmission(page, expectedClientIp) {
  const responsePromise=page.waitForResponse(response=>response.url().includes('/backend/getIP.php') && response.request().method()==='GET',{timeout:15000});
  responsePromise.catch(()=>{}); // Browser close/click failure must not leak rejection.
  await page.locator('#startStopBtn').click();
  const response=await responsePromise;
  if(response.status()!==200) {
    const error=new Error(`Session admission rejected: HTTP ${response.status()}`);
    error.admissionStatus=response.status();
    error.retryAfterSeconds=Number(response.headers()['retry-after'])||null;
    throw error;
  }
  if(expectedClientIp) {
    const body=await response.json();
    if(!String(body.processedString||'').includes(expectedClientIp)) throw new Error('Browser source differs from intended client; measurement not accepted');
  }
  await page.waitForFunction(()=>s.getState()===3,null,{timeout:15000});
}
module.exports={startAndVerifyAdmission};
