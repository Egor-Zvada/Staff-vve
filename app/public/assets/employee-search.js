(() => {
  'use strict';
  const registry=document.querySelector('#employee-registry');
  const form=registry?.querySelector('#employee-search-form');
  const results=registry?.querySelector('[data-employee-results]');
  const query=form?.elements.namedItem('q');
  const active=form?.elements.namedItem('active');
  if(!form||!results||!query||!active||!window.fetch||!window.AbortController)return;
  const feedback=registry.querySelector('[data-search-feedback]');
  const status=registry.querySelector('[data-search-status]');
  const retry=registry.querySelector('[data-search-retry]');
  let sequence=0,timer=0,controller=null,composing=false,lastUrl=null;

  function message(text,error=false){
    feedback.hidden=!text;
    status.textContent=text;
    status.className='form-status'+(error?' error':'');
    retry.hidden=!error;
  }
  function invalidate(){
    ++sequence;
    clearTimeout(timer);
    controller?.abort();
    controller=null;
    registry.removeAttribute('aria-busy');
    return sequence;
  }
  function filterUrl(){
    const url=new URL(location.href);
    url.pathname=new URL(form.action,location.href).pathname;
    url.hash='';
    for(const [key,value] of new FormData(form)){
      if(String(value).trim()!=='')url.searchParams.set(key,String(value).trim());
      else url.searchParams.delete(key);
    }
    if(url.searchParams.has('page'))url.searchParams.set('page','1');
    url.searchParams.delete('offset');
    return url;
  }
  async function load(url,token){
    if(token!==sequence)return;
    controller=new AbortController();
    const current=controller;
    let timedOut=false;
    const deadline=setTimeout(()=>{timedOut=true;current.abort()},15000);
    lastUrl=url;
    registry.setAttribute('aria-busy','true');
    message('Ищем сотрудников…');
    try{
      const response=await fetch(url.href,{credentials:'same-origin',headers:{Accept:'text/html'},signal:current.signal});
      if(!response.ok)throw new Error('Сервер не выполнил поиск.');
      const html=await response.text();
      if(token!==sequence)return;
      const page=new DOMParser().parseFromString(html,'text/html');
      const updated=page.querySelector('#employee-registry [data-employee-results]');
      if(!updated)throw new Error(response.redirected?'Сессия закончилась. Обновите страницу и войдите снова.':'Сервер не вернул список сотрудников.');
      results.replaceChildren(...updated.childNodes);
      history.replaceState(history.state,'',url.pathname+url.search);
      const rows=[...results.querySelectorAll('#employees-table tbody tr')];
      const count=rows.filter(row=>!row.querySelector('td.empty')).length;
      message(count?'Показано сотрудников: '+count:'Сотрудники не найдены');
    }catch(error){
      if(token!==sequence)return;
      const detail=timedOut?'Сервер не ответил вовремя.':error.name==='TypeError'?'Нет связи с сервером.':error.message;
      message('Поиск не обновлён. '+detail+' Показан предыдущий результат.',true);
    }finally{
      clearTimeout(deadline);
      if(token===sequence){registry.removeAttribute('aria-busy');controller=null}
    }
  }
  function schedule(delay=200){
    const token=invalidate();
    message('Ищем сотрудников…');
    timer=setTimeout(()=>load(filterUrl(),token),delay);
  }
  query.addEventListener('compositionstart',()=>{composing=true;invalidate()});
  query.addEventListener('compositionend',()=>{composing=false;schedule()});
  query.addEventListener('input',event=>{if(!composing&&!event.isComposing)schedule()});
  active.addEventListener('change',()=>schedule(0));
  form.addEventListener('submit',event=>{event.preventDefault();composing=false;schedule(0)});
  registry.querySelector('[data-search-submit]')?.setAttribute('hidden','');
  retry.addEventListener('click',()=>{const token=invalidate();load(lastUrl||filterUrl(),token)});
  registry.querySelector('[data-search-reset]')?.addEventListener('click',event=>{
    if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    event.preventDefault();query.value='';active.value='1';
    const token=invalidate();load(new URL(form.action,location.href),token);
  });
  results.addEventListener('click',event=>{
    const link=event.target instanceof Element?event.target.closest('a[href]'):null;
    if(!link||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||link.target)return;
    const url=new URL(link.href,location.href);
    if(url.origin!==location.origin||url.pathname!==new URL(form.action,location.href).pathname)return;
    event.preventDefault();
    const target=filterUrl();
    target.searchParams.set('page',url.searchParams.get('page')||'1');
    const token=invalidate();load(target,token);
  });
  window.addEventListener('popstate',()=>{
    const url=new URL(location.href);
    query.value=url.searchParams.get('q')||'';
    active.value=url.searchParams.get('active')||'1';
    const token=invalidate();load(url,token);
  });
})();
