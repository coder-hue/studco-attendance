(() => {
  const launcherClass = 'stuco-grade-import';
  const attendanceOrigin = 'https://attendance.coderhue.dev';
  const debugKey = 'stucoAttendanceDebug';
  const pendingKey = 'stucoPendingAssignmentAttendance';
  function debug(level, message, details) {
    const entry={at:new Date().toISOString(),message,details};
    console[level](`[StuCo Attendance] ${message}`,details ?? '');
    try { const entries=JSON.parse(sessionStorage.getItem(debugKey) || '[]'); entries.push(entry); sessionStorage.setItem(debugKey,JSON.stringify(entries.slice(-20))); } catch {}
  }
  try { const previous=JSON.parse(sessionStorage.getItem(debugKey) || '[]'); if (previous.length) console.info('[StuCo Attendance] Previous run (survived refresh)',previous); } catch {}
  let activeAssignmentId = null;
  let activeAssignmentName = null;
  let pendingFinishTimer = null;
  let pendingFinishActive = false;

  function attendanceFetch(path) {
    return new Promise((resolve, reject) => chrome.runtime.sendMessage({type: 'stuco-attendance-fetch', path}, response => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!response?.ok) return reject(new Error(response?.status === 401 ? 'Sign in to the attendance admin site first, then try again.' : response?.error || 'Could not load attendance.'));
      try { resolve(JSON.parse(response.body)); } catch { reject(new Error('The attendance site returned an invalid response.')); }
    }));
  }

  async function createAttendanceEvent(eventDate, schoologyAssignmentId, title) {
    debug('info','Creating attendance event', {eventDate, schoologyAssignmentId, title, attendanceOrigin});
    try {
      const tokenResponse=await fetch(`${attendanceOrigin}/extension-create-event.php`,{credentials:'include'});
      const tokenBody=await tokenResponse.json().catch(()=>null);
      debug('info','Attendance token response', {status:tokenResponse.status, ok:tokenResponse.ok, body:tokenBody && {...tokenBody, csrf:tokenBody.csrf ? '[received]' : undefined}});
      if (!tokenResponse.ok || !tokenBody?.csrf) throw new Error(tokenBody?.error || 'Sign in to the attendance admin site first, then try again.');
      const response=await fetch(`${attendanceOrigin}/extension-create-event.php`,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':tokenBody.csrf},body:JSON.stringify({event_date:eventDate,schoology_assignment_id:schoologyAssignmentId,title})});
      const body=await response.json().catch(()=>null);
      debug('info','Attendance create response', {status:response.status, ok:response.ok, body});
      if (!response.ok || !body?.ok) throw new Error(body?.error || `The attendance site returned ${response.status}.`);
      return body;
    } catch (error) {
      debug('error','Attendance event creation failed', {message:error?.message || String(error)});
      throw error;
    }
  }

  async function linkAttendanceEvent(eventId, schoologyAssignmentId) {
    debug('info','Linking attendance event', {eventId, schoologyAssignmentId});
    const tokenResponse=await fetch(`${attendanceOrigin}/extension-create-event.php`,{credentials:'include'});
    const tokenBody=await tokenResponse.json().catch(()=>null);
    if (!tokenResponse.ok || !tokenBody?.csrf) throw new Error(tokenBody?.error || 'Sign in to the attendance admin site first, then try again.');
    const response=await fetch(`${attendanceOrigin}/extension-create-event.php`,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':tokenBody.csrf},body:JSON.stringify({event_id:eventId,schoology_assignment_id:schoologyAssignmentId})});
    const body=await response.json().catch(()=>null);
    debug('info','Attendance link response', {status:response.status,ok:response.ok,body});
    if (!response.ok || !body?.ok) throw new Error(body?.error || 'The attendance event could not be linked.');
    return body;
  }

  function isVisible(element) { const style=window.getComputedStyle(element); return style.display!=='none' && style.visibility!=='hidden' && element.getClientRects().length>0; }
  function escapeHtml(value) { const element=document.createElement('div'); element.textContent=value ?? ''; return element.innerHTML; }
  function csvCell(value) { const text=String(value ?? ''); return /[",\n]/.test(text) ? `"${text.replace(/"/g,'""')}"` : text; }

  function addMenuItem() {
    const editLink=[...document.querySelectorAll('a, button')].find(item => isVisible(item) && item.textContent.trim()==='Edit' && /Track Revisions|Set All Grades|View Statistics/.test(item.parentElement?.parentElement?.textContent || ''));
    if (!editLink) return;
    const sourceItem=editLink.closest('li') || editLink;
    const menu=sourceItem.parentElement;
    if (!menu || menu.querySelector(`.${launcherClass}`)) return;
    const item=sourceItem.cloneNode(true); item.classList.add(launcherClass);
    const itemLink=item.matches('a, button') ? item : item.querySelector('a, button');
    if (!itemLink) return;
    itemLink.textContent='Import attendance'; itemLink.removeAttribute('href'); itemLink.removeAttribute('onclick');
    itemLink.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); chooseAttendanceEvent(); });
    sourceItem.parentElement.insertBefore(item, sourceItem.nextSibling);
  }
  function watchMenus() {
    document.querySelectorAll('.action-links-unfold-text').forEach(anchor => {
      const control=anchor.closest('button, a') || anchor;
      if (control.dataset.stucoMenuWatcher) return;
      control.dataset.stucoMenuWatcher='true'; control.addEventListener('click', () => {
        const header=anchor.closest('[role="columnheader"]');
        activeAssignmentName=header?.getAttribute('aria-label') || null;
        const href=header?.querySelector('a[href*="/assignment/"],a[href*="/assessments/"]')?.getAttribute('href') || '';
        const ids=href.match(/\d+/g); activeAssignmentId=ids?.[ids.length-1] || null;
        window.setTimeout(addMenuItem,50);
      });
    });
    addMenuItem();
  }
  function refreshSchoologyUI() {
    watchMenus();
    installAssignmentAttendanceHelper();
    schedulePendingAttendanceFinish();
  }
  refreshSchoologyUI(); new MutationObserver(refreshSchoologyUI).observe(document.documentElement,{childList:true,subtree:true});

  function schoologyDate(date) { const [year,month,day]=date.split('-'); return `${Number(month)}/${Number(day)}/${year.slice(-2)}`; }
  function attendanceTitle(date) { return `Attendance (${schoologyDate(date)})`; }

  function normalizeText(value) { return String(value || '').replace(/\s+/g,' ').trim(); }
  function fieldDescription(input) { const labels=input.labels?[...input.labels].map(label=>label.textContent):[];return [input.name,input.id,input.getAttribute('aria-label'),input.placeholder,...labels].filter(Boolean).join(' '); }
  function validIsoDate(year,month,day) {
    const date=new Date(Date.UTC(year,month-1,day));
    return date.getUTCFullYear()===year && date.getUTCMonth()===month-1 && date.getUTCDate()===day;
  }
  function parseSchoologyDate(value) {
    const text=normalizeText(value);
    let match=text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if(match){const year=Number(match[1]),month=Number(match[2]),day=Number(match[3]);return validIsoDate(year,month,day)?`${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`:null;}
    match=text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/);
    if(!match)return null;
    const month=Number(match[1]),day=Number(match[2]),year=match[3].length===2?2000+Number(match[3]):Number(match[3]);
    return validIsoDate(year,month,day)?`${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`:null;
  }
  function assignmentTitleInput(form) {
    return form.querySelector('#edit-title,input[name="title"],input[name$="[title]"]') || [...form.querySelectorAll('input[type="text"]')].find(input=>/assignment.*(name|title)|(name|title).*assignment/i.test(fieldDescription(input)));
  }
  function assignmentDueDateInput(form) {
    const inputs=[...form.querySelectorAll('input')].filter(input=>/due.*date|date.*due/i.test(fieldDescription(input)));
    return inputs.find(isVisible) || inputs[0] || null;
  }
  function assignmentPointsInput(form) {
    const inputs=[...form.querySelectorAll('input')].filter(input=>input.type!=='hidden' && /(^|\s)(max\s*)?(points?|pts)(\s|$)/i.test(fieldDescription(input)) && !/factor/i.test(fieldDescription(input)));
    return inputs.find(isVisible) || inputs[0] || null;
  }
  function assignmentFactorInput(form) {
    const inputs=[...form.querySelectorAll('input')].filter(input=>input.type!=='hidden' && /factor/i.test(fieldDescription(input)));
    return inputs.find(isVisible) || inputs[0] || null;
  }
  function setSchoologyField(input,value) {
    if(!input || String(input.value)===String(value))return;
    const setter=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input),'value')?.set;
    if(setter)setter.call(input,value);else input.value=value;
    input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function trackSchoologyField(input) {
    if(!input || input.dataset.stucoTracked)return;
    input.dataset.stucoTracked='true';input.dataset.stucoInitialValue=input.value;
    input.addEventListener('input',event=>{if(event.isTrusted)input.dataset.stucoTouched='true';});
    input.addEventListener('change',event=>{if(event.isTrusted)input.dataset.stucoTouched='true';});
  }
  function autofillAssignmentDefaults(form) {
    const titleInput=assignmentTitleInput(form),dueInput=assignmentDueDateInput(form),pointsInput=assignmentPointsInput(form),factorInput=assignmentFactorInput(form);
    [titleInput,dueInput,pointsInput,factorInput].forEach(trackSchoologyField);
    const eventDate=parseSchoologyDate(dueInput?.value);
    if(titleInput && !normalizeText(titleInput.value) && eventDate && titleInput.dataset.stucoTouched!=='true')setSchoologyField(titleInput,attendanceTitle(eventDate));
    if(pointsInput && pointsInput.dataset.stucoTouched!=='true' && (!normalizeText(pointsInput.value) || pointsInput.value==='100'))setSchoologyField(pointsInput,'2');
    if(factorInput && factorInput.dataset.stucoTouched!=='true' && !normalizeText(factorInput.value))setSchoologyField(factorInput,'1.00');
    return eventDate;
  }
  function nativeAssignmentCreateButton(form) {
    return [...form.querySelectorAll('button[type="submit"],input[type="submit"]')].find(button=>!button.classList.contains('stuco-create-attendance') && /^(create|create assignment|save)$/i.test(normalizeText(button.textContent || button.value)) && isVisible(button)) || null;
  }
  function assignmentIdFromUrl(value) {
    let path;try{path=new URL(value,location.origin).pathname;}catch{return null;}
    const patterns=[/\/assignment\/edit\/\d+\/(\d+)(?:\/|$)/,/\/assignment\/(\d+)(?:\/|$)/,/\/assessments\/(\d+)(?:\/|$)/];
    for(const pattern of patterns){const match=path.match(pattern);if(match)return match[1];}
    return null;
  }
  function accessibleDocuments() {
    const documents=[document];
    try{if(window.top.document!==document)documents.push(window.top.document);}catch{}
    return documents;
  }
  function assignmentRecords() {
    const records=[];
    accessibleDocuments().forEach(doc=>doc.querySelectorAll('a[href]').forEach(link=>{const id=assignmentIdFromUrl(link.href);if(id)records.push({id,title:normalizeText(link.textContent),href:link.href});}));
    return records;
  }
  function currentCourseId() { return location.pathname.match(/\/course\/(\d+)/)?.[1] || null; }
  function resolveCreatedAssignmentId(pending) {
    const fromPage=assignmentIdFromUrl(location.href);if(fromPage && !pending.beforeIds.includes(fromPage))return fromPage;
    const records=assignmentRecords();
    const exact=records.filter(record=>record.title===normalizeText(pending.title) && !pending.beforeIds.includes(record.id));
    if(exact.length)return exact.sort((a,b)=>Number(b.id)-Number(a.id))[0].id;
    const newRecords=records.filter(record=>!pending.beforeIds.includes(record.id));
    if(newRecords.length===1)return newRecords[0].id;
    return null;
  }
  function readPendingAttendance() {
    try{const pending=JSON.parse(sessionStorage.getItem(pendingKey) || 'null');return pending && pending.eventId ? pending : null;}catch{return null;}
  }
  function savePendingAttendance(pending) { sessionStorage.setItem(pendingKey,JSON.stringify(pending)); }
  function showStuCoNotice(message,type='success') {
    let target=document;try{target=window.top.document;}catch{}
    target.querySelector('.stuco-assignment-notice')?.remove();
    const notice=target.createElement('div');notice.className=`stuco-assignment-notice is-${type}`;notice.textContent=message;target.body.appendChild(notice);
    window.setTimeout(()=>notice.remove(),8000);
  }
  function schedulePendingAttendanceFinish() {
    if(window.top!==window || pendingFinishTimer || pendingFinishActive)return;
    const pending=readPendingAttendance();if(!pending || (pending.lastAttempt && Date.now()-pending.lastAttempt<4000))return;
    pendingFinishTimer=window.setTimeout(()=>{pendingFinishTimer=null;finishPendingAttendance();},350);
  }
  async function finishPendingAttendance() {
    if(window.top!==window || pendingFinishActive)return;
    const pending=readPendingAttendance();if(!pending)return;
    if(Date.now()-Number(pending.createdAt || 0)>30*60*1000){sessionStorage.removeItem(pendingKey);return;}
    const pageCourse=currentCourseId();if(pageCourse && pending.courseId && pageCourse!==pending.courseId)return;
    pendingFinishActive=true;pending.lastAttempt=Date.now();savePendingAttendance(pending);
    try {
      for(let attempt=0;attempt<24;attempt+=1){
        const assignmentId=resolveCreatedAssignmentId(pending);
        if(assignmentId){
          debug('info','Linking newly created Schoology assignment',{assignmentId,eventId:pending.eventId,title:pending.title});
          await linkAttendanceEvent(pending.eventId,assignmentId);
          sessionStorage.removeItem(pendingKey);
          showStuCoNotice('Schoology assignment and attendance created and linked.');
          return;
        }
        await new Promise(resolve=>window.setTimeout(resolve,500));
      }
      showStuCoNotice('Attendance was created. If it is not linked yet, open the gradebook item and choose Import attendance.','warning');
    } catch(error) {
      debug('error','Automatic attendance linking failed',{message:error?.message || String(error),eventId:pending.eventId});
      showStuCoNotice(error?.message || 'Attendance was created, but it could not be linked automatically.','error');
    } finally { pendingFinishActive=false; }
  }
  async function createAttendanceFromAssignment(form,button,status) {
    const titleInput=assignmentTitleInput(form),dueInput=assignmentDueDateInput(form),nativeButton=nativeAssignmentCreateButton(form);
    if(!titleInput || !nativeButton){status.textContent='Schoology’s assignment fields could not be found.';status.className='stuco-assignment-helper-status is-error';return;}
    const eventDate=autofillAssignmentDefaults(form);
    if(!form.reportValidity())return;
    const title=normalizeText(titleInput.value);
    if(!title){status.textContent='Enter the assignment name first.';status.className='stuco-assignment-helper-status is-error';titleInput.focus();return;}
    if(!eventDate){status.textContent='Set the assignment due date first.';status.className='stuco-assignment-helper-status is-error';dueInput?.focus();return;}
    button.disabled=true;status.className='stuco-assignment-helper-status';
    try {
      let pending=readPendingAttendance();
      if(!pending || pending.title!==title || pending.eventDate!==eventDate){
        status.textContent='Creating attendance…';
        const result=await createAttendanceEvent(eventDate,'',title);
        pending={eventId:Number(result.event.id),title,eventDate,courseId:currentCourseId(),beforeIds:[...new Set(assignmentRecords().map(record=>record.id))],createdAt:Date.now(),lastAttempt:0};
        savePendingAttendance(pending);
      }
      status.textContent='Creating the Schoology assignment…';
      nativeButton.click();
      window.setTimeout(()=>{if(document.contains(button)){button.disabled=false;status.textContent='Attendance is ready. Fix any Schoology errors, then click Create attendance again.';}},5000);
    } catch(error) {
      button.disabled=false;status.className='stuco-assignment-helper-status is-error';status.textContent=error?.message || 'Attendance could not be created.';
    }
  }
  function installAssignmentAttendanceHelper() {
    document.querySelectorAll('form').forEach(form=>{
      if(form.querySelector('.stuco-assignment-helper'))return;
      const nativeButton=nativeAssignmentCreateButton(form),titleInput=assignmentTitleInput(form),pageLooksRight=/\/materials\/assignments\/add(?:\/|$)/.test(location.pathname) || [...document.querySelectorAll('h1,h2,[role="heading"],.page-title')].some(heading=>/create assignment/i.test(normalizeText(heading.textContent)));
      if(!pageLooksRight || !nativeButton || !titleInput)return;
      const helper=document.createElement('div');helper.className='stuco-assignment-helper';
      const actions=nativeButton.closest('.form-actions') || nativeButton.parentElement;actions.insertBefore(helper,nativeButton);
      const button=nativeButton.cloneNode(false);button.removeAttribute('id');button.removeAttribute('name');button.removeAttribute('onclick');button.removeAttribute('formaction');button.type='button';button.classList.add('stuco-create-attendance');
      if(button.tagName==='INPUT')button.value='Create attendance';else button.textContent='Create attendance';
      const status=document.createElement('span');status.className='stuco-assignment-helper-status';status.setAttribute('aria-live','polite');
      helper.append(button,status);button.addEventListener('click',()=>createAttendanceFromAssignment(form,button,status));
      const dueInput=assignmentDueDateInput(form);[titleInput,dueInput,assignmentPointsInput(form),assignmentFactorInput(form)].forEach(trackSchoologyField);
      dueInput?.addEventListener('change',()=>autofillAssignmentDefaults(form));
      autofillAssignmentDefaults(form);
    });
  }

  function assignmentDate() {
    const match=activeAssignmentName?.match(/\((\d{1,2})\/(\d{1,2})\/(\d{2,4})\)/);
    if (!match) return null;
    const year=match[3].length===2 ? 2000+Number(match[3]) : Number(match[3]);
    return `${year}-${match[1].padStart(2,'0')}-${match[2].padStart(2,'0')}`;
  }

  async function chooseAttendanceEvent() {
    try {
      const data=await attendanceFetch('/grade-events.php');
      if (!Array.isArray(data.events)) throw new Error('The attendance site returned an invalid event list.');
      if (!activeAssignmentId) throw new Error('Schoology did not provide the assignment ID. Close the menu and try again.');
      const linked=activeAssignmentId ? data.events.filter(event => String(event.schoology_assignment || '')===String(activeAssignmentId)) : [];
      if (linked.length===1) {
        const batch=await attendanceFetch(`/grade-batch.php?event_id=${encodeURIComponent(linked[0].id)}`);
        showImportPreview(batch);
        return;
      }
      showAttendanceLinker(data.events);
    } catch (error) { alert(`Could not load attendance: ${error.message || 'Unknown error'}`); }
  }

  function showAttendanceLinker(events) {
    document.querySelector('#stuco-grade-modal')?.remove();
    const now=new Date();
    const today=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    const date=assignmentDate() || today;
    const candidates=events.filter(event => !event.schoology_assignment || String(event.schoology_assignment)===String(activeAssignmentId));
    const ordered=[...candidates].sort((a,b) => {
      const aSame=a.event_date===date ? 1:0,bSame=b.event_date===date ? 1:0;
      return bSame-aSame || String(b.event_date).localeCompare(String(a.event_date)) || Number(b.id)-Number(a.id);
    });
    const modal=document.createElement('div');modal.id='stuco-grade-modal';
    const options=ordered.map(event=>{
      const formatted=new Date(`${event.event_date}T12:00:00`).toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});
      const title=String(event.title || 'Attendance');
      const label=title.includes(formatted) || title.includes(event.event_date) ? title : `${title} · ${formatted}`;
      return `<option value="${Number(event.id)}">${escapeHtml(label)}</option>`;
    }).join('');
    modal.innerHTML=`<div class="stuco-panel"><button type="button" class="stuco-close" data-close aria-label="Close">×</button><header class="stuco-modal-header"><span class="stuco-kicker">StuCo Attendance</span><h2>Link attendance</h2><p>Choose the attendance event that belongs to this Schoology assignment.</p><div class="stuco-assignment-name">${escapeHtml(activeAssignmentName || 'This Schoology assignment')}</div></header><section class="stuco-choice"><div class="stuco-choice-heading"><span class="stuco-step">1</span><div><h3>Use existing attendance</h3><p>Link an event you already created.</p></div></div><label class="stuco-field"><span>Attendance event</span><select data-event ${ordered.length?'':'disabled'}>${options || '<option>No unlinked attendance events available</option>'}</select></label><button type="button" class="stuco-primary" data-link ${ordered.length?'':'disabled'}>Link this attendance</button></section><div class="stuco-or"><span>or</span></div><section class="stuco-choice"><div class="stuco-choice-heading"><span class="stuco-step">2</span><div><h3>Create new attendance</h3><p>Make a separate event and link it automatically.</p></div></div><label class="stuco-field"><span>Event date</span><input type="date" data-create-date value="${escapeHtml(date)}"></label><button type="button" class="stuco-primary" data-create>Create and link new attendance</button></section><p class="stuco-status" data-status>Only this Schoology assignment will use the selected attendance.</p></div>`;
    document.body.appendChild(modal);
    modal.querySelectorAll('[data-close]').forEach(button=>button.onclick=()=>modal.remove());
    modal.onclick=event=>{if(event.target===modal)modal.remove();};
    modal.querySelector('[data-link]').onclick=async event=>{
      const button=event.currentTarget,eventId=Number(modal.querySelector('[data-event]').value);button.disabled=true;button.textContent='Linking…';
      try { await linkAttendanceEvent(eventId,activeAssignmentId);const batch=await attendanceFetch(`/grade-batch.php?event_id=${encodeURIComponent(eventId)}`);modal.remove();showImportPreview(batch); }
      catch(error){button.disabled=false;button.textContent='Link this attendance';const status=modal.querySelector('[data-status]');status.classList.add('is-error');status.textContent=error.message || 'Could not link attendance.';}
    };
    modal.querySelector('[data-create]').onclick=async event=>{
      const button=event.currentTarget,createDate=modal.querySelector('[data-create-date]').value;if(!/^\d{4}-\d{2}-\d{2}$/.test(createDate)){const status=modal.querySelector('[data-status]');status.classList.add('is-error');status.textContent='Choose a valid event date.';return;}
      button.disabled=true;button.textContent='Creating…';
      try { const result=await createAttendanceEvent(createDate,activeAssignmentId,activeAssignmentName || attendanceTitle(createDate));const batch=await attendanceFetch(`/grade-batch.php?event_id=${encodeURIComponent(result.event.id)}`);modal.remove();showImportPreview(batch); }
      catch(error){button.disabled=false;button.textContent='Create and link new attendance';const status=modal.querySelector('[data-status]');status.classList.add('is-error');status.textContent=error.message || 'Could not create attendance.';}
    };
  }

  function courseId() { return location.pathname.match(/^\/course\/(\d+)\/grades/)?.[1] || null; }
  function formDataFrom(documentFragment) {
    const form=documentFragment.querySelector('form'); if (!form) throw new Error('Schoology import form was not found.');
    const data=new FormData();
    form.querySelectorAll('input[type="hidden"]').forEach(input => data.append(input.name,input.value));
    return {action:form.action,data};
  }
  async function schoologyPost(url,data) {
    const response=await fetch(url,{method:'POST',body:data,credentials:'include'});
    if (!response.ok) throw new Error(`Schoology import failed (${response.status}).`);
    return response.text();
  }
  function batchCsv(batch,students) {
    const title=batch.event.title || `Attendance ${batch.event.date}`;
    return [['Unique User ID',title],...students.map(student=>[student.schoology_user_id,Number(student.score)])].map(row=>row.map(csvCell).join(',')).join('\r\n');
  }
  async function importIntoSchoology(batch,students) {
    const id=courseId(); if (!id) throw new Error('Open the Schoology Gradebook before importing.');
    const url=`${location.origin}/courses/import/grades/${id}`;
    const uploadPage=new DOMParser().parseFromString(await (await fetch(url,{credentials:'include'})).text(),'text/html');
    const upload=formDataFrom(uploadPage); upload.data.append('files[upload]',new Blob([batchCsv(batch,students)],{type:'text/csv'}),`attendance-${batch.event.id}.csv`); upload.data.append('op','Upload File');
    const matchPage=new DOMParser().parseFromString(await schoologyPost(upload.action,upload.data),'text/html');
    const match=formDataFrom(matchPage); match.data.append('has_headers','1');
    const fields=[...matchPage.querySelectorAll('select[name$="[matched_attr]"]')];
    if (fields.length < 2) throw new Error('Schoology could not read the attendance CSV.');
    match.data.append(fields[0].name,'school_uid'); match.data.append(fields[1].name,activeAssignmentId || 'new_assignment'); match.data.append('op','Preview');
    const previewPage=new DOMParser().parseFromString(await schoologyPost(match.action,match.data),'text/html');
    const confirm=formDataFrom(previewPage); confirm.data.append('op','Confirm');
    const completePage=await schoologyPost(confirm.action,confirm.data);
    if (!completePage.includes('Your grades have been imported successfully')) throw new Error('Schoology did not confirm the grade import.');
  }

  function showImportPreview(batch) {
    const students=batch.students.filter(student => Number(student.score)>0 && student.schoology_user_id);
    const skipped=batch.students.filter(student => Number(student.score)>0 && !student.schoology_user_id).length;
    const modal=document.createElement('div'); modal.id='stuco-grade-modal';
    const destination=activeAssignmentName ? `the Schoology assignment “${escapeHtml(activeAssignmentName)}”` : 'a new Schoology assignment';
    modal.innerHTML=`<div class="stuco-panel"><h2>${escapeHtml(batch.event.title)}</h2><p>Linked to this Schoology assignment. ${students.length} present students will receive their recorded attendance points in ${destination}. No submissions remain unchanged.</p>${skipped?`<p class="stuco-missing">${skipped} present student${skipped===1?'':'s'} missing a Schoology ID will be skipped.</p>`:''}<table><thead><tr><th>Student</th><th>Grade</th></tr></thead><tbody>${students.map(student=>`<tr><td>${escapeHtml(student.name)}</td><td>${escapeHtml(Number(student.score))}</td></tr>`).join('')}</tbody></table><button data-import>Import ${students.length} grades</button><button class="secondary" data-close>Cancel</button><p data-status><strong>Schoology will confirm when the import has finished.</strong></p></div>`;
    document.body.appendChild(modal); modal.querySelector('[data-close]').onclick=()=>modal.remove();
    modal.querySelector('[data-import]').onclick=async event => { const button=event.currentTarget; button.disabled=true; button.textContent='Importing…'; try { await importIntoSchoology(batch,students); button.textContent=`Imported ${students.length} grades`; modal.querySelector('[data-status]').textContent='Import complete. Refreshing Schoology…'; window.setTimeout(()=>location.reload(),500); } catch (error) { button.disabled=false; button.textContent='Try import again'; modal.querySelector('[data-status]').textContent=error.message || 'Schoology could not import the grades.'; } };
  }
})();
