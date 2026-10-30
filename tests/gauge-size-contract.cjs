// Pure Canvas IDL-coercion mocks; no browser/network or upstream modifications.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../upstream/docker/standalone.php'),'utf8');
const start=source.indexOf('function drawMeter(');
const end=source.indexOf('function mbpsToAmount(',start);
assert(start>=0&&end>start);
const active=source.slice(start,end);
const lines=fs.readFileSync(path.join(__dirname,'../deploy/gauge-integer-pixels.patch'),'utf8').split('\n');
const removed=lines.filter(s=>s.startsWith('-')&&!s.startsWith('---')).map(s=>s.slice(1));
const added=lines.filter(s=>s.startsWith('+')&&!s.startsWith('+++')).map(s=>s.slice(1));
assert.equal(removed.length,1);
let original;
if(active.includes(removed[0])) original=active;
else{
  assert(active.includes(added.join('\n')),'Current function must match the accepted patch');
  original=active.replace(added.join('\n'),removed[0]);
}
const candidate=original.replace(removed[0],added.join('\n'));
assert.equal(active,candidate,'Actual shipped function must contain the accepted fix');
function fixture(code,dpr,cssWidth,cssHeight){
  let width=0,height=0,writes=0,clears=0;
  const context={clearRect(){clears++;},beginPath(){},arc(){},stroke(){},fillRect(){}};
  const canvas={clientWidth:cssWidth,clientHeight:cssHeight,getContext:()=>context};
  Object.defineProperties(canvas,{
    width:{get:()=>width,set:value=>{width=Math.trunc(value);writes++;}},
    height:{get:()=>height,set:value=>{height=Math.trunc(value);writes++;}},
  });
  const scope={window:{devicePixelRatio:dpr}};
  vm.runInNewContext(code,scope);
  const draw=()=>scope.drawMeter(canvas,.5,'#80808040','#6060AA',.5,'#80808040');
  return {canvas,scope,draw,reset:()=>{writes=0;clears=0;},stats:()=>({writes,clears,width,height})};
}
for(const [dpr,width,height] of [[1,280,219],[1.25,281,219],[1.5,303,237],[1.75,280,219],[2,304,238],[2.625,640,499],[0,280,219]]){
  const old=fixture(original,dpr,width,height);
  old.draw();old.reset();
  for(let frame=0;frame<30;frame++) old.draw();
  const fractional=![width*(dpr||1),height*(dpr||1)].every(Number.isInteger);
  assert.equal(old.stats().writes,fractional?60:0);
  const fixed=fixture(candidate,dpr,width,height);
  fixed.draw();fixed.reset();
  for(let frame=0;frame<30;frame++) fixed.draw();
  assert.equal(fixed.stats().writes,0);
  assert.equal(fixed.stats().clears,30);
  assert.equal(fixed.stats().width,Math.floor(width*(dpr||1)));
  assert.equal(fixed.stats().height,Math.floor(height*(dpr||1)));
  fixed.canvas.clientWidth+=3;fixed.canvas.clientHeight+=3;
  fixed.draw();assert.equal(fixed.stats().writes,2,'Real CSS resize must update bitmap');
  fixed.reset();fixed.draw();assert.equal(fixed.stats().writes,0);
}
console.log('PASS: 7 integer/fractional DPR/fallback cases; baseline resize thrash reproduced, candidate stable frames do not allocate, real resize still works; no upstream/network changes');
