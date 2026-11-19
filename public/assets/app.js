(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);

  /* ---------- Toasts ---------- */
  const toastBox = $('#toasts');
  function toast(message, type = 'info', ms = 4200) {
    if (!toastBox) return;
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.style.setProperty('--t', ms + 'ms');
    el.innerHTML = '<i></i><span></span><b class="life"></b>';
    el.querySelector('span').textContent = message;
    toastBox.appendChild(el);
    const close = () => { el.classList.add('out'); el.addEventListener('animationend', () => el.remove(), { once: true }); };
    el.addEventListener('click', close);
    setTimeout(close, ms);
  }
  window.toast = toast;
  (window.__flash || []).forEach((f, i) => setTimeout(() => toast(f.message, f.type), i * 250));

  /* ---------- Avertissement "choix de la classe" ---------- */
  function showClassNotice() {
    const text =
      "Veuillez sélectionner la classe indiquée sur votre reçu d'inscription. " +
      "Si vous choisissez une autre classe, vous serez inscrit dans celle-ci et vos notes ne seront pas prises en compte.";

    // Fallback si le CDN SweetAlert2 n'a pas pu être chargé
    if (typeof window.Swal === 'undefined') {
      alert(text);
      return Promise.resolve();
    }

    const warningIcon = `
      <svg class="swal-icon-inline text-warning" viewBox="0 0 24 24" width="18" height="18"
           fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>`;

    return Swal.fire({
      html: `
        <div class="swal-header">
          ${warningIcon}
          <h2 class="swal-title-inline">Attention au choix de la classe</h2>
        </div>
        <p class="m-0">
          Sélectionnez la classe <strong>indiquée sur votre reçu d'inscription</strong>.
          Si vous choisissez une autre classe, vous serez inscrit dans celle-ci et
          <strong>vos notes ne seront pas prises en compte</strong>.
        </p>
      `,
      confirmButtonText: "J'ai compris",
      width: 340,
      backdrop: true,
      buttonsStyling: false,
      allowOutsideClick: false,
      allowEscapeKey: false,
      focusConfirm: true,
      didOpen: () => document.querySelector('.swal2-container')?.classList.add('blur-bg'),
      customClass: {
        popup: 'swal-popup my-swal-font',
        htmlContainer: 'swal-html',
        actions: 'swal-actions',
        confirmButton: 'swal-btn swal-btn-warning',
      },
    });
  }

  /* ---------- Parcours étudiant ---------- */
  const stage = $('#choices');
  if (!stage) return; // pages admin / connexion

  const csrf = $('meta[name="csrf"]').content;
  const STEPS = [
    { key: 'section',  title: 'Votre section' },
    { key: 'cycle',    title: 'Votre cycle' },
    { key: 'level',    title: 'Votre niveau' },
    { key: 'class',    title: 'Votre classe - Veuillez sélectionner celle sur votre reçu d\'inscription' },
    { key: 'identity', title: 'Vos informations' },
  ];
  const state = { step: 0, section: null, cycle: null, level: null, klass: null };
  let classNoticeShown = false; // avertissement classe : une seule fois par visite

  const els = {
    bar: $('#bar'), eyebrow: $('#eyebrow'), title: $('#title'), back: $('#back'),
    chips: $('#chips'), pill: $('#cohort-pill'), choices: stage, form: $('#identity'),
    success: $('#success'), stageBox: $('#stage'),
  };

  const cleanUser = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
  let userTouched = false;
  const slug = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, '');

  // Extrait la filière d'un label de classe :
  // "LICENCE 1" -> "LICENCE" ; "GESTION DES ENTREPRISES 2" -> "GESTION DES ENTREPRISES"
  function getFiliere(label) {
    const parts = String(label || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2 && /^\d+$/.test(parts[parts.length - 1])) {
      return parts.slice(0, -1).join(' ');
    }
    return String(label || '').trim();
  }

  async function api(url, options) {
    let res;
    try { res = await fetch(url, options); }
    catch { throw Object.assign(new Error('Connexion impossible. Vérifiez votre réseau.'), { status: 0 }); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || 'Une erreur est survenue.'), { status: res.status, fields: data.fields });
    return data;
  }

  function animate(dir) {
    els.stageBox.classList.remove('anim-next', 'anim-prev');
    void els.stageBox.offsetWidth;
    els.stageBox.classList.add(dir === 'back' ? 'anim-prev' : 'anim-next');
  }

  function renderChips() {
    const parts = [state.section?.label, state.cycle?.label, state.level?.label, state.klass?.label].filter(Boolean);
    els.chips.innerHTML = '';
    parts.forEach((p) => { const c = document.createElement('span'); c.className = 'chip'; c.textContent = p; els.chips.appendChild(c); });
    els.chips.hidden = !parts.length;
    if (state.klass && state.section) {
      els.pill.textContent = `${slug(state.klass.label)}-${slug(state.section.code)}`;
      els.pill.hidden = false;
    } else els.pill.hidden = true;
  }

  function skeleton() {
    els.choices.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
  }

  function drawChoices(items, onPick) {
    els.choices.innerHTML = '';
    if (!items.length) {
      els.choices.innerHTML = '<p class="muted">Aucune option disponible pour le moment.</p>';
      return;
    }
    items.forEach((it, i) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'choice'; b.style.setProperty('--i', i);
      b.textContent = it.label;
      b.addEventListener('click', () => onPick(it));
      els.choices.appendChild(b);
    });
  }

  /* ---------- Filtre filière (étape classe) ---------- */
  function removeClassFilter() {
    const old = $('#class-filter');
    if (old) old.remove();
  }

  function renderClassStep(items) {
    removeClassFilter();

    if (!items.length) {
      els.choices.innerHTML = '<p class="muted">Aucune classe disponible pour le moment.</p>';
      return;
    }

    // Regroupement par filière
    const groups = new Map();
    items.forEach((it) => {
      const f = getFiliere(it.label);
      if (!groups.has(f)) groups.set(f, []);
      groups.get(f).push(it);
    });

    // Tri numérique des classes à l'intérieur d'une filière
    const byNumber = (a, b) => {
      const na = parseInt(String(a.label).trim().split(/\s+/).pop(), 10);
      const nb = parseInt(String(b.label).trim().split(/\s+/).pop(), 10);
      if (Number.isFinite(na) && Number.isFinite(nb)) return na - nb;
      return String(a.label).localeCompare(String(b.label), 'fr');
    };
    [...groups.values()].forEach((list) => list.sort(byNumber));

    const draw = (list) => {
      drawChoices(list, (it) => {
        state.klass = it;
        toast(`Cohorte : ${slug(it.label)}-${slug(state.section.code)}`, 'success', 3000);
        show(4);
      });
    };

    // Pas de filtre si une seule filière : inutile de surcharger
    if (groups.size <= 1) {
      draw(items);
      return;
    }

    // Construction du <select>
    const wrap = document.createElement('div');
    wrap.id = 'class-filter';
    wrap.className = 'filter-wrap';

    const lbl = document.createElement('label');
    lbl.setAttribute('for', 'filiere-select');
    lbl.textContent = 'Filière';

    const select = document.createElement('select');
    select.id = 'filiere-select';
    select.className = 'filter-select';

    const optAll = document.createElement('option');
    optAll.value = '';
    optAll.textContent = `Toutes les filières (${items.length})`;
    select.appendChild(optAll);

    [...groups.keys()]
      .sort((a, b) => a.localeCompare(b, 'fr'))
      .forEach((f) => {
        const o = document.createElement('option');
        o.value = f;
        o.textContent = `${f} (${groups.get(f).length})`;
        select.appendChild(o);
      });

    wrap.appendChild(lbl);
    wrap.appendChild(select);

    // Insère le select juste au-dessus de la zone des choix
    stage.parentNode.insertBefore(wrap, stage);

    select.addEventListener('change', () => {
      const chosen = select.value;
      draw(chosen ? groups.get(chosen) : items);
    });

    // Vue par défaut = toutes les classes
    draw(items);
  }

  async function show(step, dir = 'next') {
    state.step = step;
    const s = STEPS[step];

    // Nettoyage : le filtre n'appartient qu'à l'étape "classe"
    removeClassFilter();

    els.eyebrow.textContent = `Étape ${step + 1} sur ${STEPS.length}`;
    els.title.textContent = s.title;
    els.bar.style.width = `${((step + 1) / STEPS.length) * 100}%`;
    els.back.hidden = step === 0;
    renderChips();
    animate(dir);

    const isForm = s.key === 'identity';
    els.form.hidden = !isForm;
    els.choices.hidden = isForm;
    if (isForm) { setTimeout(() => els.form.lastname.focus(), 350); return; }

    skeleton();
    try {
      if (s.key === 'section') {
        const { items } = await api('./api/options.php?type=sections');
        drawChoices(items, (it) => { state.section = it; show(1); });
      } else if (s.key === 'cycle') {
        const { items } = await api(`./api/options.php?type=cycles&section_id=${state.section.id}`);
        drawChoices(items, (it) => { state.cycle = it; state.level = state.klass = null; show(2); });
      } else if (s.key === 'level') {
        const { items } = await api(`./api/options.php?type=levels&section_id=${state.section.id}&cycle_id=${state.cycle.id}`);
        drawChoices(items, (it) => { state.level = it; state.klass = null; show(3); });
      } else if (s.key === 'class') {
        const { items } = await api(`./api/options.php?type=classes&section_id=${state.section.id}&cycle_id=${state.cycle.id}&level_id=${state.level.id}`);

        // Avertissement (une seule fois par visite) AVANT d'afficher les classes
        if (!classNoticeShown) {
          classNoticeShown = true;
          await showClassNotice();
        }

        renderClassStep(items);
      }
    } catch (e) {
      els.choices.innerHTML = '<button type="button" class="btn" id="retry">Réessayer</button>';
      $('#retry').addEventListener('click', () => show(step, dir));
      toast(e.message, 'error');
    }
  }

  els.back.addEventListener('click', () => {
    const prev = state.step - 1;
    if (prev < 0) return;
    // On efface le choix qui sera refait
    [null, 'section', 'cycle', 'level', 'klass'].forEach((k, i) => { if (k && i > prev) state[k] = null; });
    show(prev, 'back');
  });

  /* ---------- Nom d'utilisateur : format forcé + suggestion ---------- */
  const uf = els.form.elements;

  // Base utilisée pour le dernier suffixe généré (évite de régénérer les chiffres à chaque frappe)
  let autoBase = '';

  const randomDigits = (n = 4) => {
    let s = '';
    for (let i = 0; i < n; i++) s += Math.floor(Math.random() * 10);
    return s;
  };

  uf.username.addEventListener('input', () => {
    uf.username.value = cleanUser(uf.username.value);
    userTouched = uf.username.value !== '';
  });

  const suggest = () => {
    if (userTouched) return;
    const first = cleanUser((uf.firstname.value.trim().split(/\s+/)[0]) || '');
    const last  = cleanUser(uf.lastname.value);
    // On garde de la marge pour les 4 chiffres (max total = 50)
    const base  = (first + last.slice(0, 1)).slice(0, 46);

    if (base === autoBase) return; // rien n'a changé, on garde le suffixe en place
    autoBase = base;

    if (!base) { uf.username.value = ''; return; }
    uf.username.value = base + randomDigits(4);
  };
  uf.lastname.addEventListener('input', suggest);
  uf.firstname.addEventListener('input', suggest);

  /* ---------- Envoi ---------- */
  function clearErrors() {
    els.form.querySelectorAll('.field').forEach((f) => { f.classList.remove('has-error'); f.querySelector('.err').textContent = ''; });
  }
  function setErrors(fields) {
    Object.entries(fields || {}).forEach(([name, msg]) => {
      const f = els.form.elements[name]?.closest('.field');
      if (f) { f.classList.add('has-error'); f.querySelector('.err').textContent = msg; }
    });
  }

  els.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    clearErrors();
    const btn = $('#submit');
    const fd = new FormData(els.form);
    const errs = {};
    if (!fd.get('lastname').trim()) errs.lastname = 'Requis.';
    if (!fd.get('firstname').trim()) errs.firstname = 'Requis.';
    if (cleanUser(fd.get('username')).length < 3) errs.username = '3 caractères minimum (minuscules et chiffres).';
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(fd.get('email').trim())) errs.email = 'Adresse e-mail invalide.';
    if (Object.keys(errs).length) { setErrors(errs); toast('Complétez les champs signalés.', 'error'); return; }

    btn.disabled = true; btn.classList.add('is-loading');
    try {
      const data = await api('./api/register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          lastname: fd.get('lastname'), firstname: fd.get('firstname'), username: cleanUser(fd.get('username')),
          email: fd.get('email'), registration_reference: fd.get('registration_reference'),
          section_id: state.section.id, cycle_id: state.cycle.id, level_id: state.level.id, class_id: state.klass.id,
        }),
      });
      if (data.ok !== true || !data.cohort || !data.username || !data.password) {
        throw new Error('Inscription non confirmée. Veuillez réessayer.');
      }
      $('#done-cohort').textContent = data.cohort;
      $('#done-username').textContent = data.username;
      $('#done-password').textContent = data.password;
      els.form.hidden = true; els.success.hidden = false;
      els.back.hidden = true; els.chips.hidden = true; els.pill.hidden = true;
      els.eyebrow.textContent = 'Terminé'; els.title.textContent = 'Merci !';
      els.bar.style.width = '100%';
      toast('Inscription enregistrée.', 'success');
    } catch (e) {
      // En cas de conflit sur le username, proposer un nouveau suffixe
      if (e.status === 409 && e.fields && e.fields.username) {
        uf.username.value = autoBase + randomDigits(4);
        userTouched = false; // permet de continuer à suggérer
      }
      setErrors(e.fields);
      toast(e.message, 'error');
    } finally {
      btn.disabled = false; btn.classList.remove('is-loading');
    }
  });

  show(0);
})();