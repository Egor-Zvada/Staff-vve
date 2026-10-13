const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium,origin,productionOrigin,privateDir,readAccess,productionAccessFile,launchOptions,safeError}=require('./browser-runtime.cjs');
const secrets=readAccess();
const id=99,output=path.join(privateDir,'wallet-qa');
const checks=[];
let browser;
(async()=>{
  fs.mkdirSync(output,{recursive:true});
  browser=await chromium.launch(launchOptions(origin));
  const context=await browser.newContext({viewport:{width:1536,height:1000}}),page=await context.newPage(),errors=[],requests=[],external=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('request',request=>{const url=request.url();requests.push({url,method:request.method()});if(/^https?:/.test(url)&&new URL(url).hostname!==new URL(origin).hostname)external.push(url)});
  async function local(route,payload){return page.evaluate(async({route,payload})=>{const response=await fetch(route,{method:payload===undefined?'GET':'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},...(payload===undefined?{}:{body:JSON.stringify(payload)})});return {status:response.status,data:await response.json()};},{route,payload});}
  const response=await page.goto(origin+'/');assert.equal(response.status(),200);assert.equal(await page.locator('#enrollment-dialog').getAttribute('open'),null);
  for(let count=0;count<5;count++)await page.locator('[data-enrollment-logo]').click();assert.equal(await page.locator('#scan-input').isDisabled(),true);
  await page.locator('#enrollment-login [name=employee_id]').fill('0');await page.locator('#enrollment-login [name=pin]').fill(secrets.enrollment_pin);await page.locator('#enrollment-login button[type=submit]').click();
  await page.locator('[data-enrollment-tab=wallet]').waitFor({state:'visible'});await page.locator('[data-enrollment-tab=wallet]').click();
  await page.locator('#enrollment-wallet-employee').selectOption(String(id));await page.locator('#enrollment-wallet-result').waitFor({state:'visible'});
  const before=await local('/api/enrollment/wallet/'+id);assert.equal(before.status,200);assert.equal(before.data.state,'ready','QA must seed the existing cached pass before this test; no external create is permitted');
  assert.equal(before.data.cached,true);assert.equal(await page.locator('#enrollment-wallet-qr').getAttribute('src'),'/api/enrollment/wallet/'+id+'/qr');
  const cached=await local('/api/enrollment/wallet/'+id,{});assert.equal(cached.status,200);assert.equal(cached.data.state,'ready');assert.equal(cached.data.cached,true);assert.equal(cached.data.share_url,before.data.share_url);assert.equal(cached.data.serial_number,before.data.serial_number);assert.equal(cached.data.quota.used,before.data.quota.used);
  assert.equal(requests.some(request=>request.url.includes('/api/mark')),false);
  checks.push('PIN enrollment displays cached local QR without attendance or external creation');
  await page.locator('#enrollment-dialog [data-close-dialog]').click();await page.waitForFunction(()=>!document.querySelector('#scan-input').disabled);assert.equal(await page.locator('#scan-input').isEnabled(),true);
  await page.goto(origin+'/login');await page.locator('form[action="/login"] [name=employee_id]').fill('0');await page.locator('form[action="/login"] [name=password]').fill(secrets.admin_password);await Promise.all([page.waitForURL('**/admin'),page.locator('form[action="/login"] button[type=submit]').click()]);
  const manage=await page.goto(origin+'/admin/wallet');assert.equal(manage.status(),200);await page.locator('#wallet-admin-employee').selectOption(String(id));await page.locator('#wallet-admin-result').waitFor({state:'visible'});assert.equal(await page.locator('#wallet-admin-qr').getAttribute('src'),'/admin/wallet/qr/'+id);assert.equal(await page.locator('#wallet-admin-issue').isDisabled(),true);assert.equal(await page.locator('#wallet-admin-revoke').isEnabled(),true);checks.push('Dedicated Wallet management exposes cached pass and guarded actions');
  await page.goto(origin+'/admin/print?ids='+id);await page.locator('#print-output').selectOption('duplex');await page.waitForFunction(()=>!document.querySelector('#download-pdf').disabled);
  const node=page.locator(`.badge-placement[data-id="${id}"][data-side=front]`).first();await node.click();
  async function set(selector,value){await page.locator(selector).fill(String(value));await page.locator(selector).dispatchEvent('change');}
  await set('#placement-x',17.25);await set('#placement-y',31.5);await set('[data-sheet=offset_x]',1.2);await set('[data-sheet=offset_y]',-0.8);
  async function geometry(){return page.evaluate(id=>{const scale=96/25.4*Number(document.querySelector('#print-zoom').value),front=document.querySelector(`.badge-placement[data-id="${id}"][data-side=front]`),back=document.querySelector(`.badge-placement[data-id="${id}"][data-side=back]`),box=n=>({x:parseFloat(n.style.left)/scale,y:parseFloat(n.style.top)/scale,w:parseFloat(n.style.width)/scale,h:parseFloat(n.style.height)/scale});return {front:box(front),back:box(back),w:Number(document.querySelector('[data-sheet=width_mm]').value),h:Number(document.querySelector('[data-sheet=height_mm]').value)};},id);}
  const close=(a,b)=>assert.ok(Math.abs(a-b)<1e-3,`Geometry mismatch ${a} vs ${b}`);
  await page.locator('#duplex-flip').selectOption('long');let g=await geometry();close(g.back.x,g.w-g.front.x-g.front.w);close(g.back.y,g.front.y);
  await page.locator('#duplex-flip').selectOption('short');g=await geometry();close(g.back.x,g.front.x);close(g.back.y,g.h-g.front.y-g.front.h);
  await page.locator('#duplex-flip').selectOption('long');checks.push('Duplex mirrors physical coordinates after calibration offsets for both flip modes');
  async function download(selector,file,signature){const wait=page.waitForEvent('download');await page.locator(selector).click();const item=await wait;assert.equal(await item.failure(),null);const target=path.join(output,file);await item.saveAs(target);const bytes=fs.readFileSync(target);assert.ok(bytes.length>100);assert.deepEqual(bytes.subarray(0,signature.length),Buffer.from(signature));return bytes;}
  const pdf=await download('#download-pdf','duplex.pdf','%PDF-');assert.ok(pdf.length>2000);checks.push('Duplex PDF downloaded from the local Staff server');
  const zip=await download('#download-png','duplex.zip',[0x50,0x4b,0x03,0x04]);assert.ok(zip.includes(Buffer.from('_front_')));assert.ok(zip.includes(Buffer.from('_wallet_')));checks.push('Both PNG sides are included in the local ZIP');
  await page.locator('#print-output').selectOption('wallet_back');await page.waitForFunction(()=>!document.querySelector('#download-png').disabled);await download('#download-png','wallet-back.png',[0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a]);checks.push('Single Wallet reverse PNG downloaded');
  await page.screenshot({path:path.join(output,'print-back.png'),fullPage:true});
  const clock=await local('/api/clock');const created=await local('/admin/employees/save',{surname:'QA',given_name:'Новыйкод',active:1,admin_role:'MODERATOR',password:'Staff-QA-VVE-Code-Only-2026',started_on:clock.data.date});assert.equal(created.status,200);const employee=created.data.employee,code=employee.pass_code||employee.display_id;assert.match(code,/^VVE-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/);
  await page.goto(origin+'/');await page.locator('#scan-input').fill(code);await page.waitForFunction(()=>document.querySelector('#result-title').textContent.startsWith('Приход')||document.querySelector('#result-title').textContent.startsWith('Уход'));assert.equal(await page.locator('#scan-input').inputValue(),'');
  const publicContext=await browser.newContext(),second=await publicContext.newPage();second.on('pageerror',error=>errors.push(error.message));second.on('request',request=>{if(/^https?:/.test(request.url())&&new URL(request.url()).hostname!==new URL(origin).hostname)external.push(request.url())});
  const manual=await second.goto(origin+'/manual?code='+encodeURIComponent(code));assert.equal(manual.status(),200);assert.equal(await second.locator('#manual-id').inputValue(),code);assert.equal(await second.locator('#manual-form').getAttribute('data-employee-code'),code);await second.locator('#manual-reload').click();await second.waitForFunction(()=>document.querySelector('#manual-status').textContent==='Изменений нет');
  const hiddenKey=await second.evaluate(async id=>(await fetch('/api/day?employee_id='+id)).status,employee.id);assert.ok(hiddenKey>=400,'An unsigned internal key must not resolve an alpha pass for a public visitor');
  const mark=second.waitForRequest(request=>request.url().endsWith('/api/mark'));await second.locator('[data-mark-now=arrival]').click();assert.equal((await mark).postDataJSON().code,code);await second.locator('.toast.success').waitFor({state:'visible'});
  await second.goto(origin+'/login');await second.locator('form[action="/login"] [name=employee_id]').fill(code);await second.locator('form[action="/login"] [name=password]').fill('Staff-QA-VVE-Code-Only-2026');await Promise.all([second.waitForURL('**/admin'),second.locator('form[action="/login"] button[type=submit]').click()]);await publicContext.close();
  checks.push('New VVE code: generated, no-Enter scan, manual lookup and administrative login; no Wallet issued');
  assert.deepEqual(errors,[]);assert.deepEqual(external,[]);
  const result={origin:'isolated Staff V2 QA listener',checks,passed:checks.length,browserErrors:errors,browserExternalRequests:external,providerCreates:'none; pre-seeded cached pass only'};fs.writeFileSync(path.join(output,'results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));await browser.close();
})().catch(async error=>{let message=error?.stack||String(error);for(const value of [secrets.admin_password,secrets.enrollment_pin])if(value)message=message.split(String(value)).join('[redacted]');console.error(message);await browser?.close();process.exitCode=1;});
