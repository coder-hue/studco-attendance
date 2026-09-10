document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-qr]').forEach(setupQr);
  if (window.LIVE_EVENT_ID) setupLiveAttendance();
});

let qrLibraryPromise;
function loadQrLibrary() {
  if (window.QRCode) return Promise.resolve();
  if (qrLibraryPromise) return qrLibraryPromise;
  qrLibraryPromise = new Promise((resolve, reject) => {
    const library = document.createElement('script');
    library.src = '/assets/qrcode.min.js';
    library.onload = resolve;
    library.onerror = () => reject(new Error('QR library unavailable'));
    document.head.appendChild(library);
  });
  return qrLibraryPromise;
}

function setupQr(canvas) {
  let style = 'black';
  const image = canvas;
  const scope = image.closest('[data-qr-panel]') || image.closest('aside') || document;
  const backingCanvas = document.createElement('canvas');
  backingCanvas.width = 1080; backingCanvas.height = 1080;
  const render = async () => {
    await loadQrLibrary();
    return new Promise((resolve, reject) => {
    if (!window.QRCode) return reject(new Error('QR library unavailable'));
    const colors = style === 'white' ? { dark: '#FFFFFF', light: '#00000000' } : { dark: '#000000', light: '#FFFFFF' };
    window.QRCode.toCanvas(backingCanvas, image.dataset.url, { width: 1080, margin: 2, errorCorrectionLevel: 'M', color: colors }, error => {
      if (error) return reject(error);
      image.src = backingCanvas.toDataURL('image/png');
      image.closest('.qr-box')?.classList.toggle('white-qr', style === 'white');
      resolve();
    });
    });
  };
  render().catch(() => { image.replaceWith(Object.assign(document.createElement('p'), { className: 'hint', textContent: 'QR generator could not load. Refresh and try again.' })); });

  scope.querySelectorAll('[data-qr-style]').forEach(button => button.addEventListener('click', () => {
    style = button.dataset.qrStyle;
    scope.querySelectorAll('[data-qr-style]').forEach(item => item.classList.toggle('active', item === button));
    render().catch(() => {});
  }));
  scope.querySelector('[data-copy-qr]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    try {
      await render();
      const blob = await new Promise(resolve => backingCanvas.toBlob(resolve, 'image/png'));
      if (!blob || !navigator.clipboard || !window.ClipboardItem) throw new Error('Clipboard unavailable');
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
      button.textContent = 'Copied PNG';
    } catch (_) { button.textContent = 'Use download instead'; }
  });
  scope.querySelector('[data-download-qr]')?.addEventListener('click', async () => {
    await render();
    const link = document.createElement('a');
    link.href = backingCanvas.toDataURL('image/png');
    link.download = `attendance-qr-${image.dataset.eventId}-${style}.png`;
    link.click();
  });
  scope.querySelector('[data-copy-link]')?.addEventListener('click', async event => {
    await navigator.clipboard.writeText(image.dataset.url);
    event.currentTarget.textContent = 'Link copied';
  });
}

function setupLiveAttendance() {
  const table = document.querySelector('[data-live-table]');
  const template = document.querySelector('[data-attendance-form]');
  const update = async () => {
    try {
      const response = await fetch(`/api.php?action=live&event_id=${window.LIVE_EVENT_ID}`);
      if (!response.ok) return;
      const data = await response.json();
      Object.entries(data.counts).forEach(([key, value]) => { const el = document.querySelector(`[data-count="${key}"]`); if (el) el.textContent = value; });
      table.innerHTML = '';
      data.attendance.forEach(row => {
        const tr = document.createElement('tr');
        const time = row.check_in_time ? new Date(row.check_in_time.replace(' ', 'T')).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}) : '—';
        const statusLabel = row.status === 'present' && Number(row.points) > 0 ? `${Number(row.points)} points` : 'No submission';
        tr.innerHTML = `<td><strong></strong></td><td><span class="badge status-${row.status}">${statusLabel}</span></td><td>${time}</td><td></td>`;
        tr.querySelector('strong').textContent = row.name;
        const form = template.content.cloneNode(true);
        form.querySelector('[name="member_id"]').value = row.id;
        const statusSelect = form.querySelector('[name="status"]');
        statusSelect.value = statusSelect.querySelector(`[value="${Number(row.points)}"]`) ? String(Number(row.points)) : row.status;
        tr.lastElementChild.appendChild(form);
        table.appendChild(tr);
      });
    } catch (_) {}
  };
  update(); setInterval(update, 5000);
}
