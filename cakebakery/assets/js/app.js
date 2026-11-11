// Small UI helpers (no libraries)
// Back/forward button: never show a page from the browser's memory (it may be a protected page from before logout)
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
document.addEventListener('DOMContentLoaded', () => {
  // live delivery fee: the fee comes from the chosen delivery area (the server re-checks it from the database)
  const zone = document.getElementById('zone'), loc = document.getElementById('location'), box = document.querySelector('.summary');
  if (zone && box) {
    const sub = +box.dataset.subtotal, fmt = n => 'Ksh ' + Math.round(n).toLocaleString('en-US');
    const upd = () => {
      const f = +(zone.selectedOptions[0]?.dataset.fee || 0);
      document.getElementById('fee').textContent = f > 0 ? fmt(f) : (zone.value ? 'Free' : 'Ksh 0'); document.getElementById('total').textContent = fmt(sub + f);
      if (loc) { loc.required = zone.value !== ''; loc.placeholder = zone.value ? 'Street, estate or landmark (required)' : 'Not needed for pickup'; }
    };
    zone.addEventListener('change', upd); upd();
    // show the M-Pesa code box only when M-Pesa is chosen; stop double submits
    const mb = document.getElementById('mpesabox'), radios = document.querySelectorAll('input[name=method]');
    const mc = document.getElementById('mpesacode');
    const sw = () => { const m = document.querySelector('input[name=method]:checked'); const on = (!m || m.value === 'mpesa'); if (mb) mb.style.display = on ? '' : 'none'; if (mc) mc.required = on; };
    radios.forEach(r => r.addEventListener('change', sw)); sw();
    const co = document.getElementById('checkout'), pb = document.getElementById('placebtn');
    if (co && pb) co.addEventListener('submit', () => { pb.disabled = true; pb.textContent = 'Placing order…'; });
  }
  // admin dropdown
  const closeMenus = () => {
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    document.querySelectorAll('[data-dropdown]').forEach(b => b.setAttribute('aria-expanded', 'false'));
  };
  document.querySelectorAll('[data-dropdown]').forEach(b => b.addEventListener('click', e => {
    e.stopPropagation();
    const menu = b.nextElementSibling, wasOpen = menu.classList.contains('open');
    closeMenus();
    if (!wasOpen) { menu.classList.add('open'); b.setAttribute('aria-expanded', 'true'); }
  }));
  document.addEventListener('click', closeMenus);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenus(); });
  // file name on product upload
  const fi = document.querySelector('.upload input'); if (fi) fi.addEventListener('change', () => document.getElementById('fname').textContent = fi.files[0]?.name || 'Upload image');
  // order table: click a row to select, then use the action buttons
  const rows = document.querySelectorAll('#orders [data-row]');
  rows.forEach(r => r.addEventListener('click', () => { rows.forEach(x => x.classList.remove('sel')); r.classList.add('sel'); }));
  document.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => {
    const sel = document.querySelector('#orders tr.sel'); if (!sel) { alert('Click an order row first.'); return; }
    if (b.dataset.need && sel.dataset.status !== b.dataset.need) { alert('Only delivered orders can be deleted. Pick a delivered order, or use Cancel order.'); return; }
    const d = document.getElementById(b.dataset.open);
    d.querySelector('.sel-id').value = sel.dataset.id; d.querySelector('.sel-label').textContent = '#' + sel.dataset.id;
    const s = d.querySelector('select'); if (s) s.value = sel.dataset.status;
    d.showModal();
  }));
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
});

// Show / hide password: eye button inside the password field (customer and admin login)
document.addEventListener('click', e => {
  const b = e.target.closest('.pw-toggle'); if (!b) return;
  const inp = document.getElementById(b.getAttribute('aria-controls')); if (!inp) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  b.classList.toggle('on', show); b.setAttribute('aria-pressed', show); b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  inp.focus({preventScroll: true});
});
// keep the keyboard open on phones: do not let the button steal focus from the field
document.addEventListener('mousedown', e => { if (e.target.closest('.pw-toggle')) e.preventDefault(); });
