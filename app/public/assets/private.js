(() => {
  'use strict';
  const $=(s,p=document)=>p.querySelector(s),$$=(s,p=document)=>Array.from(p.querySelectorAll(s));
  let theme='light',leaveApproved=false,dirty=false;
  try{theme=localStorage.getItem('staff-theme')||'light'}catch{}
  function applyTheme(){document.documentElement.dataset.theme=theme==='system'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):theme}
  applyTheme();$('[data-private-theme]')?.addEventListener('click',()=>{theme=document.documentElement.dataset.theme==='dark'?'light':'dark';applyTheme();try{localStorage.setItem('staff-theme',theme)}catch{}});
  const tracked=$$('form[data-private-dirty]'),initial=new WeakMap();
  const snapshot=form=>JSON.stringify(Array.from(new FormData(form).entries()).filter(([key])=>!['csrf','request_id'].includes(key)));
  tracked.forEach(form=>initial.set(form,snapshot(form)));
  function updateDirty(){dirty=tracked.some(form=>snapshot(form)!==initial.get(form))}
  tracked.forEach(form=>{form.addEventListener('input',updateDirty);form.addEventListener('change',updateDirty)});
  window.addEventListener('beforeunload',event=>{if(dirty&&!leaveApproved){event.preventDefault();event.returnValue=''}});
  $$('[data-private-nav]').forEach(link=>link.addEventListener('click',event=>{if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button!==0)return;if(dirty&&!confirm('Есть несохранённые изменения. Перейти без сохранения?')){event.preventDefault();return}leaveApproved=true}));
  document.addEventListener('submit',event=>{if(event.defaultPrevented||event.target.id==='owner-week-form')return;if(dirty&&!event.target.matches('[data-private-dirty]')&&!confirm('Есть несохранённые изменения. Продолжить без сохранения?')){event.preventDefault();return}leaveApproved=true});

  const search=$('#private-employee-search'),select=$('#private-employee');
  if(search&&select){const options=Array.from(select.options).map(option=>({value:option.value,text:option.textContent}));search.addEventListener('input',()=>{const selected=select.value,q=search.value.trim().toLocaleLowerCase('ru');let count=0;select.replaceChildren();for(const item of options){const matches=item.value==='0'||item.text.toLocaleLowerCase('ru').includes(q);if(matches||item.value===selected){select.add(new Option(item.text,item.value,item.value===selected,item.value===selected));if(matches&&item.value!=='0')count++}}const status=$('[data-employee-search-status]');if(status)status.textContent=q?`Найдено: ${count}`:''})}

  const week=$('#owner-week-form');
  if(week){
    const rows=$$('[data-week-row]',week),status=$('#owner-week-status'),save=$('[data-week-save]',week),reload=$('[data-week-reload]',week),all=$('[data-week-select-all]',week);
    const available=rows.filter(row=>!$('[data-week-select]',row).disabled);
    let pending=null,blocked=false,sending=false;
    function say(text,error=false){status.textContent=text;status.className='form-status'+(error?' error':'')}
    function count(){const picked=available.filter(row=>$('[data-week-select]',row).checked);$('[data-week-selected-count]',week).textContent=picked.length?`Выбрано дней: ${picked.length}`:'Дни не выбраны';rows.forEach(row=>row.classList.toggle('is-selected',$('[data-week-select]',row).checked));all.checked=available.length>0&&picked.length===available.length;all.indeterminate=picked.length>0&&picked.length<available.length;return picked}
    function lock(value){$$('input,button',week).forEach(input=>{if(input===save||input===reload)return;if(value){if(input.dataset.privateWasDisabled===undefined)input.dataset.privateWasDisabled=input.disabled?'1':'0';input.disabled=true}else if(input.dataset.privateWasDisabled!==undefined){input.disabled=input.dataset.privateWasDisabled==='1';delete input.dataset.privateWasDisabled}})}
    function changed(row){if(pending||blocked)return;$('[data-week-select]',row).checked=true;count();say('Изменения не сохранены');updateDirty()}
    rows.forEach(row=>{$$('[data-week-time]',row).forEach(input=>input.addEventListener('input',()=>changed(row)));$('[data-week-clean]',row).addEventListener('change',()=>changed(row));$('[data-week-select]',row).addEventListener('change',()=>{count();say('Выбранные дни ещё не сохранены');updateDirty()})});
    all.addEventListener('change',()=>{available.forEach(row=>$('[data-week-select]',row).checked=all.checked);count();updateDirty()});
    $('[data-week-clear-selection]',week).addEventListener('click',()=>{available.forEach(row=>$('[data-week-select]',row).checked=false);count();updateDirty()});
    $('[data-week-preset]',week).addEventListener('click',()=>{const picked=count();if(!picked.length){say('Сначала выберите дни.');return}let applied=0;for(const row of picked){if(!row.dataset.planStart||!row.dataset.planEnd)continue;$('[data-week-time="arrival"]',row).value=row.dataset.planStart.slice(0,5);$('[data-week-time="departure"]',row).value=row.dataset.planEnd.slice(0,5);applied++}say(applied?`График подставлен для ${applied} дн. Сохраните изменения.`:'У выбранных дней нет полного графика.');updateDirty()});
    reload.addEventListener('click',()=>{if(dirty&&!confirm('Перечитать неделю? Несохранённый ввод будет заменён актуальными данными.'))return;leaveApproved=true;location.reload()});
    week.addEventListener('submit',async event=>{
      event.preventDefault();if(sending||blocked)return;
      if(!pending){
        const selected=count();if(!selected.length){say('Выберите хотя бы один день.',true);return}
        const data=Object.fromEntries(new FormData(week));pending={csrf:data.csrf,employee_id:data.employee_id,week_start:data.week_start,request_id:data.request_id,rows:{}};
        for(const row of selected){const date=row.dataset.weekRow,values={};$$('[data-week-time]',row).forEach(input=>values[input.dataset.weekTime]=input.value||null);pending.rows[date]={selected:'1',version:$('input[name$="[version]"]',row).value,values,clean:$('[data-week-clean]',row).checked?'1':'0'}}
      }
      sending=true;lock(true);save.disabled=true;reload.hidden=true;say('Сохраняем выбранные дни…');
      const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
      try{
        const response=await fetch(week.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':pending.csrf},body:JSON.stringify(pending),signal:controller.signal});
        let result;try{result=await response.json()}catch{throw Object.assign(new Error('Сервер не вернул подтверждение.'),{uncertain:true})}
        if(!response.ok||result.success===false){throw Object.assign(new Error(result.error||result.message||'Не удалось сохранить неделю.'),{status:response.status,uncertain:response.status>=500})}
        pending=null;dirty=false;leaveApproved=true;say('Выбранные дни сохранены.');
        location.assign(result.redirect||location.href);
      }catch(error){
        if(error.status===409){pending=null;blocked=true;lock(false);save.disabled=true;reload.hidden=false;say(error.message+' Ввод оставлен на экране. Перечитайте неделю перед повтором.',true)}
        else if(error.uncertain||!error.status){reload.hidden=false;say('Подтверждение не получено. Ввод сохранён на экране; повтор проверит ту же операцию.',true);save.textContent='Проверить / повторить'}
        else{pending=null;lock(false);say(error.message,true)}
      }finally{clearTimeout(timer);sending=false;if(!blocked)save.disabled=false}
    });count();
  }

  const ruleForm=$('#owner-automation-form');
  if(ruleForm){
    const fields=['id','version','name','employee_id','event_type','trigger_time','mark_time','trigger_day_offset','valid_from','valid_to'];
    function syncRandomMinutes(){const box=$('input[type=checkbox][name=random_minutes]',ruleForm);if(!box)return;const supported=['arrival','departure'].includes(ruleForm.elements.namedItem('event_type').value);box.disabled=!supported;if(!supported)box.checked=false;const group=$('[data-rule-random]',ruleForm);if(group)group.hidden=!supported}
    ruleForm.elements.namedItem('event_type').addEventListener('change',()=>{syncRandomMinutes();updateDirty()});syncRandomMinutes();
    function resetRule(){if(snapshot(ruleForm)!==initial.get(ruleForm)&&!confirm('Сбросить несохранённые изменения правила?'))return;ruleForm.reset();ruleForm.elements.namedItem('id').value='';ruleForm.elements.namedItem('version').value='';syncRandomMinutes();$('[data-rule-editor-caption]').textContent='Новое правило';initial.set(ruleForm,snapshot(ruleForm));updateDirty();ruleForm.scrollIntoView({behavior:'smooth',block:'start'});ruleForm.elements.namedItem('name').focus({preventScroll:true})}
    $('[data-rule-new]')?.addEventListener('click',resetRule);$('[data-rule-cancel]')?.addEventListener('click',resetRule);
    $$('[data-rule-edit]').forEach(button=>button.addEventListener('click',()=>{
      if(snapshot(ruleForm)!==initial.get(ruleForm)&&!confirm('Открыть другое правило без сохранения текущего ввода?'))return;
      let rule;try{rule=JSON.parse(button.dataset.ruleEdit)}catch{return}
      ruleForm.reset();for(const field of fields){const input=ruleForm.elements.namedItem(field);if(input)input.value=rule[field]??''}
      $$('[name="weekdays[]"]',ruleForm).forEach(input=>input.checked=(rule.weekdays||[]).map(Number).includes(Number(input.value)));
      for(const field of ['enabled','working_days_only','random_minutes']){const input=$(`input[type=checkbox][name="${field}"]`,ruleForm);if(input)input.checked=Boolean(Number(rule[field]))}syncRandomMinutes();
      $('[data-rule-editor-caption]').textContent='Изменение правила';initial.set(ruleForm,snapshot(ruleForm));updateDirty();ruleForm.scrollIntoView({behavior:'smooth',block:'start'});ruleForm.elements.namedItem('name').focus({preventScroll:true});
    }));
    ruleForm.addEventListener('submit',event=>{if(!$$('[name="weekdays[]"]:checked',ruleForm).length){event.preventDefault();leaveApproved=false;alert('Выберите хотя бы один день недели.');return}const offset=ruleForm.elements.namedItem('trigger_day_offset').value,mark=ruleForm.elements.namedItem('mark_time').value,trigger=ruleForm.elements.namedItem('trigger_time').value,random=$('input[type=checkbox][name=random_minutes]',ruleForm);if(random?.checked&&mark>'23:54'){event.preventDefault();leaveApproved=false;alert('Для случайных минут задайте базовое время не позже 23:54, чтобы отметка осталась в том же дне.');return}if(offset==='0'&&mark>trigger){event.preventDefault();leaveApproved=false;alert('Время отметки не может быть позже времени срабатывания в тот же день.')}});
  }
})();
