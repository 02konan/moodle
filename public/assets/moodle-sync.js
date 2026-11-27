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

  function courseLine(c) {
    const parts = [`${c.enrolled} inscrit(s)` + (c.already_enrolled ? `, ${c.already_enrolled} déjà inscrit(s)` : '')];
    parts.push((c.group_created ? 'groupe créé' : 'groupe existant') + ` : ${c.group_added} ajouté(s)` + (c.already_in_group ? `, ${c.already_in_group} déjà membre(s)` : ''));
    if (c.message_failed) parts.push('<span class="sync-warn">message de bienvenue non envoyé</span>');
    return parts.join(' · ');
  }

  function renderReport(r) {
    const courses = r.courses.map((c) => `<li>${esc(c.shortname)} : ${c.found ? courseLine(c) : ''}${c.error
      ? `${c.found ? ' · ' : ''}<span class="sync-err">${esc(c.error)}</span>` : ''}</li>`).join('');
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

  /* ---------- Agenda → dérogations de groupe des tests Moodle ---------- */
  let agendaDlg = null;
  function agendaDialog() {
    if (agendaDlg && document.body.contains(agendaDlg)) return agendaDlg;
    agendaDlg = document.createElement('dialog');
    agendaDlg.className = 'modal detail-modal agenda-sync-modal';
    agendaDlg.innerHTML = '<div class="modal-head"><h2>Envoi de l\'agenda à Moodle</h2>'
      + '<button class="icon-btn" type="button" data-close-agenda aria-label="Fermer"><svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>'
      + '<div class="modal-content"></div>';
    agendaDlg.addEventListener('click', (e) => { if (e.target === agendaDlg || e.target.closest('[data-close-agenda]')) agendaDlg.close(); });
    agendaDlg.addEventListener('close', () => window.appVisit?.(location.href, { history: 'none', soft: true }));   // statuts « Envoyé » à jour
    document.body.appendChild(agendaDlg);
    return agendaDlg;
  }
  function renderAgenda(r) {
    const quizzes = r.quizzes.map((q) => {
      const done = [q.created && `${q.created} créée(s)`, q.updated && `${q.updated} mise(s) à jour`, q.deleted && `${q.deleted} supprimée(s)`].filter(Boolean).join(', ');
      const cls = q.errors.length ? 'is-ko' : (q.warning ? 'is-warn' : 'is-ok');
      return `<div class="sync-quiz ${cls}"><strong>${esc(q.title)}</strong>`
        + `<span class="small muted">${q.quiz ? `Test Moodle : ${esc(q.quiz)} · ` : ''}${done ? `Dérogations : ${done}` : 'Aucune dérogation modifiée'}</span>`
        + (q.warning ? `<span class="small sync-warn">${esc(q.warning)}</span>` : '')
        + (q.errors.length ? `<ul>${q.errors.map((e) => `<li class="sync-err">${esc(e)}</li>`).join('')}</ul>` : '') + '</div>';
    }).join('');
    return `<p>Cours Moodle <strong>${esc(r.course)}</strong> : <strong>${r.saved}</strong> dérogation(s) enregistrée(s)`
      + `${r.deleted ? `, <strong>${r.deleted}</strong> supprimée(s)` : ''}${r.errors ? `, <strong class="sync-err">${r.errors} erreur(s)</strong>` : ''}.</p>`
      + `<p class="muted small">Les dates apparaissent dans le calendrier Moodle des étudiants de chaque groupe GP/&lt;cohorte&gt;.</p>` + quizzes;
  }
  async function pushAgenda(btn) {
    const body = new FormData();
    body.append('csrf', csrf()); body.append('plan', btn.dataset.plan);
    if (btn.dataset.item) body.append('item', btn.dataset.item);
    // La fenêtre s'ouvre tout de suite : l'envoi peut prendre du temps (un appel Moodle par QCM et par cohorte)
    const dlg = agendaDialog();
    const content = dlg.querySelector('.modal-content');
    content.innerHTML = '<p class="sync-progress"><span class="spinner" aria-hidden="true"></span>Envoi en cours vers Moodle… Ne fermez pas la page.</p>';
    if (!dlg.open) dlg.showModal();
    btn.disabled = true; btn.classList.add('is-loading');
    try {
      const res = await fetch('./moodle-agenda.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch { data = { error: `Réponse inattendue du serveur (HTTP ${res.status}).` }; console.error('[moodle-agenda]', text); }
      if (!res.ok || !data.ok) throw new Error(data.error || data.flash?.[0]?.message || `Erreur serveur (HTTP ${res.status}).`);
      content.innerHTML = renderAgenda(data.report);
    } catch (e) {
      console.error('[moodle-agenda]', e);
      content.innerHTML = `<p class="sync-err"><strong>L'envoi a échoué :</strong> ${esc(e.message || String(e))}</p>`
        + '<p class="muted small">Vérifiez dans Moodle que les fonctions mod_quiz_* sont ajoutées au service web et que le titre du cours correspond au nom abrégé du cours Moodle.</p>';
    } finally {
      btn.disabled = false; btn.classList.remove('is-loading');
    }
  }

  /* ---------- Boutons ---------- */
  document.addEventListener('click', (e) => {
    const agenda = e.target.closest('[data-moodle-agenda]');
    if (agenda) {
      e.preventDefault();
      if (agenda.disabled) return;
      const what = agenda.dataset.item ? `du QCM « ${agenda.dataset.label} »` : `du cours « ${agenda.dataset.label} »`;
      window.confirmDialog({ title: 'Envoyer l\'agenda à Moodle ?', text: `Les programmations ${what} deviennent des dérogations de groupe GP/<cohorte> sur les tests Moodle du même nom.`, ok: 'Envoyer' })
        .then((ok) => { if (ok) pushAgenda(agenda); });
      return;
    }
    const one = e.target.closest('[data-moodle-sync]');
    if (one) {
      e.preventDefault();
      const c = one.dataset.moodleSync;
      window.confirmDialog({ title: 'Créer dans Moodle ?', text: `Comptes, cohorte, inscriptions et groupes de ${c}.`, ok: 'Créer' })
        .then((ok) => { if (ok) run([c]); });
      return;
    }
    if (e.target.closest('#cohort-bulk-sync')) {
      const list = [...document.querySelectorAll('input.cohort-check:checked')].map((c) => c.value);
      if (list.length) window.confirmDialog({ title: 'Créer dans Moodle ?', text: `Comptes, cohortes, inscriptions et groupes de ${list.length} cohorte(s).`, ok: 'Créer' })
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
