<?php
/**
 * En-tête commun du backoffice : <head>, sidebar et ouverture de <main id="app">.
 *
 * Variables attendues avant l'inclusion :
 *   $pageTitle  string  titre de l'onglet
 *   $active     string  entrée active de la sidebar : students | cohorts | subjects | courses | duplicates
 *
 * Seul le contenu de <main id="app"> est remplacé lors des navigations asynchrones (assets/admin-ajax.js).
 */
declare(strict_types=1);

$pageTitle ??= 'Backoffice';
$active    ??= '';

$sideLinks = [
    'students' => ['./index.php',           'Accueil',              '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>'],
    'cohorts'  => ['./cohorts.php',         'Cohortes',             '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M15 14.5c3 0 6 1.8 6 5.5"/>'],
    'subjects' => ['./cohort-subjects.php', 'Matières par cohorte', '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/><path d="M8 7h7"/>'],
    'courses'  => ['./courses.php',         'Noms des cours',       '<path d="M20 12l-8 8-9-9V3h8z"/><circle cx="7.5" cy="7.5" r="1.5"/>'],
    'duplicates' => ['./duplicates.php',    'Doublons',             '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>'],
];
$flashData = json_encode(flash_pull(), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle) ?> — Backoffice</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="admin-layout">
  <div class="ajax-progress" aria-hidden="true"></div>

  <header class="mobile-bar">
    <button class="icon-btn ghost" type="button" data-sidebar-toggle aria-label="Ouvrir le menu" aria-controls="sidebar">☰</button>
    <a class="brand" href="./index.php"><span class="brand-dot"></span> Moodle Admin</a>
  </header>

  <aside class="sidebar" id="sidebar">
    <a class="brand" href="./index.php"><span class="brand-dot"></span> Moodle Admin</a>
    <nav class="side-nav" aria-label="Navigation principale">
      <?php foreach ($sideLinks as $key => [$href, $label, $icon]): ?>
        <a class="side-link" href="<?= $href ?>" data-nav="<?= $key ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <span class="side-user" title="<?= e($_SESSION['admin_email'] ?? '') ?>"><?= e($_SESSION['admin_email'] ?? 'Administrateur') ?></span>
      <a class="side-link" href="./logout.php" data-no-async>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/></svg><span>Déconnexion</span>
      </a>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-toggle></div>

  <main class="admin" id="app" data-active="<?= e($active) ?>" data-flash='<?= $flashData ?>'>
