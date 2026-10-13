// Explicit operator-only gate. Creates/reuses one real Wallet pass and prints active staff.
// This is deliberately excluded from the normal test suite.
const fs=require('node:fs'),path=require('node:path');
const {chromium,productionOrigin,privateDir,readAccess,productionAccessFile,launchOptions,safeError}=require('./browser-runtime.cjs');
if(process.env.STAFF_PRODUCTION_ARTIFACTS!=='1')throw Error('Set STAFF_PRODUCTION_ARTIFACTS=1 only for an authorized live issuance');
const access=readAccess(productionAccessFile),id=Number(process.env.STAFF_WALLET_EMPLOYEE_ID);
if(!Number.isInteger(id)||id<1)throw Error('Choose an explicit STAFF_WALLET_EMPLOYEE_ID');
let browser;
(async()=>{
 browser=await chromium.launch(launchOptions(productionOrigin));const context=await browser.newContext({viewport:{width:1680,height:1050},acceptDownloads:true}),page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(productionOrigin+'/login');await page.locator('form[action="/login"] [name=employee_id]').fill(String(id).padStart(3,'0'));await page.locator('form[action="/login"] [name=password]').fill(access.password);await Promise.all([page.waitForURL('**/admin'),page.locator('form[action="/login"] button').click()]);
 await page.goto(productionOrigin+'/admin/wallet');
 const local=(route,data)=>page.evaluate(async({route,data})=>{const r=await fetch(route,{method:data===undefined?'GET':'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},...(data===undefined?{}:{body:JSON.stringify(data)})});return{status:r.status,data:await r.json()};},{route,data});
 const before=await local('/admin/wallet/usage');if(before.status!==200||before.data.quota.stale)throw Error('Live quota cannot be verified');
 const issued=await local('/admin/wallet/issue',{ids:[id]});const pass=issued.data.results?.[0];if(issued.status!==200||pass?.state!=='ready')throw Error('Wallet result was not confirmed; do not automatically repeat issuance');
 const reused=await local('/admin/wallet/issue',{ids:[id]});if(reused.data.results?.[0]?.serial_number!==pass.serial_number||!reused.data.results[0].cached)throw Error('Saved Wallet was not reused');
 const after=await local('/admin/wallet/usage');
 fs.writeFileSync(path.join(privateDir,'wallet-employee.json'),JSON.stringify(pass,null,2));
 const download=async(selector,file)=>{const pending=page.waitForEvent('download',{timeout:90000});await page.locator(selector).click();const d=await pending;if(await d.failure())throw Error('Artifact download failed');await d.saveAs(path.join(privateDir,file));return fs.statSync(path.join(privateDir,file)).size;};
 await page.locator('#wallet-admin-employee').selectOption(String(id));await page.locator('#wallet-admin-result').waitFor({state:'visible'});await download('#wallet-admin-pkpass','wallet-employee.pkpass');
 await page.goto(productionOrigin+'/admin/print');await page.locator('#print-mode').selectOption('all');await page.waitForFunction(()=>document.querySelectorAll('.badge-placement').length>0&&!document.querySelector('#download-pdf').disabled,{},{timeout:90000});
 const cards=await page.locator('.badge-placement').count(),started=Date.now();const bytes=await download('#download-pdf','production-active-badges.pdf');const ms=Date.now()-started;
 await page.goto(productionOrigin+'/admin/print?ids='+id);await page.waitForFunction(()=>!document.querySelector('#download-png').disabled);await download('#download-png','production-badge.png');
 await page.locator('#print-output').selectOption('duplex');await page.waitForFunction(()=>!document.querySelector('#download-pdf').disabled);await download('#download-pdf','production-badge-duplex.pdf');
 await page.screenshot({path:path.join(privateDir,'production-print.png'),fullPage:true});
 if(errors.length)throw Error(errors.join('; '));const report={employee_id:id,display_id:pass.display_id,wallet_ready:true,cached_reuse:true,quota_used_before:before.data.quota.used,quota_used_after:after.data.quota.used,mass_pdf:{cards,bytes,milliseconds:ms},browserErrors:errors,attendance_writes:0};fs.writeFileSync(path.join(privateDir,'production-artifacts-results.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));await browser.close();
})().catch(async e=>{console.error(safeError(e,access));await browser?.close();process.exitCode=1;});
