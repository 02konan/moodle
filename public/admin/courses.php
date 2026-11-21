<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/moodle_courses.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('Session expirée, rechargez la page.', 'error');
    } else {
        $auto  = (array)($_POST['auto'] ?? []);
        $names = (array)($_POST['name'] ?? []);
        $upsert = $pdo->prepare('INSERT INTO course_overrides (auto_name, moodle_name) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE moodle_name = VALUES(moodle_name)');
        $delete = $pdo->prepare('DELETE FROM course_overrides WHERE auto_name = ?');
        $changed = 0;
        $pdo->beginTransaction();
        foreach ($auto as $i => $a) {
            $a = (string)$a;
            $n = preg_replace('/\s+/u', ' ', trim((string)($names[$i] ?? ''))) ?? '';
            if ($a === '' || mb_strlen($n) > 255) continue;
            if ($n === '' || $n === $a) {
                $delete->execute([$a]);              // vide ou identique : retour au nom automatique
                $changed += $delete->rowCount();
            } else {
                $upsert->execute([$a, $n]);
                $changed += $upsert->rowCount() ? 1 : 0;
            }
        }
        $pdo->commit();
        flash($changed ? "$changed nom(s) de cours mis à jour." : 'Aucune modification.', $changed ? 'success' : 'info');
    }
    header('Location: ./courses.php' . (isset($_POST['q']) && $_POST['q'] !== '' ? '?q=' . urlencode((string)$_POST['q']) : ''));
    exit;
}

/* Tous les cours générés à partir des cohortes existantes et de leurs matières */
$subjects  = subjects_by_cohort($pdo);
$overrides = course_overrides($pdo);
$cohorts   = $pdo->query("SELECT cohort, MIN(section_code) section_code, COUNT(*) n
                          FROM students WHERE cohort <> '' GROUP BY cohort ORDER BY cohort")->fetchAll();
$courses = [];   // nom auto => ['cohorts' => [...], 'students' => n]
foreach ($cohorts as $c) {
    foreach (moodle_auto_courses_for($c['cohort'], $c['section_code'], $subjects) as $auto) {
        $courses[$auto]['cohorts'][] = $c['cohort'];
        $courses[$auto]['students'] = ($courses[$auto]['students'] ?? 0) + (int)$c['n'];
    }
}
ksort($courses, SORT_NATURAL | SORT_FLAG_CASE);

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $needle = ascii($q);
    $courses = array_filter($courses, fn($v, $k) => str_contains(ascii($k . ' ' . ($overrides[$k] ?? '')), $needle), ARRAY_FILTER_USE_BOTH);
}
$nModified = count(array_intersect_key($overrides, $courses));
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Noms des cours — Backoffice</title>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body class="admin-body">

<header class="topbar">
  <a class="brand" href="./index.php"><span class="brand-dot"></span> Moodle Admin</a>
  <nav>
    <a class="link small" href="./index.php">Accueil</a>
    <a class="link small" href="./cohorts.php">Cohortes</a>
    <a class="link small" href="./cohort-subjects.php">Matières par cohorte</a>
    <a class="link small" href="./courses.php" aria-current="page">Noms des cours</a>
    <span class="nav-sep" aria-hidden="true"></span>
    <span class="nav-user small muted"><?= e($_SESSION['admin_email'] ?? 'Admin') ?></span>
    <a class="btn-logout small" href="./logout.php">Déconnexion</a>
  </nav>
</header>

<main class="admin">
  <header class="reveal page-head">
    <div>
      <h1>Noms des cours Moodle</h1>
      <p class="muted">Les noms sont générés automatiquement (filière + matière). Si un cours porte un autre nom abrégé dans Moodle,
        corrigez-le ici : l'export CSV utilisera ce nom. Videz le champ pour revenir au nom automatique.</p>
    </div>
  </header>

  <section class="panel reveal">
    <form method="get" class="filters">
      <input type="search" name="q" placeholder="Rechercher un cours (ex. FC, mathématiques)" value="<?= e($q) ?>">
      <button class="btn" type="submit">Rechercher</button>
      <?php if ($q !== ''): ?><a class="btn" href="./courses.php">Tout afficher</a><?php endif; ?>
    </form>

    <?php if (!$courses): ?>
      <p class="empty"><?= $q !== '' ? 'Aucun cours ne correspond à cette recherche.'
        : 'Aucun cours pour l\'instant : ajoutez des matières aux cohortes dans « Matières par cohorte ».' ?></p>
    <?php else: ?>
      <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <p class="muted small"><?= count($courses) ?> cours · <?= $nModified ?> nom(s) personnalisé(s)</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Nom généré</th><th>Nom abrégé dans Moodle (utilisé à l'export)</th><th>Cohortes</th></tr></thead>
            <tbody>
            <?php foreach ($courses as $auto => $info): $custom = $overrides[$auto] ?? null; ?>
              <tr>
                <td class="small"><?= e($auto) ?></td>
                <td>
                  <input type="hidden" name="auto[]" value="<?= e($auto) ?>">
                  <input name="name[]" maxlength="255" spellcheck="false" value="<?= e($custom ?? $auto) ?>"
                         aria-label="Nom Moodle pour <?= e($auto) ?>">
                  <?php if ($custom !== null): ?><span class="tag tag-new">Personnalisé</span><?php endif; ?>
                </td>
                <td class="small">
                  <?php foreach ($info['cohorts'] as $c): ?><span class="pill"><?= e($c) ?></span> <?php endforeach; ?>
                  <span class="muted">· <?= (int)$info['students'] ?> étudiant(s)</span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="export"><button class="btn btn-primary" type="submit">Enregistrer les noms</button></div>
      </form>
    <?php endif; ?>
  </section>
</main>

<div id="toasts" class="toasts" aria-live="polite"></div>
<script>window.__flash = <?= json_encode(flash_pull(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../assets/app.js"></script>
</body>
</html>
