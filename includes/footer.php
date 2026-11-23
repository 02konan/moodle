<?php
/**
 * Pied de page commun du backoffice : ferme <main id="app"> et charge les scripts.
 * Les scripts sont chargés une seule fois ; ils se réinitialisent à chaque contenu
 * chargé en asynchrone (événement « app:load »).
 */
declare(strict_types=1);
?>
  </main>

  <div id="toasts" class="toasts" aria-live="polite"></div>
  <script src="<?= e(asset('app.js')) ?>"></script>
  <script src="<?= e(asset('admin-ajax.js')) ?>"></script>
  <script src="<?= e(asset('admin-bulk.js')) ?>"></script>
  <script src="<?= e(asset('admin-edit.js')) ?>"></script>
  <script src="<?= e(asset('cohort-subjects.js')) ?>"></script>
  <script src="<?= e(asset('cohort-pdf.js')) ?>"></script>
  <script src="<?= e(asset('moodle-sync.js')) ?>"></script>
</body>
</html>
