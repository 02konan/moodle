// Cohortes : création dans Moodle en un clic (comptes, cohorte, inscriptions, groupes).
// Boutons : [data-moodle-sync="NOM"] pour une cohorte, #cohort-bulk-sync pour la sélection.
// Les cohortes sont traitées une par une ; le rapport s'affiche dans la fenêtre #sync-dialog.
(() => {
  'use strict';
  const csrf = () => document.querySelector('meta[name="csrf"]')?.content || '';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let running = false;

  async function syncOne(cohort) {
    const body = new FormData();
    body.append('csrf', csrf()); body.append('cohort', cohort);
    const res = await fetch('./moodle-sync.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } });
    const data = await res.json().catch(() => ({ error: 'Réponse illisible du serveur.' }));
    if (!res.ok || !data.ok) throw new Error(data.error || data.flash?.[0]?.message || 'Erreur serveur');
    return data.report;
  }

  function renderReport(r) {
    const courses = r.courses.map((c) => `<li>${esc(c.shortname)} : ${c.error
      ? `<span class="sync-err">${esc(c.error)}</span>`
      : `${c.enrolled} inscrit(s)${c.group_created ? ', groupe créé' : ', groupe existant'}`}</li>`).join('');
    const failed = r.failed.map((f) => `<li><code>${esc(f.username)}</code> : <span class="sync-err">${esc(f.message)}</span></li>`).join('');
    const warnings = r.warnings.map((w) => `<li class="sync-warn">${esc(w)}</li>`).join('');
    return `<p><strong>${r.created}</strong> compte(s) créé(s), <strong>${r.existing}</strong> déjà existant(s)`
      + `${r.failed.length ? `, <strong class="sync-err">${r.failed.length} en erreur</strong>` : ''}`
      + ` · cohorte ${r.cohort_created ? 'créée' : 'existante'}</p>`
      + (courses || warnings ? `<ul>${courses}${warnings}</ul>` : '')
      + (failed ? `<details open><summary>Comptes non créés</summary><ul>${failed}</ul></details>` : '');
  }

  async function run(cohorts) {
    if (running || !cohorts.length) return;
    const dlg = document.getElementById('sync-dialog');
    const log = document.getElementById('sync-log');
    const status = document.getElementById('sync-status');
    const close = document.getElementById('sync-close');
    if (!dlg) return;
    running = true;
    log.innerHTML = ''; close.disabled = true; dlg.showModal();
    let ok = 0, ko = 0;
    for (const [i, cohort] of cohorts.entries()) {
      status.textContent = `Cohorte ${i + 1} sur ${cohorts.length} : ${cohort}…`;
      const item = document.createElement('div');
      item.className = 'sync-item';
      item.innerHTML = `<h3>${esc(cohort)}</h3><p class="muted small">En cours…</p>`;
      log.appendChild(item); item.scrollIntoView({ block: 'nearest' });
      try {
        const r = await syncOne(cohort);
        item.innerHTML = `<h3>${esc(cohort)}</h3>` + renderReport(r);
        item.classList.add(r.failed.length || r.courses.some((c) => c.error) ? 'is-warn' : 'is-ok');
        ok++;
      } catch (e) {
        item.innerHTML = `<h3>${esc(cohort)}</h3><p class="sync-err">${esc(e.message)}</p>`;
        item.classList.add('is-ko');
        ko++;
      }
    }
    status.textContent = `Terminé : ${ok} cohorte(s) traitée(s)${ko ? `, ${ko} en échec` : ''}.`;
    close.disabled = false;
    running = false;
  }

  /* ---------- Boutons ---------- */
  document.addEventListener('click', (e) => {
    const one = e.target.closest('[data-moodle-sync]');
    if (one) {
      e.preventDefault();
      const c = one.dataset.moodleSync;
      window.confirmDialog({ title: 'Créer dans Moodle', text: `Créer les comptes, la cohorte, les inscriptions et les groupes de ${c} dans Moodle ?`, ok: 'Créer dans Moodle' })
        .then((ok) => { if (ok) run([c]); });
      return;
    }
    if (e.target.closest('#cohort-bulk-sync')) {
      const list = [...document.querySelectorAll('input.cohort-check:checked')].map((c) => c.value);
      if (list.length) window.confirmDialog({ title: 'Créer dans Moodle', text: `Créer les comptes, cohortes, inscriptions et groupes de ${list.length} cohorte(s) dans Moodle ?`, ok: 'Créer dans Moodle' })
        .then((ok) => { if (ok) run(list); });
      return;
    }
    if (e.target.closest('#sync-close')) {
      document.getElementById('sync-dialog')?.close();
      window.appVisit?.(location.href, { history: 'none', soft: true });   // compteurs « Non exportés » à jour
    }
  });

  // Fermeture par Échap interdite pendant le traitement
  document.addEventListener('cancel', (e) => { if (running && e.target.id === 'sync-dialog') e.preventDefault(); }, true);
})();
