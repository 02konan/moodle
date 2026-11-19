<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/moodle_courses.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('Requête invalide.', 'error');
    } else {
        try {
            $courses = parse_course_list((string)($_POST['courses'] ?? ''));
            $aliases = parse_aliases((string)($_POST['aliases'] ?? ''));
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM moodle_courses');
            $ins = $pdo->prepare('INSERT IGNORE INTO moodle_courses (shortname) VALUES (?)');
            foreach ($courses as $c) $ins->execute([$c]);
            $pdo->exec('DELETE FROM filiere_aliases');
            $ins = $pdo->prepare('INSERT INTO filiere_aliases (filiere, course_filiere) VALUES (?, ?)');
            foreach ($aliases as $from => $to) $ins->execute([$from, $to]);
            $pdo->commit();
            flash(count($courses) . ' cours et ' . count($aliases) . ' alias enregistrés.', 'success');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[moodle-courses] ' . $ex->getMessage());
            flash("Enregistrement impossible (la table existe-t-elle ? voir sql/2026-11-moodle-courses.sql).", 'error');
        }
    }
    header('Location: ./moodle-courses.php');
    exit;
}

$catalogue = course_catalogue($pdo);
$aliases   = filiere_aliases($pdo);
$aliasText = implode("\n", array_map(fn($k, $v) => "$k = $v", array_keys($aliases), $aliases));

// Aperçu : ce que l'export produira pour chaque cohorte
$cohorts = $pdo->query(
    "SELECT cohort, MIN(class_label) class_label, COUNT(*) n
     FROM students WHERE cohort <> '' GROUP BY cohort ORDER BY cohort"
)->fetchAll();
$missing = 0;
foreach ($cohorts as &$c) {
    $c['prefix']  = class_course_prefix($c['class_label'], $aliases);
    $c['courses'] = courses_for_class($c['class_label'], $catalogue, $aliases);
    if (!$c['courses']) $missing++;
}
unset($c);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cours Moodle — Backoffice</title>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body class="admin-body">

<header class="topbar">
  <a class="brand" href="./index.php"><span class="brand-dot"></span> Moodle Admin</a>
  <nav>
    <a class="link small" href="./index.php">Accueil</a>
    <a class="link small" href="./cohort-subjects.php">Matières par cohorte</a>
    <a class="link small" href="./moodle-courses.php" aria-current="page">Cours &amp; groupes</a>
    <span class="nav-sep" aria-hidden="true"></span>
    <span class="nav-user small muted"><?= e($_SESSION['admin_email'] ?? 'Admin') ?></span>
    <a class="btn-logout small" href="./logout.php">Déconnexion</a>
  </nav>
</header>

<main class="admin">
  <header class="reveal page-head">
    <div>
      <h1>Cours &amp; groupes Moodle</h1>
      <p class="muted">À l'export, chaque étudiant est inscrit dans les cours de sa filière et placé dans le groupe
        <code>GP/&lt;cohorte&gt;</code>. Moodle crée le groupe dans le cours s'il n'existe pas.</p>
    </div>
  </header>

  <section class="panel reveal">
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field">
        <span>Noms abrégés des cours Moodle (un par ligne ou séparés par des virgules, recopiés exactement)</span>
        <textarea name="courses" rows="10" spellcheck="false"
          placeholder="BTS 2 FCGE: MATHÉMATIQUES GÉNÉRALES&#10;BTS 2 IDA: MATHÉMATIQUES FINANCIÈRES &amp; RO"><?= e(implode("\n", $catalogue)) ?></textarea>
      </label>
      <label class="field">
        <span>Alias de filière, si le code de la classe diffère de celui du cours (un par ligne : <code>FC = FCGE</code>)</span>
        <textarea name="aliases" rows="3" spellcheck="false" placeholder="FC = FCGE"><?= e($aliasText) ?></textarea>
      </label>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </form>
  </section>

  <section class="panel reveal">
    <h2>Aperçu par cohorte<?= $missing ? ' — ' . $missing . ' sans cours' : '' ?></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cohorte</th><th>Préfixe recherché</th><th>Groupe</th><th>Cours (course1, course2…)</th></tr></thead>
        <tbody>
        <?php foreach ($cohorts as $c): ?>
          <tr>
            <td><span class="pill"><?= e($c['cohort']) ?></span> <span class="muted small"><?= (int)$c['n'] ?></span></td>
            <td><code><?= e($c['prefix']) ?></code></td>
            <td><code><?= e(moodle_group_name($c['cohort'])) ?></code></td>
            <td>
              <?php if ($c['courses']): ?>
                <?= implode('<br>', array_map('e', $c['courses'])) ?>
              <?php else: ?>
                <span class="tag tag-new">Aucun cours trouvé</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cohorts): ?>
          <tr><td colspan="4" class="empty">Aucune cohorte pour l'instant.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<div id="toasts" class="toasts" aria-live="polite"></div>
<script>window.__flash = <?= json_encode(flash_pull(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../assets/app.js"></script>
</body>
</html>
