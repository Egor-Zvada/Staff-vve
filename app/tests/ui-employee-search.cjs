'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium,launchOptions}=require('./browser-runtime.cjs');
const app=fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8');
const search=fs.readFileSync(path.join(__dirname,'../public/assets/employee-search.js'),'utf8');
const esc=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const person={id:7,pass_code:'007',surname:'Иванов',given_name:'Иван',name:'Иванов Иван',version:3,is_guard:0,admin_role:'NONE',active:1};
function fixture(url){
  const q=url.searchParams.get('q')||'',active=url.searchParams.get('active')||'1';
  const text=q==='Старый'?'Устаревший результат':q==='Петров'?'Петров Пётр':q==='Ошибка'?'Восстановленный результат':q==='Пусто'?'':q||'Начальный список';
  const next=new URL(url);next.searchParams.set('page','2');
  const rows=text?`<tr><td>007</td><td>${esc(text)}</td><td><button data-employee-edit="${esc(JSON.stringify(person))}">Изменить</button><form action="/admin/employees/rotate-passcode" method="post" data-ajax data-reload="false" data-confirm="Сменить код?"><input type="hidden" name="csrf" value="fixture"><input type="hidden" name="id" value="7"><input type="hidden" name="version" value="3"><button type="submit">Сменить код</button></form><form action="/admin/employees/deactivate" method="post" data-confirm="Уволить сотрудника?"><input type="hidden" name="id" value="7"><input type="hidden" name="active" value="0"><button type="submit">Уволить</button></form></td></tr>`:'<tr><td colspan="3" class="empty">Сотрудники не найдены</td></tr>';
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="fixture"></head><body><section id="employee-registry"><form id="employee-search-form" action="/admin/employees" method="get"><label>Поиск<input name="q" value="${esc(q)}" type="search"></label><label>Статус<select name="active"><option value="0" ${active==='0'?'selected':''}>Уволены</option><option value="1" ${active==='1'?'selected':''}>Работают</option><option value="all" ${active==='all'?'selected':''}>Все</option></select></label><button type="submit" data-search-submit>Найти</button><a href="/admin/employees" data-search-reset>Сбросить</a></form><div data-search-feedback hidden><span data-search-status></span><button type="button" data-search-retry hidden>Повторить поиск</button></div><div data-employee-results><table id="employees-table"><tbody>${rows}</tbody></table><footer><a href="${esc(next.pathname+next.search)}" data-test-next>Далее</a></footer></div></section><dialog id="employee-dialog"><h2 data-employee-title></h2><button data-close-dialog>Закрыть</button><form><input name="csrf" value="fixture"><input name="id"><input name="version"><input name="surname"><input name="given_name"><input name="password"><input name="enrollment_pin"><input name="is_guard" type="checkbox"><label data-new-employee-schedule><select name="group_id" data-new-employee-group><option value="1">Офис</option></select></label></form></dialog><div id="toast-stack"></div></body></html>`;
}
let browser;
(async()=>{
  browser=await chromium.launch(launchOptions());
  const context=await browser.newContext(),page=await context.newPage(),gets=[],posts=[],errors=[];
  let failOnce=true;
  await page.route('https://staff-search.test/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(request.method()==='POST'){
      posts.push({path:url.pathname,body:request.postData()});
      return route.fulfill({status:200,contentType:url.pathname.endsWith('deactivate')?'text/html':'application/json',body:url.pathname.endsWith('deactivate')?'<h1>Уволен</h1>':'{"success":true}'});
    }
    if(request.resourceType()==='fetch'){
      gets.push(url);
      if(url.searchParams.get('q')==='Старый')await new Promise(resolve=>setTimeout(resolve,600));
      if(url.searchParams.get('q')==='Ошибка'&&failOnce){failOnce=false;return route.fulfill({status:500,contentType:'text/html',body:'Ошибка сервера'})}
    }
    return route.fulfill({status:200,contentType:'text/html',body:fixture(url)});
  });
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('https://staff-search.test/admin/employees?active=0&page=3&page_size=2');
  await page.addScriptTag({content:app});await page.addScriptTag({content:search});
  const input=page.locator('#employee-search-form [name=q]'),body=page.locator('#employees-table tbody');
  assert.equal(await page.locator('[data-search-submit]').isVisible(),false);
  await input.fill('И');await page.waitForTimeout(50);await input.fill('Ив');await page.waitForTimeout(50);await input.fill('Иванов');
  await page.waitForFunction(()=>document.querySelector('#employees-table tbody').textContent.includes('Иванов'));
  assert.equal(gets.length,1,'A typing burst should produce one debounced request');
  assert.equal(gets[0].searchParams.get('q'),'Иванов');assert.equal(gets[0].searchParams.get('active'),'0');assert.equal(gets[0].searchParams.get('page'),'1');assert.equal(gets[0].searchParams.get('page_size'),'2');
  assert.equal(await input.evaluate(node=>document.activeElement===node),true,'Replacing results must retain input focus');
  assert.equal(new URL(page.url()).searchParams.get('q'),'Иванов');

  await page.evaluate(()=>{window.originalSearchFetch=window.fetch;window.searchAbortCount=0;window.fetch=(url,options={})=>{options.signal?.addEventListener('abort',()=>window.searchAbortCount++);const {signal,...rest}=options;return window.originalSearchFetch(url,rest)}});
  await input.fill('Старый');await page.waitForFunction(()=>document.querySelector('#employee-registry').getAttribute('aria-busy')==='true');
  await input.fill('Петров');await page.waitForFunction(()=>document.querySelector('#employees-table tbody').textContent.includes('Петров'));
  await page.waitForTimeout(650);
  assert.match(await body.innerText(),/Петров/);assert.doesNotMatch(await body.innerText(),/Устаревший/);assert.equal(new URL(page.url()).searchParams.get('q'),'Петров');assert.ok(await page.evaluate(()=>window.searchAbortCount)>0,'A new query must abort the previous request');
  await page.evaluate(()=>window.fetch=window.originalSearchFetch);

  await page.locator('#employee-search-form [name=active]').selectOption('all');
  await page.waitForFunction(()=>new URL(location.href).searchParams.get('active')==='all');
  await page.locator('[data-test-next]').click();await page.waitForFunction(()=>new URL(location.href).searchParams.get('page')==='2');
  assert.equal(new URL(page.url()).searchParams.get('q'),'Петров');assert.equal(new URL(page.url()).searchParams.get('active'),'all');

  await input.fill('Ошибка');await page.locator('[data-search-retry]').waitFor({state:'visible'});
  assert.match(await page.locator('[data-search-status]').innerText(),/Показан предыдущий результат/);assert.match(await body.innerText(),/Петров/);
  await page.locator('[data-search-retry]').click();await page.waitForFunction(()=>document.querySelector('#employees-table tbody').textContent.includes('Восстановленный'));
  await input.fill('Пусто');await page.waitForFunction(()=>document.querySelector('#employees-table tbody .empty'));
  assert.match(await page.locator('[data-search-status]').innerText(),/Сотрудники не найдены/);

  await input.fill('Иванов');await input.press('Enter');await page.waitForFunction(()=>document.querySelector('#employees-table tbody').textContent.includes('Иванов'));
  await page.locator('[data-employee-edit]').click();assert.equal(await page.locator('#employee-dialog').evaluate(node=>node.open),true);assert.equal(await page.locator('#employee-dialog [name=surname]').inputValue(),'Иванов');assert.equal(await page.locator('#employee-dialog [name=is_guard]').isChecked(),false);await page.locator('[data-close-dialog]').click();
  const rotate=page.locator('form[action$="rotate-passcode"] button');
  page.once('dialog',dialog=>dialog.dismiss());await rotate.click();await page.waitForTimeout(50);assert.equal(posts.length,0,'Cancelled dynamic AJAX must not send a mutation');
  let confirmations=0;page.once('dialog',dialog=>{confirmations++;dialog.accept()});await rotate.click();await page.waitForFunction(()=>document.querySelector('form[data-ajax] .form-status')?.textContent==='Сохранено');assert.equal(posts.length,1);assert.equal(confirmations,1);assert.match(posts[0].body,/name="id"\r\n\r\n7/);
  page.once('dialog',dialog=>dialog.dismiss());await page.locator('form[action$="deactivate"] button').click();await page.waitForTimeout(50);assert.equal(posts.length,1,'Cancelled dynamic ordinary form must not submit');
  page.once('dialog',dialog=>dialog.accept());await page.locator('form[action$="deactivate"] button').click();await page.waitForURL('**/admin/employees/deactivate');assert.equal(posts.length,2);assert.equal(posts[1].body,'id=7&active=0');

  const fallback=await browser.newContext({javaScriptEnabled:false}),plain=await fallback.newPage();
  await plain.route('https://staff-search.test/**',route=>route.fulfill({status:200,contentType:'text/html',body:fixture(new URL(route.request().url()))}));
  await plain.goto('https://staff-search.test/admin/employees?active=0');
  assert.equal(await plain.locator('[data-search-submit]').isVisible(),true);
  await plain.locator('[name=q]').fill('Иванов');await plain.locator('[name=q]').press('Enter');await plain.waitForURL(url=>url.searchParams.get('q')==='Иванов');assert.equal(new URL(plain.url()).searchParams.get('active'),'0');assert.match(await plain.locator('#employees-table tbody').innerText(),/Иванов/);
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({passed:10,checks:['One Cyrillic request per typing burst; active and page size retained, page reset','Focused search field survives result replacement','Abort and sequence reject late results even when transport ignores abort','Active change and dynamic pagination preserve query','Inline failure keeps previous rows and retry recovers','Empty search results render clearly','Enter submits live search immediately','Replaced edit buttons preserve guard form state','Replaced AJAX and ordinary forms confirm exactly once and respect cancel','JavaScript-disabled GET form and Enter work'],realMutation:false},null,2));
  await browser.close();
})().catch(async error=>{console.error(error);await browser?.close();process.exitCode=1});
