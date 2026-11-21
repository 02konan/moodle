<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/filters.php';
require __DIR__ . '/../../src/moodle_courses.php';
require_admin();

$pdo = db();
[$where, $params] = student_filters($_GET);

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$st = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE $where");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$sql = "SELECT s.*
        FROM students s
        WHERE $where
        ORDER BY s.created_at DESC, s.id DESC
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$subjects = subjects_by_cohort($pdo);
$overrides = course_overrides($pdo);
$stats = $pdo->query('SELECT COUNT(*) total, SUM(exported_at IS NULL) fresh, COUNT(DISTINCT cohort) cohorts FROM students')->fetch();
$cohorts = $pdo->query('SELECT cohort, SUM(exported_at IS NULL) n FROM students GROUP BY cohort ORDER BY cohort')->fetchAll();
$query = fn(array $extra = []) => http_build_query(array_filter(array_merge($_GET, $extra), fn($v) => $v !== '' && $v !== null));
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backoffice — Inscriptions Moodle</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
  <header class="topbar">
  <a class="brand" href="./index.php">
    <span class="brand-dot"></span> Moodle Admin
  </a>
  <nav>
    <a class="link small" href="./index.php" aria-current="page">Accueil</a>
    <a class="link small" href="./cohort-subjects.php">Matières par cohorte</a>
    <a class="link small" href="./courses.php">Noms des cours</a>
    <span class="nav-sep" aria-hidden="true"></span>
    <span class="nav-user small muted"><?= e($_SESSION['admin_email'] ?? 'Admin') ?></span>
    <a class="btn-logout small" href="./logout.php">Déconnexion</a>
  </nav>
</header>


<main class="admin">
  <section class="stats">
    <div class="stat reveal"><span class="stat-n"><?= (int)$stats['total'] ?></span><span class="muted">Étudiants</span></div>
    <div class="stat reveal" style="--d:.06s"><span class="stat-n"><?= (int)$stats['fresh'] ?></span><span class="muted">Pas encore exportés</span></div>
    <div class="stat reveal" style="--d:.12s"><span class="stat-n"><?= (int)$stats['cohorts'] ?></span><span class="muted">Cohortes</span></div>
  </section>

  <section class="panel reveal" style="--d:.18s">
    <form class="filters" method="get">
      <input type="search" name="q" placeholder="Rechercher nom, e-mail, identifiant" value="<?= e($_GET['q'] ?? '') ?>">
      <select name="cohort">
        <option value="">Toutes les cohortes</option>
        <?php foreach ($cohorts as $c): ?>
          <option value="<?= e($c['cohort']) ?>" <?= ($_GET['cohort'] ?? '') === $c['cohort'] ? 'selected' : '' ?>><?= e($c['cohort']) ?> (<?= (int)$c['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <select name="status">
        <?php foreach (['' => 'Tous les statuts', 'new' => 'Non exportés', 'exported' => 'Déjà exportés'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($_GET['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Filtrer</button>
    </form>

    <form class="export" method="post" action="./export.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="q" value="<?= e($_GET['q'] ?? '') ?>">
      <input type="hidden" name="cohort" value="<?= e($_GET['cohort'] ?? '') ?>">
      <input type="hidden" name="status" value="<?= e($_GET['status'] ?? '') ?>">
      <label class="check"><input type="checkbox" name="mark" value="1" checked> Marquer comme exportés</label>
      <button class="btn btn-primary" type="submit" <?= $total === 0 ? 'disabled' : '' ?>>Exporter CSV (<?= $total ?>)</button>
    </form>

    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Identifiant</th><th>Nom</th><th>E-mail</th><th>Mot de passe</th><th>Cohorte</th><th>Cours / groupe Moodle</th><th>Export</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><code><?= e($r['username']) ?></code></td>
            <td><strong><?= e($r['lastname']) ?></strong> <?= e($r['firstname']) ?></td>
            <td><?= e($r['email']) ?></td>
            <td class="muted"><?= e($r['password']) ?></td>
            <td><span class="pill"><?= e($r['cohort']) ?></span></td>
            <td class="small">
              <?php $courses = moodle_courses_for($r['cohort'], $r['section_code'], $subjects, $overrides); ?>
              <?php if ($courses): ?>
                <?= implode('<br>', array_map('e', $courses)) ?><br><span class="muted"><?= e(moodle_group_name($r['cohort'])) ?></span>
              <?php else: ?>
                <a class="tag tag-new" href="./cohort-subjects.php?cohort=<?= e(urlencode($r['cohort'])) ?>">Aucune matière</a>
              <?php endif; ?>
            </td>
            <td><?= $r['exported_at'] ? '<span class="tag tag-done">Exporté</span>' : '<span class="tag tag-new">Nouveau</span>' ?></td>
            <td class="row-actions">
              <a class="icon-btn" href="./edit.php?<?= e(http_build_query(['id' => (int)$r['id'], 'back' => $query()])) ?>" title="Modifier" aria-label="Modifier">✎</a>
              <form method="post" action="./delete.php" onsubmit="return confirm('Supprimer cet étudiant ?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="back" value="<?= e($query()) ?>">
                <button class="icon-btn" title="Supprimer" aria-label="Supprimer">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="empty">Aucun étudiant pour ces critères.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pager">
        <?php if ($page > 1): ?><a class="btn" href="?<?= e($query(['page' => $page - 1])) ?>">←</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn" href="?<?= e($query(['page' => $page + 1])) ?>">→</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</main>

<div id="toasts" class="toasts" aria-live="polite"></div>
<script>window.__flash = <?= json_encode(flash_pull(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../assets/app.js"></script>
</body>
</html>
