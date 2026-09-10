document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('[data-member-search]');
  const results = document.querySelector('[data-member-results]');
  const form = document.querySelector('[data-member-select-form]');
  if (!input || !results || !form) return;
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) { results.innerHTML = ''; return; }
    timer = setTimeout(async () => {
      const response = await fetch(`/api.php?action=members&q=${encodeURIComponent(q)}`);
      const data = await response.json();
      results.innerHTML = data.members.length ? data.members.map(member => `<button type="button" class="person-option" data-id="${member.id}"><strong>${escapeHtml(member.name)}</strong></button>`).join('') : '<p class="muted">No matching member found.</p>';
    }, 150);
  });
  results.addEventListener('click', event => {
    const button = event.target.closest('[data-id]');
    if (!button) return;
    form.querySelector('[name="member_id"]').value = button.dataset.id;
    form.submit();
  });
});
function escapeHtml(value) { const el = document.createElement('div'); el.textContent = value ?? ''; return el.innerHTML; }
