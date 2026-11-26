// Backoffice : notifications et confirmations (SweetAlert2, thème « bo » défini dans admin.css).
//   window.toast(message, type)          notification (success | error | warning | info)
//   window.notify([{message, type}, …])  plusieurs messages regroupés dans une seule notification
//   window.confirmDialog({…}) → Promise<boolean>
//   <button data-confirm="Texte" [data-confirm-title] [data-confirm-ok] [data-confirm-danger]>
//     → demande une confirmation avant l'action normale du bouton ou du lien.
(() => {
  'use strict';
  const Swal = window.Swal;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ICON = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
  // Pictogrammes des notifications (pastille colorée selon le type, cf. admin.css)
  const svg = (p) => `<svg viewBox="0 0 24 24" aria-hidden="true">${p}</svg>`;
  const ICON_HTML = {
    success: svg('<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
    error: svg('<path d="M7 7l10 10M17 7L7 17"/>'),
    warning: svg('<path d="M12 7v6"/><path d="M12 17h.01"/>'),
    info: svg('<path d="M12 11v6"/><path d="M12 7h.01"/>'),
  };

  /* ---------- Notifications ---------- */
  const Toast = Swal && Swal.mixin({
    toast: true,
    position: 'bottom-end',
    showConfirmButton: false,
    showCloseButton: true,
    timer: 4500,
    timerProgressBar: true,
    customClass: { popup: 'bo-toast', icon: 'bo-toast-icon', title: 'bo-toast-title', htmlContainer: 'bo-toast-text', closeButton: 'bo-toast-close', timerProgressBar: 'bo-toast-bar' },
    didOpen: (el) => { el.addEventListener('mouseenter', Swal.stopTimer); el.addEventListener('mouseleave', Swal.resumeTimer); },
  });

  // Une fenêtre de confirmation (ouverte ou en train de se fermer) est-elle à l'écran ?
  const dialogShown = () => Swal.isVisible() && !Swal.getPopup()?.classList.contains('bo-toast');

  // Affiche la notification dès que la fenêtre de confirmation a disparu (sans jamais l'écraser)
  function show(options, tries = 0) {
    if (dialogShown()) { if (tries < 60) setTimeout(() => show(options, tries + 1), 150); return; }
    Toast.fire({ ...options, iconHtml: ICON_HTML[options.icon] || ICON_HTML.info });
  }

  function toast(message, type = 'info') {
    if (!message) return;
    if (!Toast) { console.log(`[${type}] ${message}`); return; }
    show({ icon: ICON[type] || 'info', title: String(message) });
  }

  function notify(list) {
    const items = (list || []).filter((f) => f && f.message);
    if (!items.length) return;
    if (items.length === 1) { toast(items[0].message, items[0].type); return; }
    const type = items.some((f) => f.type === 'error') ? 'error' : items.some((f) => f.type === 'warning') ? 'warning' : items[items.length - 1].type;
    if (!Toast) { items.forEach((f) => toast(f.message, f.type)); return; }
    show({ icon: ICON[type] || 'info', title: `${items.length} messages`, html: '<ul>' + items.map((f) => `<li>${esc(f.message)}</li>`).join('') + '</ul>' });
  }

  /* ---------- Confirmations ---------- */
  function confirmDialog({ title = 'Confirmer l\'action', text = '', ok = 'Confirmer', cancel = 'Annuler', danger = false } = {}) {
    if (!Swal) return Promise.resolve(window.confirm(text || title));
    return Swal.fire({
      title, text,
      icon: danger ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: ok,
      cancelButtonText: cancel,
      reverseButtons: true,
      focusCancel: danger,
      buttonsStyling: false,
      customClass: {
        popup: 'bo-dialog' + (danger ? ' is-danger' : ''),
        title: 'bo-dialog-title',
        htmlContainer: 'bo-dialog-text',
        actions: 'bo-dialog-actions',
        confirmButton: 'btn ' + (danger ? 'btn-danger-solid' : 'btn-primary'),
        cancelButton: 'btn',
        icon: 'bo-dialog-icon',
      },
      showClass: { popup: 'bo-in' },
      hideClass: { popup: 'bo-out' },
    }).then((r) => r.isConfirmed);
  }

  // Interception en phase de capture : la confirmation passe avant toute autre action du bouton
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (!el || el.disabled) return;
    if (el.dataset.confirmed === '1') return;          // deuxième clic, après confirmation
    e.preventDefault();
    e.stopImmediatePropagation();
    confirmDialog({
      title: el.dataset.confirmTitle || (el.hasAttribute('data-confirm-danger') ? 'Action définitive' : 'Confirmer l\'action'),
      text: el.dataset.confirm,
      ok: el.dataset.confirmOk || (el.hasAttribute('data-confirm-danger') ? 'Supprimer' : 'Confirmer'),
      danger: el.hasAttribute('data-confirm-danger'),
    }).then((ok) => {
      if (!ok) return;
      el.dataset.confirmed = '1';
      try { el.click(); } finally { delete el.dataset.confirmed; }
    });
  }, true);

  // Sidebar : réduire / déplier (colonne d'icônes), choix mémorisé dans le navigateur
  // Réduite : le bouton disparaît et c'est le logo qui déplie la sidebar.
  const setCollapsed = (collapsed) => {
    document.documentElement.classList.toggle('side-collapsed', collapsed);
    try { localStorage.setItem('bo-side', collapsed ? 'collapsed' : 'expanded'); } catch { /* stockage indisponible */ }
  };
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-side-collapse]')) { setCollapsed(true); return; }
    const brand = e.target.closest('.bo-side .bo-brand');
    if (brand && document.documentElement.classList.contains('side-collapsed') && window.matchMedia('(min-width: 1025px)').matches) {
      e.preventDefault();
      e.stopImmediatePropagation();
      setCollapsed(false);
    }
  }, true);

  // Lignes dépliables : <tr data-expand="id-de-la-ligne-détail"> + bouton [data-row-toggle]
  function toggleRow(row, open) {
    const detail = document.getElementById(row.dataset.expand);
    if (!detail) return;
    open ??= detail.hidden;
    detail.hidden = !open;
    row.classList.toggle('is-expanded', open);
    row.querySelector('[data-row-toggle]')?.setAttribute('aria-expanded', String(open));
  }
  document.addEventListener('click', (e) => {
    const row = e.target.closest('tr[data-expand]');
    if (!row) return;
    if (e.target.closest('[data-row-toggle]')) { toggleRow(row); return; }
    if (e.target.closest('a, button, input, label, select, textarea, form')) return;
    if (window.getSelection()?.toString()) return;   // sélection de texte : ne pas replier
    toggleRow(row);
  });

  window.toast = toast;
  window.notify = notify;
  window.confirmDialog = confirmDialog;

  // Messages transmis par une page chargée normalement (connexion)
  if (Array.isArray(window.__flash)) notify(window.__flash);

  // Ferme les menus déroulants (<details class="bo-pop">) au clic extérieur ou sur Échap
  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.bo-pop[open]').forEach((d) => { if (!d.contains(e.target) || e.target.closest('a')) d.open = false; });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('details.bo-pop[open]').forEach((d) => { d.open = false; });
  });
  document.addEventListener('toggle', (e) => {
    if (e.target.matches?.('details.bo-search[open]')) e.target.querySelector('input')?.focus();
  }, true);
})();
