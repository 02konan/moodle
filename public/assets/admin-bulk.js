// Index admin : sélection multiple + modale de modification / suppression de masse.
(() => {
  'use strict';
  const form = document.getElementById('bulk-form');
  if (!form) return;

  const $ = (id) => document.getElementById(id);
  const bar = $('bulk-bar'), dlg = $('bulk-dialog'), checkAll = $('check-all');
  const counts = [$('bulk-count'), $('bulk-count-2')];
  const rows = () => [...document.querySelectorAll('input.row-check')];
  const picked = () => rows().filter((c) => c.checked);
  const notify = (m, t = 'error') => window.toast && window.toast(m, t);

  /* ---------- Sélection ---------- */
  function refresh() {
    const all = rows(), sel = picked();
    counts.forEach((c) => { c.textContent = sel.length; });
    bar.hidden = sel.length === 0;
    all.forEach((c) => c.closest('tr').classList.toggle('is-selected', c.checked));
    if (checkAll) {
      checkAll.checked = all.length > 0 && sel.length === all.length;
      checkAll.indeterminate = sel.length > 0 && sel.length < all.length;
    }
  }
  checkAll?.addEventListener('change', () => { rows().forEach((c) => { c.checked = checkAll.checked; }); refresh(); });
  document.addEventListener('change', (e) => { if (e.target.matches('input.row-check')) refresh(); });
  $('bulk-clear').addEventListener('click', () => { rows().forEach((c) => { c.checked = false; }); refresh(); });
  refresh();

  /* ---------- Suppression ---------- */
  $('bulk-delete').addEventListener('click', (e) => {
    const n = picked().length;
    if (!n) { e.preventDefault(); return; }
    if (!confirm(`Supprimer définitivement ${n} étudiant(s) ? Cette action est irréversible.`)) e.preventDefault();
  });

  /* ---------- Modale : listes en cascade (toutes optionnelles) ---------- */
  const chain = [
    { name: 'section_id', type: 'sections', params: () => ({}) },
    { name: 'cycle_id',   type: 'cycles',   params: (v) => ({ section_id: v.section_id }) },
    { name: 'level_id',   type: 'levels',   params: (v) => ({ section_id: v.section_id, cycle_id: v.cycle_id }) },
    { name: 'class_id',   type: 'classes',  params: (v) => ({ section_id: v.section_id, cycle_id: v.cycle_id, level_id: v.level_id }) },
  ];
  const sel = (name) => form.elements[name];
  const values = () => Object.fromEntries(chain.map((c) => [c.name, sel(c.name).value]));
  const placeholder = (s, disabled) => { s.innerHTML = ''; s.add(new Option('— Inchangé —', '')); s.disabled = disabled; };

  async function load(i) {
    const c = chain[i], select = sel(c.name);
    const qs = new URLSearchParams({ type: c.type, ...c.params(values()) });
    select.disabled = true;
    try {
      const res = await fetch('../api/options.php?' + qs, { headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Erreur');
      placeholder(select, false);
      data.items.forEach((it) => select.add(new Option(it.label, it.id)));
    } catch (e) {
      placeholder(select, true);
      notify('Impossible de charger les ' + c.type + ' : ' + e.message);
    }
  }

  chain.forEach((c, i) => {
    sel(c.name).addEventListener('change', async () => {
      for (let j = i + 1; j < chain.length; j++) placeholder(sel(chain[j].name), true);
      if (i + 1 < chain.length && sel(c.name).value) await load(i + 1);
    });
  });

  let sectionsLoaded = false;
  function openDialog() {
    if (!picked().length) return;
    if (!sectionsLoaded) { sectionsLoaded = true; load(0); }
    dlg.showModal();
  }
  $('bulk-edit-open').addEventListener('click', openDialog);
  $('bulk-cancel').addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); }); // clic sur le fond

  /* ---------- Validation avant envoi (action=edit) ---------- */
  form.addEventListener('submit', (e) => {
    if (e.submitter?.value !== 'edit') return;
    const filled = chain.filter((c) => sel(c.name).value);
    if (filled.length && filled.length < chain.length) {
      e.preventDefault();
      notify('Parcours incomplet : complétez jusqu\'à la classe ou laissez tout vide.');
      sel(chain.find((c) => !sel(c.name).value).name).focus();
      return;
    }
    const nothing = !filled.length && sel('export_status').value === 'keep' && !sel('regen_password').checked;
    if (nothing) { e.preventDefault(); notify('Renseignez au moins un champ à modifier.'); return; }
    if (!confirm(`Appliquer ces modifications à ${picked().length} étudiant(s) ?`)) e.preventDefault();
  });
})();
