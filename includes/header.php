<?php
/**
 * En-tête commun du backoffice : <head>, sidebar rétractable, barre du haut et ouverture de <main id="app">.
 *
 * Variables attendues avant l'inclusion :
 *   $pageTitle  string  titre de l'onglet
 *   $active     string  entrée active : dashboard | inscriptions | cohorts | subjects | courses | plans | agenda | duplicates
 *
 * Seul le contenu de <main id="app"> est remplacé lors des navigations asynchrones (assets/admin-ajax.js).
 * La sidebar se replie en colonne d'icônes (choix mémorisé dans le navigateur) ; sur mobile, elle devient un tiroir.
 */
declare(strict_types=1);

$pageTitle ??= 'Backoffice';
$active    ??= '';

$i = fn(string $paths) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $paths . '</svg>';
$navLinks = [
    'dashboard'    => ['./index.php',           'Tableau de bord', $i('<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>')],
    'inscriptions' => ['./inscriptions.php',    'Inscriptions',    $i('<rect x="5" y="4" width="14" height="17" rx="2.5"/><path d="M9 4V2.8h6V4"/><path d="M9 10h6M9 14h6M9 18h3"/>')],
    'cohorts'      => ['./cohorts.php',         'Cohortes',        $i('<path d="M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="8" r="3.5"/><path d="M21 20v-1a4 4 0 0 0-3-3.87M15.5 4.1a3.5 3.5 0 0 1 0 6.8"/>')],
    'subjects'     => ['./cohort-subjects.php', 'Matières',        $i('<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/><path d="M8 7h7M8 11h5"/>')],
    'courses'      => ['./courses.php',         'Noms des cours',  $i('<path d="M20 12l-8 8-9-9V3h8z"/><circle cx="7.5" cy="7.5" r="1.5"/>')],
    'plans'        => ['./programmes.php',      'Cours',           $i('<path d="M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2z"/><path d="M22 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z"/>')],
    'agenda'       => ['./agenda.php',          'Agenda',          $i('<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>')],
    'duplicates'   => ['./duplicates.php',      'Doublons',        $i('<rect x="8" y="8" width="12" height="12" rx="2.5"/><path d="M16 8V6.5A2.5 2.5 0 0 0 13.5 4h-7A2.5 2.5 0 0 0 4 6.5v7A2.5 2.5 0 0 0 6.5 16H8"/>')],
];
$email = (string)($_SESSION['admin_email'] ?? '');
$name = $email !== '' ? ucfirst(preg_split('/[._@-]/', $email)[0]) : 'Administrateur';
$initials = mb_strtoupper(mb_substr($name, 0, 2));
$flashData = json_encode(flash_pull(), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= e(csrf_token()) ?>">
  <meta name="theme-color" content="#f4f4f2">
  <title><?= e($pageTitle) ?> — Backoffice</title>
  <script>try { if (localStorage.getItem('bo-side') === 'collapsed') document.documentElement.classList.add('side-collapsed'); } catch (e) {}</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('admin.css')) ?>">
</head>
<body class="bo">
  <div class="ajax-progress" aria-hidden="true"></div>
  <a class="skip-link" href="#app">Aller au contenu</a>

  <aside class="bo-side" id="sidebar" aria-label="Navigation principale">
    <div class="bo-side-head">
      <!-- Sidebar réduite : le logo sert à la déplier (cf. admin-ui.js) -->
      <a class="bo-brand" href="./index.php" aria-label="Tableau de bord" data-label="Déplier le menu">
        <span class="bo-logo"><?= ui_icon('layers') ?></span>
        <span class="bo-brand-text"><strong>Backoffice</strong><small>Inscriptions Moodle</small></span>
      </a>
      <button class="bo-collapse" type="button" data-side-collapse aria-label="Réduire le menu" title="Réduire / déplier le menu">
        <?= $i('<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16"/><path d="m15 10-2 2 2 2"/>') ?>
      </button>
    </div>
    <nav class="bo-nav">
      <?php foreach ($navLinks as $key => [$href, $label, $svg]): ?>
        <a class="bo-nav-link" href="<?= $href ?>" data-nav="<?= $key ?>" data-label="<?= e($label) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>>
          <?= $svg ?><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="bo-side-foot">
      <a class="bo-nav-link" href="./logout.php" data-no-async data-label="Déconnexion">
        <?= $i('<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>') ?><span>Déconnexion</span>
      </a>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-toggle></div>

  <div class="bo-body">
    <header class="bo-top">
      <button class="bo-icon-btn bo-burger" type="button" data-sidebar-toggle aria-label="Ouvrir le menu" aria-controls="sidebar"><?= $i('<path d="M4 7h16M4 12h16M4 17h16"/>') ?></button>
      <form class="bo-search" action="./inscriptions.php" method="get" role="search">
        <label class="sr-only" for="bo-search-q">Rechercher un étudiant</label>
        <input id="bo-search-q" type="search" name="q" placeholder="Rechercher un étudiant : nom, identifiant, cohorte…" autocomplete="off">
      </form>
      <div class="bo-actions">
        <a class="bo-icon-btn" href="./duplicates.php" data-nav="duplicates" title="Doublons" aria-label="Doublons" <?= $active === 'duplicates' ? 'aria-current="page"' : '' ?>>
          <?= $navLinks['duplicates'][2] ?>
        </a>
        <details class="bo-pop bo-profile">
          <summary aria-label="Menu du compte">
            <span class="bo-avatar" aria-hidden="true"><?= e($initials) ?></span>
            <span class="bo-profile-text"><strong><?= e($name) ?></strong><small><?= e($email) ?></small></span>
            <?= $i('<path d="m6 9 6 6 6-6"/>') ?>
          </summary>
          <div class="bo-pop-panel bo-menu">
            <p class="bo-menu-head"><strong><?= e($name) ?></strong><small><?= e($email) ?></small></p>
            <a href="./index.php"><?= $navLinks['dashboard'][2] ?>Tableau de bord</a>
            <a href="./duplicates.php"><?= $navLinks['duplicates'][2] ?>Doublons</a>
            <a href="./logout.php" data-no-async class="is-danger"><?= $i('<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>') ?>Déconnexion</a>
          </div>
        </details>
      </div>
    </header>

    <main class="bo-main admin" id="app" data-version="<?= e(assets_version()) ?>" data-active="<?= e($active) ?>" data-flash='<?= $flashData ?>'>
