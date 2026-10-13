// Read-only acceptance against Staff2 production; never creates attendance facts.
const fs=require('node:fs'),path=require('node:path');
const {chromium,origin,productionOrigin,privateDir,readAccess,productionAccessFile,launchOptions,safeError}=require('./browser-runtime.cjs');
const access=readAccess(productionAccessFile);
let browser;
(async()=>{
 browser=await chromium.launch(launchOptions(productionOrigin));
 const results=[];
 for(const id of access.ids){const context=await browser.newContext({viewport:{width:1440,height:950}}),page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(productionOrigin+'/login');await page.locator('form[action="/login"] [name=employee_id]').fill(String(id).padStart(3,'0'));await page.locator('form[action="/login"] [name=password]').fill(access.password);await Promise.all([page.waitForURL('**/admin'),page.locator('form[action="/login"] button').click()]);
  for(const route of ['/admin/employees','/admin/attendance','/admin/schedules','/admin/credentials','/admin/print','/admin/wallet','/admin/status']){const r=await page.goto(productionOrigin+route);if(r.status()!==200)throw Error('Production route failed '+route);}
  const enrollment=await page.evaluate(async({id,pin})=>{const r=await fetch('/api/enrollment/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({employee_id:String(id),pin})});return{status:r.status,data:await r.json()};},{id,pin:access.enrollment_pin});if(enrollment.status!==200)throw Error('Personal PIN enrollment failed');
  if(errors.length)throw Error(errors.join('; '));results.push({administrator_id:String(id).padStart(3,'0'),login:true,enrollment_pin:true,pages:7,browserErrors:[]});
  if(id===7){await page.goto(productionOrigin+'/admin');await page.screenshot({path:path.join(privateDir,'production-admin.png'),fullPage:true});}
  await context.close();
 }
 fs.writeFileSync(path.join(privateDir,'production-results.json'),JSON.stringify(results,null,2));console.log(JSON.stringify({read_only:true,results},null,2));await browser.close();
})().catch(async e=>{console.error(safeError(e,access));await browser?.close();process.exitCode=1;});
