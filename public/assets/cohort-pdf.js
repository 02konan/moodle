// Listes PDF des étudiants par cohorte (en-tête + logo sur chaque page).
// Boutons : [data-pdf-cohort="NOM"] → un PDF ; #cohort-bulk-export → un ZIP (un PDF par cohorte cochée).
(() => {
  'use strict';
  const notify = (m, t = 'error') => (window.toast ? window.toast(m, t) : alert(m));

  const G = {
    ink: [40, 40, 38], text: [70, 70, 68], soft: [130, 130, 127], line: [205, 205, 202],
    headBg: [0, 0, 0], headInk: [255, 255, 255], zebra: [245, 245, 243], accent: [249, 128, 18],
  };

  /* ---------- Logo (chargé une fois, converti en data URL) ---------- */
  let logoPromise = null;
  function loadLogo() {
    logoPromise ??= fetch('../assets/logo.png')
      .then((r) => (r.ok ? r.blob() : null))
      .then((b) => b && new Promise((res) => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(b); }))
      .catch(() => null);
    return logoPromise;
  }

  async function fetchCohorts(names) {
    const qs = new URLSearchParams();
    names.forEach((n) => qs.append('cohorts[]', n));
    const res = await fetch('./cohort-data.php?' + qs, { headers: { Accept: 'application/json' } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Erreur serveur');
    return data;
  }

  /* ---------- Construction d'un PDF ---------- */
  function buildPdf(c, generated, logo) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const M = 14, PW = doc.internal.pageSize.getWidth(), PH = doc.internal.pageSize.getHeight();

    const header = () => {
      let x = M;
      if (logo) { doc.addImage(logo, 'PNG', M, 10, 20, 20); x = M + 25; }
      doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(...G.ink);
      doc.text('Liste des étudiants', x, 15);
      doc.setFontSize(11);
      doc.text(`Cohorte : ${c.cohort}`, x, 21);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...G.soft);
      const sub = [c.section, c.class, `${c.students.length} étudiant${c.students.length > 1 ? 's' : ''}`].filter(Boolean).join('  ·  ');
      doc.text(doc.splitTextToSize(sub, PW - x - M - 40), x, 26);
      doc.text(`Généré le ${generated}`, PW - M, 15, { align: 'right' });
      doc.setDrawColor(...G.accent); doc.setLineWidth(0.6);
      doc.line(M, 34, PW - M, 34);
    };

    doc.autoTable({
      startY: 40,
      margin: { top: 40, left: M, right: M, bottom: 16 },
      head: [['N°', 'Nom complet', "Nom d'utilisateur", 'Mot de passe']],
      body: c.students.length
        ? c.students.map((s, i) => [i + 1, s.name, s.username, s.password])
        : [[{ content: 'Aucun étudiant', colSpan: 4, styles: { halign: 'center', fontStyle: 'italic', textColor: G.soft } }]],
      theme: 'grid',
      styles: { font: 'helvetica', fontSize: 9.5, cellPadding: 2.6, textColor: G.text, lineColor: G.line, lineWidth: 0.15 },
      headStyles: { fillColor: G.headBg, textColor: G.headInk, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: G.zebra },
      columnStyles: { 0: { cellWidth: 12, halign: 'right' }, 2: { font: 'courier' }, 3: { font: 'courier' } },
      didDrawPage: header,           // en-tête + logo sur chaque page
    });

    const n = doc.internal.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...G.soft);
      doc.text(`${c.cohort}  —  Page ${i} / ${n}`, PW - M, PH - 7, { align: 'right' });
    }
    return doc;
  }

  // Nom des fichiers : [nom-cohorte]-[date]-[heure].pdf  (ex. BTS-2-FC-03-ANGRE-2026-11-21-16h05.pdf)
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}h${pad(d.getMinutes())}`; };
  const fileName = (cohort, ts) => `${cohort.replace(/[^A-Za-z0-9._-]+/g, '_')}-${ts}.pdf`;

  /* ---------- Librairies chargées à la demande (premier export) ---------- */
  // Hébergées dans assets/vendor (aucune dépendance à un CDN)
  const CDN = {
    jspdf:     '../assets/vendor/jspdf.umd.min.js?v=2.5.1',
    autotable: '../assets/vendor/jspdf.plugin.autotable.min.js?v=3.8.2',
    jszip:     '../assets/vendor/jszip.min.js?v=3.10.1',
  };
  const loaded = {};
  const loadScript = (src) => (loaded[src] ??= new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = src; s.onload = res;
    s.onerror = () => { delete loaded[src]; rej(new Error('Librairie indisponible : vérifiez la connexion et réessayez.')); };
    document.head.appendChild(s);
  }));
  async function ready(needZip) {
    if (!window.jspdf?.jsPDF) await loadScript(CDN.jspdf);
    if (!window.jspdf.jsPDF.API.autoTable) await loadScript(CDN.autotable);
    if (needZip && !window.JSZip) await loadScript(CDN.jszip);
  }

  async function busy(btn, fn) {
    if (btn.disabled) return;
    btn.disabled = true; btn.classList.add('is-loading');
    try { await fn(); } catch (e) { notify(e.message || String(e)); }
    finally { btn.disabled = false; btn.classList.remove('is-loading'); }
  }

  /* ---------- Agenda d'un cours en PDF paysage ---------- */
  function buildAgendaPdf(data, logo) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const M = 12, PW = doc.internal.pageSize.getWidth(), PH = doc.internal.pageSize.getHeight();

    const header = () => {
      let x = M;
      if (logo) { doc.addImage(logo, 'PNG', M, 8, 18, 18); x = M + 22; }
      doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(...G.ink);
      doc.text('Agenda des QCM', x, 14);
      doc.setFontSize(10); doc.text(data.plan, x, 20);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...G.soft);
      doc.text(`Généré le ${data.generated}`, PW - M, 14, { align: 'right' });
      doc.setDrawColor(...G.accent); doc.setLineWidth(0.6); doc.line(M, 29, PW - M, 29);
    };

    // Une ligne fusionnée annonce chaque section / sous-section ; les QCM qui suivent leur appartiennent.
    const COLS = 5;
    const band = (text, level) => [{
      content: text, colSpan: COLS,
      styles: level === 0
        ? { fillColor: [235, 235, 232], textColor: G.ink, fontStyle: 'bold', fontSize: 9.5 }
        : { fillColor: [247, 247, 245], textColor: G.text, fontStyle: 'bolditalic', fontSize: 8.8, cellPadding: { top: 1.6, bottom: 1.6, left: 7, right: 2 } },
    }];
    const body = [];
    let section = null, sub = null;
    data.quizzes.forEach((q) => {
      if (q.section !== section) { section = q.section; sub = null; body.push(band(section, 0)); }
      if (q.subsection && q.subsection !== sub) body.push(band(q.subsection, 1));
      else if (!q.subsection && sub) body.push(band(section + ' (suite)', 0));   // retour à la section après une sous-section
      sub = q.subsection;
      const indent = q.subsection ? 7 : 3;
      const n = Math.max(q.schedules.length, 1);
      const quizCell = { content: q.quiz, rowSpan: n, styles: { fontStyle: 'bold', cellPadding: { top: 2, bottom: 2, left: indent, right: 2 } } };
      if (!q.schedules.length) {
        body.push([quizCell, { content: 'Non programmé', colSpan: 4, styles: { fontStyle: 'italic', textColor: G.soft } }]);
        return;
      }
      q.schedules.forEach((s, i) => body.push([...(i === 0 ? [quizCell] : []), s.cohort, s.opens, s.closes, s.status]));
    });
    if (!body.length) body.push([{ content: 'Ce cours ne contient encore aucun QCM.', colSpan: COLS, styles: { halign: 'center', fontStyle: 'italic', textColor: G.soft } }]);

    doc.autoTable({
      startY: 34,
      margin: { top: 34, left: M, right: M, bottom: 14 },
      head: [['QCM', 'Cohorte', 'Ouverture', 'Fermeture', 'État']],
      body,
      theme: 'grid',
      styles: { font: 'helvetica', fontSize: 8.5, cellPadding: 2, textColor: G.text, lineColor: G.line, lineWidth: 0.15, valign: 'middle' },
      headStyles: { fillColor: G.headBg, textColor: G.headInk, fontStyle: 'bold' },
      columnStyles: { 0: { cellWidth: 105 }, 1: { cellWidth: 55 }, 2: { cellWidth: 36 }, 3: { cellWidth: 36 }, 4: { cellWidth: 'auto' } },
      rowPageBreak: 'avoid',
      didDrawPage: header,
    });

    const n = doc.internal.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); doc.setTextColor(...G.soft);
      doc.text(`${data.plan}  —  Page ${i} / ${n}`, PW - M, PH - 6, { align: 'right' });
    }
    return doc;
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-agenda-pdf]');
    if (!btn) return;
    e.preventDefault();
    busy(btn, async () => {
      await ready(false);
      const res = await fetch('./agenda-data.php?plan=' + encodeURIComponent(btn.dataset.agendaPdf), { headers: { Accept: 'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'Erreur serveur');
      const logo = await loadLogo();
      const slug = data.plan.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase().slice(0, 80);
      buildAgendaPdf(data, logo).save(`agenda-${slug || 'cours'}-${stamp()}.pdf`);
    });
  });

  /* ---------- Agenda général / par cohorte (page Agenda) : une ligne fusionnée par jour ---------- */
  function buildSchedulePdf(title, subtitle, data, rows, withCohort) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const M = 12, PW = doc.internal.pageSize.getWidth(), PH = doc.internal.pageSize.getHeight();
    const logo = data.logo;
    const header = () => {
      let x = M;
      if (logo) { doc.addImage(logo, 'PNG', M, 8, 18, 18); x = M + 22; }
      doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(...G.ink);
      doc.text(title, x, 14);
      doc.setFontSize(10); doc.text(subtitle, x, 20);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(...G.soft);
      doc.text([data.period, ...data.filters].join('   ·   '), x, 25);
      doc.text(`Généré le ${data.generated}`, PW - M, 14, { align: 'right' });
      doc.setDrawColor(...G.accent); doc.setLineWidth(0.6); doc.line(M, 29, PW - M, 29);
    };
    const cols = withCohort ? 5 : 4;
    const body = [];
    let day = null;
    rows.forEach((r) => {
      if (r.day !== day) {
        day = r.day;
        body.push([{ content: day, colSpan: cols, styles: { fillColor: [235, 235, 232], textColor: G.ink, fontStyle: 'bold', fontSize: 9.5 } }]);
      }
      body.push([r.hours.replace('→', '–'), r.plan, r.quiz, ...(withCohort ? [r.cohort] : []), r.status]);   // « → » absent de la police PDF
    });
    if (!body.length) body.push([{ content: 'Aucune programmation sur cette période.', colSpan: cols, styles: { halign: 'center', fontStyle: 'italic', textColor: G.soft } }]);

    doc.autoTable({
      startY: 34,
      margin: { top: 34, left: M, right: M, bottom: 14 },
      head: [['Ouverture – fermeture', 'Cours', 'QCM', ...(withCohort ? ['Cohorte'] : []), 'État']],
      body,
      theme: 'grid',
      styles: { font: 'helvetica', fontSize: 8.5, cellPadding: 2, textColor: G.text, lineColor: G.line, lineWidth: 0.15, valign: 'middle' },
      headStyles: { fillColor: G.headBg, textColor: G.headInk, fontStyle: 'bold' },
      columnStyles: withCohort
        ? { 0: { cellWidth: 36, fontStyle: 'bold' }, 1: { cellWidth: 78 }, 2: { cellWidth: 78 }, 3: { cellWidth: 52 }, 4: { cellWidth: 'auto' } }
        : { 0: { cellWidth: 40, fontStyle: 'bold' }, 1: { cellWidth: 100 }, 2: { cellWidth: 104 }, 3: { cellWidth: 'auto' } },
      rowPageBreak: 'avoid',
      didDrawPage: header,
    });
    const n = doc.internal.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); doc.setTextColor(...G.soft);
      doc.text(`${subtitle}  —  Page ${i} / ${n}`, PW - M, PH - 6, { align: 'right' });
    }
    return doc;
  }

  function downloadBlob(blob, name) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-agenda-export]');
    if (!btn) return;
    e.preventDefault();
    const form = btn.closest('form');
    const qs = new URLSearchParams(new FormData(form));
    busy(btn, async () => {
      const perCohort = btn.dataset.agendaExport === 'cohort';
      await ready(perCohort);
      const res = await fetch('./agenda-export-data.php?' + qs, { headers: { Accept: 'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'Erreur serveur');
      data.logo = await loadLogo();
      const ts = stamp();
      if (!perCohort) {
        buildSchedulePdf('Agenda des QCM', 'Agenda général', data, data.schedules, true).save(`agenda-general-${ts}.pdf`);
        return;
      }
      const byCohort = new Map();
      data.schedules.forEach((r) => { if (!byCohort.has(r.cohort)) byCohort.set(r.cohort, []); byCohort.get(r.cohort).push(r); });
      if (!byCohort.size) throw new Error('Aucune programmation sur cette période.');
      const file = (c) => `${c.replace(/[^A-Za-z0-9._-]+/g, '_')}-agenda-${ts}.pdf`;
      const cohorts = [...byCohort.keys()].sort((a, b) => a.localeCompare(b, 'fr', { numeric: true }));
      if (cohorts.length === 1) {
        buildSchedulePdf('Agenda des QCM', `Cohorte ${cohorts[0]}`, data, byCohort.get(cohorts[0]), false).save(file(cohorts[0]));
        return;
      }
      const zip = new window.JSZip();
      cohorts.forEach((c) => zip.file(file(c), buildSchedulePdf('Agenda des QCM', `Cohorte ${c}`, data, byCohort.get(c), false).output('arraybuffer')));
      downloadBlob(await zip.generateAsync({ type: 'blob' }), `agendas-cohortes-${ts}.zip`);
      notify(`${cohorts.length} agendas de cohorte téléchargés (fichier ZIP).`, 'success');
    });
  });

  /* ---------- Export d'une cohorte ---------- */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-pdf-cohort]');
    if (!btn) return;
    e.preventDefault();
    busy(btn, async () => {
      await ready(false);
      const [data, logo] = await Promise.all([fetchCohorts([btn.dataset.pdfCohort]), loadLogo()]);
      if (!data.cohorts.length) throw new Error('Aucun étudiant déjà exporté dans cette cohorte.');
      buildPdf(data.cohorts[0], data.generated, logo).save(fileName(data.cohorts[0].cohort, stamp()));
    });
  });

  /* ---------- Export de masse : un PDF par cohorte, regroupés dans un ZIP ---------- */
  function init() {
  const bulkBtn = document.getElementById('cohort-bulk-export');
  if (!bulkBtn || bulkBtn.dataset.ready) return;
  bulkBtn.dataset.ready = '1';
  const rows = () => [...document.querySelectorAll('input.cohort-check')];
  const picked = () => rows().filter((c) => c.checked).map((c) => c.value);
  const checkAll = document.getElementById('cohort-check-all');
  const bar = document.getElementById('cohort-bulk-bar');
  const count = document.getElementById('cohort-bulk-count');

  function refresh() {
    const all = rows(), sel = picked();
    count.textContent = sel.length;
    bar.hidden = sel.length === 0;
    all.forEach((c) => c.closest('tr').classList.toggle('is-selected', c.checked));
    if (checkAll) { checkAll.checked = all.length > 0 && sel.length === all.length; checkAll.indeterminate = sel.length > 0 && sel.length < all.length; }
  }
  checkAll?.addEventListener('change', () => { rows().forEach((c) => { c.checked = checkAll.checked; }); refresh(); });
  rows().forEach((c) => c.addEventListener('change', refresh));
  document.getElementById('cohort-bulk-clear')?.addEventListener('click', () => { rows().forEach((c) => { c.checked = false; }); refresh(); });
  refresh();

  bulkBtn.addEventListener('click', () => {
    const names = picked();
    if (!names.length) return;
    busy(bulkBtn, async () => {
      await ready(names.length > 1);
      const [data, logo] = await Promise.all([fetchCohorts(names), loadLogo()]);
      if (!data.cohorts.length) throw new Error('Aucun étudiant déjà exporté dans les cohortes sélectionnées.');
      if (data.cohorts.length === 1) {
        buildPdf(data.cohorts[0], data.generated, logo).save(fileName(data.cohorts[0].cohort, stamp()));
        return;
      }
      const ts = stamp();
      const zip = new window.JSZip();
      data.cohorts.forEach((c) => zip.file(fileName(c.cohort, ts), buildPdf(c, data.generated, logo).output('arraybuffer')));
      const blob = await zip.generateAsync({ type: 'blob' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `listes-cohortes-${ts}.zip`;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 10000);
      notify(`${data.cohorts.length} listes PDF téléchargées (fichier ZIP).`, 'success');
    });
  });
  }

  document.addEventListener('app:load', init);
  init();
})();
