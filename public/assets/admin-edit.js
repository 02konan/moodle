// Modification d'un étudiant : listes en cascade section → cycle → niveau → classe.
// Réinitialisé à chaque chargement de contenu (événement « app:load » d'admin-ajax.js).
(() => {
  'use strict';
  function init() {
  const form = document.getElementById('edit-form');
  if (!form || form.dataset.ready) return;
  form.dataset.ready = '1';

  const chain = [
    { name: 'section_id', type: 'sections', params: () => ({}) },
    { name: 'cycle_id',   type: 'cycles',   params: (v) => ({ section_id: v.section_id }) },
    { name: 'level_id',   type: 'levels',   params: (v) => ({ section_id: v.section_id, cycle_id: v.cycle_id }) },
    { name: 'class_id',   type: 'classes',  params: (v) => ({ section_id: v.section_id, cycle_id: v.cycle_id, level_id: v.level_id }) },
  ];
  const sel = (name) => form.elements[name];
  const values = () => Object.fromEntries(chain.map((c) => [c.name, sel(c.name).value]));

  async function load(i, keep) {
    const c = chain[i];
    const select = sel(c.name);
    const wanted = keep ? select.dataset.current : '';
    const qs = new URLSearchParams({ type: c.type, ...c.params(values()) });
    select.disabled = true;
    try {
      const res = await fetch('../api/options.php?' + qs, { headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Erreur');
      select.innerHTML = '';
      select.add(new Option('— Choisir —', ''));
      data.items.forEach((it) => select.add(new Option(it.label, it.id, false, String(it.id) === wanted)));
      if (wanted && select.value !== wanted) select.value = '';
    } catch (e) {
      if (keep) return; // on garde la valeur actuelle affichée par le serveur
      select.innerHTML = '';
      select.add(new Option('Indisponible', ''));
      window.toast && window.toast('Impossible de charger les ' + c.type + ' : ' + e.message, 'error');
    } finally {
      select.disabled = false;
    }
  }

  function clearFrom(i) {
    for (let j = i; j < chain.length; j++) {
      const s = sel(chain[j].name);
      s.innerHTML = '';
      s.add(new Option('— Choisir —', ''));
    }
  }

  chain.forEach((c, i) => {
    sel(c.name).addEventListener('change', async () => {
      if (i + 1 >= chain.length) return;
      clearFrom(i + 1);
      if (sel(c.name).value) await load(i + 1, false);
    });
  });

  // Chargement initial en conservant les choix actuels
  (async () => { for (let i = 0; i < chain.length; i++) await load(i, true); })();

  form.addEventListener('submit', (e) => {
    const missing = chain.find((c) => !sel(c.name).value);
    if (missing) {
      e.preventDefault();
      window.toast && window.toast('Complétez le parcours jusqu\'à la classe.', 'error');
      sel(missing.name).focus();
    }
  });
  }

  document.addEventListener('app:load', init);
  init();
})();
