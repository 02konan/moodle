<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/moodle_courses.php';
require_admin();

$pdo = db();
$cohort = trim((string)($_GET['cohort'] ?? ''));

$st = $pdo->prepare('SELECT * FROM students WHERE cohort = ? ORDER BY lastname, firstname');
$st->execute([$cohort]);
$students = $st->fetchAll();
if ($cohort === '' || !$students) {
    flash('Cohorte introuvable.', 'error');
    redirect('./cohorts.php');
}

$first   = $students[0];
$fresh   = count(array_filter($students, fn($s) => $s['exported_at'] === null));
$courses = moodle_courses_for($cohort, $first['section_code'], subjects_by_cohort($pdo), course_overrides($pdo));
$back    = http_build_query(['cohort' => $cohort]);   // retour vers la liste filtrée après modification
$pageTitle = $cohort;
$active = 'cohorts';
require __DIR__ . '/../../includes/header.php';
?>

  <header class="reveal page-head">
    <div>
      <p class="muted small"><a class="link" href="./cohorts.php">← Toutes les cohortes</a></p>
      <h1><?= e($cohort) ?></h1>
      <p class="muted"><?= e($first['section_label']) ?> · <?= e($first['cycle_label']) ?> · <?= e($first['level_label']) ?> · <?= e($first['class_label']) ?></p>
    </div>
    <button class="btn btn-primary" type="button" data-pdf-cohort="<?= e($cohort) ?>"><span class="btn-label">Exporter la liste PDF</span></button>
  </header>

  <section class="stats">
    <div class="stat reveal"><span class="stat-n"><?= count($students) ?></span><span class="muted">Étudiants</span></div>
    <div class="stat reveal" style="--d:.06s"><span class="stat-n"><?= $fresh ?></span><span class="muted">Pas encore exportés</span></div>
    <div class="stat reveal" style="--d:.12s">
      <span class="muted small">Moodle</span>
      <span class="small"><strong><?= e(moodle_group_name($cohort)) ?></strong></span>
      <span class="small muted"><?= $courses ? count($courses) . ' cours' : '<a class="link" href="./cohort-subjects.php?cohort=' . e(urlencode($cohort)) . '">Aucune matière</a>' ?></span>
    </div>
  </section>

  <?php if ($courses): ?>
    <section class="panel reveal">
      <h2>Cours Moodle</h2>
      <p class="small"><?= implode('<br>', array_map('e', $courses)) ?></p>
    </section>
  <?php endif; ?>

  <section class="panel reveal" style="--d:.18s">
    <h2>Étudiants</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>N°</th><th>Nom complet</th><th>Nom d'utilisateur</th><th>Mot de passe</th><th>E-mail</th><th>Export</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($students as $i => $s): ?>
          <tr>
            <td class="muted"><?= $i + 1 ?></td>
            <td><strong><?= e($s['lastname']) ?></strong> <?= e($s['firstname']) ?></td>
            <td><code><?= e($s['username']) ?></code></td>
            <td><code><?= e($s['password']) ?></code></td>
            <td class="small"><?= e($s['email']) ?></td>
            <td><?= $s['exported_at'] ? '<span class="tag tag-done">Exporté</span>' : '<span class="tag tag-new">Nouveau</span>' ?></td>
            <td class="row-actions">
              <a class="icon-btn" href="./edit.php?<?= e(http_build_query(['id' => (int)$s['id'], 'back' => $back])) ?>" title="Modifier" aria-label="Modifier"><?= ui_icon('pencil') ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
