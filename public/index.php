<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= e(csrf_token()) ?>">
  <title>Création de votre compte étudiant</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./assets/app.css">
</head>
<body class="student-body">
  <div class="shell">
    <header class="hero reveal">
      <div class="brand"><span class="brand-dot"></span> Compte étudiant Moodle</div>
      <h1>Bienvenue&nbsp;! Créons votre accès.</h1>
      <p class="muted">Quelques choix, puis vos informations. Cela prend moins d'une minute.</p>
    </header>

    <div class="progress" aria-hidden="true"><span id="bar"></span></div>

    <section class="card reveal" style="--d:.08s">
      <div class="card-head">
        <button id="back" class="icon-btn ghost" type="button" aria-label="Retour" hidden>←</button>
        <div>
          <p class="eyebrow" id="eyebrow">Étape 1 sur 5</p>
          <h2 id="title">Votre section</h2>
        </div>
        <span id="cohort-pill" class="pill pill-accent" hidden></span>
      </div>

      <div id="chips" class="chips" hidden></div>

      <div id="stage" class="stage">
        <div id="choices" class="choices"></div>

        <form id="identity" class="form" hidden novalidate>
          <div class="grid-2">
            <label class="field"><span>Nom</span><input name="lastname" autocomplete="family-name" required maxlength="100"><small class="err"></small></label>
            <label class="field"><span>Prénom(s)</span><input name="firstname" autocomplete="given-name" required maxlength="100"><small class="err"></small></label>
          </div>
          <label class="field"><span>Nom d'utilisateur</span><input readonly name="username" autocomplete="off" autocapitalize="none" spellcheck="false" required minlength="3" maxlength="50" placeholder="Générer automatiquement"><small class="err"></small></label>
          <label class="field"><span>Adresse e-mail</span><input name="email" type="email" autocomplete="email" required maxlength="190"><small class="err"></small></label>
          <input class="hp" name="registration_reference" tabindex="-1" autocomplete="new-password" aria-hidden="true" readonly>
          <button id="submit" class="btn btn-primary" type="submit"><span class="btn-label">Créer mon compte</span></button>
        </form>

        <div id="success" class="success" hidden>
          <svg viewBox="0 0 52 52" class="tick" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M15 27l8 8 14-16"/></svg>
          <h3>Inscription enregistrée</h3>
          <p class="muted">Votre cohorte&nbsp;: <span class="pill pill-accent" id="done-cohort"></span></p>
          <p class="muted">Votre nom d'utilisateur&nbsp;: <code id="done-username"></code></p>
          <p class="muted">Votre mot de passe&nbsp;: <code id="done-password"></code></p>
          <p class="muted small">Notez ces informations : votre compte Moodle sera créé par l'administration avec ce nom et ce mot de passe.</p>
        </div>
      </div>
    </section>
  </div>

    <div id="toasts" class="toasts" aria-live="polite"></div>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="./assets/app.js"></script>

</body>
</html>
