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
  <script src="../assets/app.js"></script>
  <script src="../assets/admin-ajax.js"></script>
  <script src="../assets/admin-bulk.js"></script>
  <script src="../assets/admin-edit.js"></script>
  <script src="../assets/cohort-subjects.js"></script>
  <script src="../assets/cohort-pdf.js"></script>
</body>
</html>
