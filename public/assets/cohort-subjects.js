// Matières par cohorte : remplit le téléphone quand un enseignant connu est saisi.
// Les téléphones connus sont fournis par l'attribut data-teacher-phones du formulaire.
(() => {
  'use strict';
  function init() {
    const form = document.getElementById('subject-form');
    if (!form || form.dataset.ready) return;
    form.dataset.ready = '1';

    let teacherPhones = {};
    try { teacherPhones = JSON.parse(form.dataset.teacherPhones || '{}'); } catch { /* données absentes */ }
    const teacherInput = form.querySelector('input[name="teacher"]');
    const phoneInput   = form.querySelector('input[name="phone"]');
    if (!teacherInput || !phoneInput) return;

    // Le téléphone saisi à la main n'est jamais écrasé
    let phoneTouched = phoneInput.value.trim() !== '';
    phoneInput.addEventListener('input', () => { phoneTouched = phoneInput.value.trim() !== ''; });

    teacherInput.addEventListener('input', () => {
      if (phoneTouched) return;
      const name = teacherInput.value.trim();
      if (name === '') { phoneInput.value = ''; return; }
      const found = Object.keys(teacherPhones).find((k) => k.localeCompare(name, 'fr', { sensitivity: 'base' }) === 0);
      if (found) phoneInput.value = teacherPhones[found] || '';
    });
  }

  document.addEventListener('app:load', init);
  init();
})();
