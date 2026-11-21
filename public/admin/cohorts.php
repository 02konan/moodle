<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin();

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));

$where = "cohort <> ''";
$params = [];
if ($q !== '') {
    $where .= ' AND (cohort LIKE ? OR section_label LIKE ? OR class_label LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $params = [$like, $like, $like];
}
$st = $pdo->prepare(
    "SELECT cohort, MIN(section_label) section_label, MIN(class_label) class_label,
            COUNT(*) n, SUM(exported_at IS NULL) fresh
     FROM students WHERE $where GROUP BY cohort"
);
$st->execute($params);
$cohorts = $st->fetchAll();
usort($cohorts, fn($a, $b) => strnatcasecmp($a['cohort'], $b['cohort']));

$totalStudents = array_sum(array_column($cohorts, 'n'));
$totalFresh    = array_sum(array_column($cohorts, 'fresh'));
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cohortes — Backoffice</title>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body class="admin-body">

<header class="topbar">
  <a class="brand" href="./index.php"><span class="brand-dot"></span> Moodle Admin</a>
  <nav>
    <a class="link small" href="./index.php">Accueil</a>
    <a class="link small" href="./cohorts.php" aria-current="page">Cohortes</a>
    <a class="link small" href="./cohort-subjects.php">Matières par cohorte</a>
    <a class="link small" href="./courses.php">Noms des cours</a>
    <span class="nav-sep" aria-hidden="true"></span>
    <span class="nav-user small muted"><?= e($_SESSION['admin_email'] ?? 'Admin') ?></span>
    <a class="btn-logout small" href="./logout.php">Déconnexion</a>
  </nav>
</header>

<main class="admin">
  <header class="reveal page-head">
    <div>
      <h1>Cohortes</h1>
      <p class="muted">Liste des étudiants par cohorte, exportable en PDF (nom complet, nom d'utilisateur, mot de passe).</p>
    </div>
  </header>

  <section class="stats">
    <div class="stat reveal"><span class="stat-n"><?= count($cohorts) ?></span><span class="muted">Cohortes</span></div>
    <div class="stat reveal" style="--d:.06s"><span class="stat-n"><?= (int)$totalStudents ?></span><span class="muted">Étudiants</span></div>
    <div class="stat reveal" style="--d:.12s"><span class="stat-n"><?= (int)$totalFresh ?></span><span class="muted">Pas encore exportés</span></div>
  </section>

  <section class="panel reveal" style="--d:.18s">
    <form class="filters" method="get">
      <input type="search" name="q" placeholder="Rechercher une cohorte, une section, une classe" value="<?= e($q) ?>">
      <button class="btn" type="submit">Rechercher</button>
      <?php if ($q !== ''): ?><a class="btn" href="./cohorts.php">Tout afficher</a><?php endif; ?>
    </form>

    <div class="bulk-bar" id="cohort-bulk-bar" hidden>
      <span><strong id="cohort-bulk-count">0</strong> cohorte(s) sélectionnée(s)</span>
      <button class="btn" type="button" id="cohort-bulk-clear">Désélectionner</button>
      <span class="bulk-spacer"></span>
      <button class="btn btn-primary" type="button" id="cohort-bulk-export"><span class="btn-label">Exporter les listes PDF</span></button>
    </div>

    <div class="table-wrap">
      <table>
        <thead><tr>
          <th class="col-check"><input type="checkbox" id="cohort-check-all" aria-label="Tout sélectionner"></th>
          <th>Cohorte</th><th>Section / classe</th><th>Étudiants</th><th>Non exportés</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($cohorts as $c): $detail = './cohort.php?cohort=' . urlencode($c['cohort']); ?>
          <tr>
            <td class="col-check"><input type="checkbox" class="cohort-check" value="<?= e($c['cohort']) ?>" aria-label="Sélectionner <?= e($c['cohort']) ?>"></td>
            <td><a class="pill-link" href="<?= e($detail) ?>"><span class="pill"><?= e($c['cohort']) ?></span></a></td>
            <td class="small"><?= e($c['section_label']) ?><br><span class="muted"><?= e($c['class_label']) ?></span></td>
            <td><strong><?= (int)$c['n'] ?></strong></td>
            <td><?= (int)$c['fresh'] ? '<span class="tag tag-new">' . (int)$c['fresh'] . '</span>' : '<span class="muted">0</span>' ?></td>
            <td class="row-actions">
              <a class="btn" href="<?= e($detail) ?>">Détails</a>
              <button class="btn" type="button" data-pdf-cohort="<?= e($c['cohort']) ?>"><span class="btn-label">PDF</span></button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cohorts): ?>
          <tr><td colspan="6" class="empty"><?= $q !== '' ? 'Aucune cohorte ne correspond à cette recherche.' : 'Aucune cohorte : elles apparaissent dès qu\'un étudiant s\'inscrit.' ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<div id="toasts" class="toasts" aria-live="polite"></div>
<script src="../assets/app.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="../assets/cohort-pdf.js"></script>
</body>
</html>
