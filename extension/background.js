const attendanceOrigin = 'https://attendance.coderhue.dev';

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'stuco-attendance-create-event') {
    const date = String(message.eventDate || '');
    console.info('[StuCo Attendance] Background event request', {date});
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return sendResponse({ok:false,error:'Choose a valid attendance date.'});
    (async () => {
      const eventsUrl = `${attendanceOrigin}/admin?view=events`;
      const eventsPage = await fetch(eventsUrl,{credentials:'include'});
      const html = await eventsPage.text();
      if (!eventsPage.ok || /<h1>Sign in<\/h1>|Email or password is incorrect/i.test(html)) throw new Error('Sign in to the attendance admin site first, then try again.');
      const csrf = html.match(/name="csrf"\s+value="([^"]+)"/)?.[1];
      if (!csrf) throw new Error('The attendance site did not provide a secure creation token.');
      const response = await fetch(`${attendanceOrigin}/extension-create-event.php`,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({event_date:date})});
      const responseText = await response.text();
      if (!response.ok) throw new Error(`The attendance site returned ${response.status}.`);
      let result; try { result=JSON.parse(responseText); } catch { throw new Error('The attendance site returned an invalid response.'); }
      if (!result?.ok) throw new Error(result?.error || 'The attendance event could not be created.');
      sendResponse({ok:true,status:response.status,url:response.url,body:responseText});
    })().catch(error => { console.error('[StuCo Attendance] Background event request failed', error); sendResponse({ok:false,error:error.message || 'Could not reach the attendance site.'}); });
    return true;
  }
  if (message?.type !== 'stuco-attendance-fetch' || typeof message.path !== 'string') return;
  const allowed = message.path === '/grade-events.php' || /^\/grade-batch\.php\?event_id=\d+$/.test(message.path);
  if (!allowed) return sendResponse({ok:false,error:'Invalid attendance request.'});
  fetch(`${attendanceOrigin}${message.path}`,{credentials:'include'})
    .then(async response => sendResponse({ok:response.ok,status:response.status,body:await response.text(),url:response.url}))
    .catch(error => sendResponse({ok:false,error:error.message || 'Could not reach the attendance site.'}));
  return true;
});
