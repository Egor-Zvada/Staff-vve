const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium,origin,productionOrigin,privateDir,readAccess,productionAccessFile,launchOptions,safeError}=require('./browser-runtime.cjs');
const script=fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8');
const html=`<!doctype html><html data-theme="light"><head><meta name="csrf-token" content="test"></head><body class="scanner-body">
<button data-event="AUTO" class="selected">Авто</button><button data-event="arrival">Приход</button><button data-event="departure">Уход</button>
<input id="scan-input"><button id="typed-mode">Ввести ID</button><div id="auto-description"></div>
<div id="scanner-online"><i></i> Готов</div><div id="scan-result"><div class="result-symbol"></div><div id="result-title"></div><div id="result-detail"></div></div>
<div id="pending-operations"></div><div id="toast-stack"></div>
<script type="application/json" id="scanner-config">{"timestamp":"2026-10-13T09:00:00+11:00","quiet_ms":100,"duplicate_seconds":3}</script></body></html>`;
let browser;
(async()=>{
  browser=await chromium.launch(launchOptions());
  const context=await browser.newContext();const page=await context.newPage();const requests=[],errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://staff-v2.test/**',async route=>{
    if(!route.request().url().endsWith('/api/mark'))return route.fulfill({status:200,contentType:'text/html',body:html});
    const payload=route.request().postDataJSON();requests.push(payload);
    await new Promise(resolve=>setTimeout(resolve,requests.length===1?600:30));
    return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,event_type:payload.event_type==='AUTO'?'arrival':payload.event_type,employee:{id:Number(payload.code),name:'Тест '+payload.code},time:'09:00:01',date:'2026-10-13',duplicate:false})});
  });
  await page.goto('https://staff-v2.test/');await page.addScriptTag({content:script});
  const input=page.locator('#scan-input');
  await input.fill('007');await page.waitForTimeout(150);assert.equal(await input.inputValue(),'');
  await input.fill('021');await page.waitForTimeout(150);assert.equal(await input.inputValue(),'');
  await page.waitForFunction(()=>document.querySelector('#result-detail').textContent.includes('021'));
  assert.deepEqual(requests.map(r=>r.code),['007','021']);assert.notEqual(requests[0].request_id,requests[1].request_id);
  await input.fill('022');await input.press('Enter');await page.waitForTimeout(170);
  assert.equal(requests.filter(r=>r.code==='022').length,1);
  await page.locator('#typed-mode').click();await input.pressSequentially('23',{delay:180});await page.waitForTimeout(180);
  assert.equal(requests.some(r=>r.code==='23'),false);await input.press('Enter');await page.waitForTimeout(100);
  assert.equal(requests.at(-1).code,'23');assert.equal(requests.at(-1).source,'keyboard');
  await page.locator('#typed-mode').click();await page.locator('[data-event="departure"]').click();
  await input.fill('024');await page.waitForTimeout(115);
  assert.equal(await page.locator('[data-event="AUTO"]').getAttribute('class'),'selected');
  await page.locator('[data-event="departure"]').click();await page.waitForTimeout(80);
  assert.equal(await page.locator('[data-event="departure"]').getAttribute('class'),'selected');
  await input.fill('vve-ab12-cd34-ef56');await page.waitForTimeout(170);
  assert.equal(requests.at(-1).code,'VVE-AB12-CD34-EF56');
  await input.fill('VVE-1234-5678-ABCD');await input.press('Enter');await page.waitForTimeout(170);
  assert.equal(requests.filter(r=>r.code==='VVE-1234-5678-ABCD').length,1);
  await input.fill('ММУ-ФИ12-СВ34-УА56');await page.waitForTimeout(180);
  assert.equal(requests.at(-1).code,'VVE-AB12-CD34-EF56');
  await input.fill('ТАСЖ04ФИ12СВ');await page.waitForTimeout(180);
  assert.equal(requests.at(-1).code,'NFC:04AB12CD');
  const count=requests.length;await input.fill('Марина');await page.waitForTimeout(180);assert.equal(requests.length,count);assert.equal(await page.locator('#result-title').textContent(),'Код не распознан');
  await page.evaluate(()=>Object.defineProperty(navigator,'onLine',{get:()=>false,configurable:true}));await input.fill('025');await page.waitForTimeout(180);assert.equal(requests.at(-1).code,'025');await page.waitForFunction(()=>document.querySelector('#result-detail').textContent.includes('025'));
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({passed:11,checks:['No Enter and immediate buffer reset','Two distinct queued codes behind slow response','Enter cancels quiet timer','Slow keyboard requires Enter','Response cannot reset next event choice','VVE scanner code without Enter and uppercase normalization','VVE code with Enter without a second request','Russian HID VVE prefix converts by QWERTY','Russian HID NFC prefix and colon convert by QWERTY','Ordinary Russian names are rejected','Working LAN accepts marks when navigator.onLine is false'],hardwareTest:false,browserErrors:errors},null,2));
  await browser.close();
})().catch(async error=>{console.error(error);await browser?.close();process.exitCode=1;});
