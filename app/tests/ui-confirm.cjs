const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium,origin,productionOrigin,privateDir,readAccess,productionAccessFile,launchOptions,safeError}=require('./browser-runtime.cjs');
let browser;
(async()=>{
  browser=await chromium.launch(launchOptions());const context=await browser.newContext(),page=await context.newPage(),calls=[];
  await page.route('https://staff-confirm.test/**',route=>{if(route.request().method()==='POST'){calls.push(route.request().url());return route.fulfill({status:200,contentType:'application/json',body:'{"success":true}'})}return route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><html><head><meta name="csrf-token" content="fixture"></head><body><form action="/admin/employees/rotate-passcode" data-ajax data-reload="false" data-confirm="Сменить код?"><input name="id" value="74"><input name="version" value="1"><button type="submit">Сменить код</button></form><div id="toast-stack"></div></body></html>'})});
  await page.goto('https://staff-confirm.test/');await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8')});
  page.once('dialog',dialog=>dialog.dismiss());await page.locator('button[type=submit]').click();await page.waitForTimeout(80);assert.equal(calls.length,0,'Cancel must prevent the AJAX mutation');
  let dialogs=0;page.on('dialog',dialog=>{dialogs++;dialog.accept()});await page.locator('button[type=submit]').click();await page.waitForFunction(()=>document.querySelector('.form-status')?.textContent==='Сохранено');assert.equal(calls.length,1);assert.equal(dialogs,1,'AJAX confirm must not be shown twice');
  console.log(JSON.stringify({passed:2,checks:['Cancelled credential rotation sends no request','Accepted rotation sends one request with one confirmation'],realMutation:false},null,2));await browser.close();
})().catch(async error=>{console.error(error);await browser?.close();process.exitCode=1;});
