const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const script=fs.readFileSync(path.join(__dirname,'../public/assets/print.js'),'utf8');
const source=script.slice(script.indexOf('function backPosition('),script.indexOf('function printPages('));
const backPosition=vm.runInNewContext(source+';backPosition');
const portrait={sheet:{width_mm:210,height_mm:297,offset_x:1.5,offset_y:-2},template:{width_mm:53.98,height_mm:85.6},duplex_flip:'long'};
const p={id:7,page:0,x:10,y:20,rotation:0};
function close(actual,expected){assert.ok(Math.abs(actual-expected)<1e-9,`${actual} differs from ${expected}`)}
let result=backPosition(p,portrait);close(result.x,144.52);close(result.y,18);
result=backPosition(p,{...portrait,duplex_flip:'short'});close(result.x,11.5);close(result.y,193.4);
result=backPosition({...p,rotation:90},portrait);close(result.x,112.9);close(result.y,18);close(result.w,85.6);close(result.h,53.98);
const landscape={...portrait,sheet:{...portrait.sheet,width_mm:297,height_mm:210}};
result=backPosition(p,landscape);close(result.x,11.5);close(result.y,106.4);
result=backPosition(p,{...landscape,duplex_flip:'short'});close(result.x,231.52);close(result.y,18);
assert.deepEqual(p,{id:7,page:0,x:10,y:20,rotation:0});
console.log(JSON.stringify({passed:5,checks:['Portrait long edge','Portrait short edge','Rotated card dimensions','Landscape long edge','Landscape short edge'],physicalPrinterTest:false},null,2));
