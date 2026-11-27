// Backoffice : navigation et formulaires en asynchrone (fetch).
// Les liens et formulaires internes sont envoyés avec fetch ; seul <main id="app"> est remplacé.
// Réponses possibles du serveur : une page HTML (on remplace le contenu) ou du JSON
// { redirect, flash } (on affiche les messages puis on charge l'URL indiquée).
// Exclure un lien ou un formulaire : attribut data-no-async (téléchargements, déconnexion…).
(() => {
  'use strict';
  const app = () => document.getElementById('app');
  if (!app()) return;

  const notify = (list) => (window.notify ? window.notify(list) : (list || []).forEach((f) => window.toast?.(f.message, f.type)));
  let controller = null;

  /* ---------- Sidebar mobile ---------- */
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-sidebar-toggle]')) document.body.classList.toggle('sidebar-open');
  });

  /* ---------- Initialisation d'un contenu (au chargement et après chaque remplacement) ---------- */
  function pageLoaded(root) {
    try { notify(JSON.parse(root.dataset.flash || '[]')); } catch { /* données absentes */ }
    const active = root.dataset.active || '';
    document.querySelectorAll('[data-nav]').forEach((a) => {
      if (a.dataset.nav === active) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    document.dispatchEvent(new CustomEvent('app:load', { detail: { root } }));
  }

  /* ---------- Requête + remplacement du contenu ---------- */
  // soft : recherche en direct (curseur conservé) ; stay : retour sur la même page après une action (défilement conservé)
  const bootVersion = app()?.dataset.version || '';   // version des scripts chargés avec cet onglet
  let outdated = false;

  async function visit(url, { method = 'GET', body = null, history: hist = 'push', soft = false, stay = false } = {}) {
    if (outdated && method === 'GET') { window.location.href = url; return; }
    controller?.abort();
    controller = new AbortController();
    document.body.classList.add('is-loading');
    try {
      const res = await fetch(url, {
        method, body, signal: controller.signal, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', Accept: 'text/html, application/json' },
      });
      const type = res.headers.get('content-type') || '';

      if (type.includes('application/json')) {
        const data = await res.json();
        notify(data.flash);
        if (data.error) window.toast?.(data.error, 'error');
        if (data.redirect) {
          const target = new URL(data.redirect, res.url || url);
          const same = target.pathname === location.pathname && target.search === location.search;
          return visit(target.href, same ? { history: 'replace', stay: true } : { history: 'push' });
        }
        return;
      }

      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const next = doc.getElementById('app');
      if (!next) { window.location.href = res.url || url; return; }   // page hors backoffice (connexion…)
      // Nouvelle version déployée depuis l'ouverture de l'onglet : les scripts en mémoire sont périmés,
      // on recharge entièrement (GET) ; après un envoi de formulaire, au prochain changement de page.
      if (next.dataset.version && next.dataset.version !== bootVersion) {
        if (method === 'GET') { window.location.href = res.url || url; return; }
        outdated = true;
      }

      swap(next, doc.title, { soft, stay });
      if (hist === 'push') window.history.pushState({ async: true }, '', res.url || url);
      else if (hist === 'replace') window.history.replaceState({ async: true }, '', res.url || url);
    } catch (err) {
      if (err.name === 'AbortError') return;
      window.toast?.('Connexion impossible, réessayez.', 'error');
    } finally {
      document.body.classList.remove('is-loading');
    }
  }

  let lastFormKey = null;   // formulaire envoyé (data-key) : son champ reprend le curseur après « stay »

  function swap(next, title, { soft = false, stay = false } = {}) {
    const scrollY = window.scrollY;
    // Conserve le champ en cours de saisie (recherche en direct)
    const focused = document.activeElement;
    const keep = soft && focused?.name && app().contains(focused)
      ? { name: focused.name, start: focused.selectionStart, end: focused.selectionEnd } : null;

    const node = document.importNode(next, true);
    if (soft || stay) node.classList.add('no-anim');
    app().replaceWith(node);
    document.title = title;
    document.body.classList.remove('sidebar-open');
    document.querySelectorAll('details.bo-pop[open]').forEach((d) => { d.open = false; });

    // Les <script> insérés par innerHTML ne s'exécutent pas : on les recrée
    node.querySelectorAll('script').forEach((old) => {
      const s = document.createElement('script');
      [...old.attributes].forEach((a) => s.setAttribute(a.name, a.value));
      s.textContent = old.textContent;
      old.replaceWith(s);
    });

    if (keep) {
      const el = node.querySelector(`[name="${CSS.escape(keep.name)}"]`);
      if (el) { el.focus(); try { el.setSelectionRange(keep.start, keep.end); } catch { /* type sans sélection */ } }
    } else if (stay) {
      window.scrollTo({ top: scrollY });
      const f = lastFormKey && node.querySelector(`form[data-key="${CSS.escape(lastFormKey)}"] input[type="text"]`);
      if (f) f.focus({ preventScroll: true });
    } else {
      window.scrollTo({ top: 0 });
    }
    lastFormKey = null;
    pageLoaded(node);
  }

  /* ---------- Interception des liens ---------- */
  const internal = (url) => url.origin === location.origin && /\/admin\/[^/]*$/.test(url.pathname);

  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.target || a.hasAttribute('download') || a.hasAttribute('data-no-async')) return;
    const url = new URL(a.href, location.href);
    if (!internal(url) || (url.hash && url.pathname === location.pathname && url.search === location.search)) return;
    e.preventDefault();
    visit(url.href);
  });

  /* ---------- Interception des formulaires ---------- */
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || form.hasAttribute('data-no-async') || form.target) return;
    const url = new URL(form.getAttribute('action') || location.href, location.href);
    if (!internal(url)) return;
    e.preventDefault();

    const data = new FormData(form, e.submitter || undefined);
    if ((form.method || 'get').toLowerCase() === 'get') {
      url.search = new URLSearchParams([...data].filter(([, v]) => v !== '')).toString();
      const live = form.hasAttribute('data-live-form');   // recherche en direct : pas d'entrée d'historique par frappe
      visit(url.href, { soft: live, history: live ? 'replace' : 'push' });
    } else {
      lastFormKey = form.dataset.key || null;
      // POST : en cas de réponse HTML (erreurs de saisie), l'adresse affichée ne change pas
      visit(url.href, { method: 'POST', body: data, history: 'none' });
    }
  });

  /* ---------- Recherche et filtres en direct ---------- */
  let timer = null;
  document.addEventListener('input', (e) => {
    const field = e.target.closest('[data-live]');
    if (!field) return;
    clearTimeout(timer);
    timer = setTimeout(() => field.form?.requestSubmit(), field.list ? 150 : 350);
  });

  // Listes de suggestions validées au choix (ou à la sortie du champ) plutôt qu'à chaque frappe
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-submit-on-change]')) e.target.form?.requestSubmit();
  });

  // « Tout cocher » : <button data-check-all="nom[]"> coche / décoche les cases de ce nom dans son formulaire
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-check-all]');
    if (!btn) return;
    const scope = (btn.dataset.checkScope && btn.closest(btn.dataset.checkScope)) || btn.closest('form') || document;
    const boxes = [...scope.querySelectorAll(`input[type=checkbox][name="${CSS.escape(btn.dataset.checkAll)}"]`)];
    const all = boxes.every((b) => b.checked);
    boxes.forEach((b) => { b.checked = !all; });
    updateCounts(btn.closest('form'));
  });

  // Filtre de puces : <input data-chip-filter> masque les .chip-check[data-chip-text] du même bloc qui ne correspondent pas
  document.addEventListener('input', (e) => {
    const input = e.target.closest('[data-chip-filter]');
    if (!input) return;
    const q = input.value.trim().toLowerCase();
    const scope = input.closest('details, section, form') || document;
    scope.querySelectorAll('.chip-check[data-chip-text]').forEach((c) => c.classList.toggle('is-filtered', q !== '' && !c.dataset.chipText.includes(q)));
  });

  // Entrée dans le filtre : ne soumet pas le formulaire
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.closest('[data-chip-filter]')) e.preventDefault();
  });

  // Compteur « n sélectionné(s) » : <span data-count-for="nom[]">
  function updateCounts(form) {
    (form || document).querySelectorAll('[data-count-for]').forEach((el) => {
      const n = (form || document).querySelectorAll(`input[type=checkbox][name="${CSS.escape(el.dataset.countFor)}"]:checked`).length;
      el.textContent = `${n} sélectionné${n > 1 ? 's' : ''}`;
      el.hidden = n === 0;
    });
  }
  document.addEventListener('change', (e) => { if (e.target.matches('input[type=checkbox]')) updateCounts(e.target.form); });

  window.addEventListener('popstate', () => visit(location.href, { history: 'none' }));

  window.appVisit = visit;
  pageLoaded(app());
})();
