<?php
declare(strict_types=1);
// Doublons : étudiants regroupés par nom (majuscules, accents, ponctuation et ordre des mots ignorés).
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/students.php';
require_admin();

$pdo = db();
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$self = './duplicates.php' . ($q !== '' ? '?q=' . urlencode($q) : '');

/* ---------- Actions : garder un étudiant du groupe, ou supprimer la sélection ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('Session expirée, rechargez la page.', 'error');
        redirect($self);
    }
    $group = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['group'] ?? [])), fn($i) => $i > 0)));
    if (count($group) < 2 || count($group) > 100) { flash('Groupe invalide.', 'error'); redirect($self); }

    // On revérifie côté serveur que les étudiants portent toujours le même nom
    $in = implode(',', array_fill(0, count($group), '?'));
    $st = $pdo->prepare("SELECT id, lastname, firstname FROM students WHERE id IN ($in)");
    $st->execute($group);
    $keys = [];
    foreach ($st->fetchAll() as $r) $keys[(int)$r['id']] = name_key($r['lastname'], $r['firstname']);
    if (count(array_unique($keys)) !== 1) { flash('Ce groupe a changé entre-temps : vérifiez à nouveau.', 'error'); redirect($self); }

    if (isset($_POST['keep'])) {
        $keep = (int)$_POST['keep'];
        if (!isset($keys[$keep])) { flash('Étudiant introuvable.', 'error'); redirect($self); }
        $remove = array_values(array_diff(array_keys($keys), [$keep]));
    } else {
        $remove = array_values(array_intersect(array_map('intval', (array)($_POST['ids'] ?? [])), array_keys($keys)));
        if (!$remove) { flash('Cochez au moins un étudiant à supprimer.', 'error'); redirect($self); }
        if (count($remove) === count($keys)) { flash('Gardez au moins un étudiant du groupe.', 'error'); redirect($self); }
    }
    if ($remove) {
        $in = implode(',', array_fill(0, count($remove), '?'));
        $st = $pdo->prepare("DELETE FROM students WHERE id IN ($in)");
        $st->execute($remove);
        $k = $st->rowCount();
        flash("$k doublon" . ($k > 1 ? 's supprimés' : ' supprimé') . '.', 'success');
    }
    redirect($self);
}

/* ---------- Regroupement par nom ---------- */
$rows = $pdo->query(
    'SELECT id, lastname, firstname, username, email, password, cohort, created_at, exported_at FROM students ORDER BY created_at, id'
)->fetchAll();
$groups = [];
foreach ($rows as $r) {
    $key = name_key($r['lastname'], $r['firstname']);
    if ($key !== '') $groups[$key][] = $r;
}
$groups = array_filter($groups, fn($g) => count($g) > 1);
if ($q !== '') {
    $needle = implode(' ', preg_split('/[^a-z0-9]+/', ascii($q), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $groups = array_filter($groups, function ($g, $key) use ($needle) {
        foreach (explode(' ', $needle) as $w) if ($w !== '' && !str_contains($key, $w)) return false;
        return true;
    }, ARRAY_FILTER_USE_BOTH);
}
uksort($groups, 'strnatcasecmp');
$nStudents = array_sum(array_map('count', $groups));

$pageTitle = 'Doublons';
$active = 'duplicates';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head">
    <div>
      <h1>Doublons</h1>
      <p class="muted">Étudiants inscrits plusieurs fois sous le même nom (majuscules, accents et ordre des mots ignorés).
        Vérifiez la cohorte et l'e-mail avant de supprimer.</p>
    </div>
  </header>

  <section class="stats">
    <div class="stat reveal"><span class="stat-n"><?= count($groups) ?></span><span class="muted">Noms en double</span></div>
    <div class="stat reveal" style="--d:.06s"><span class="stat-n"><?= $nStudents ?></span><span class="muted">Inscriptions concernées</span></div>
  </section>

  <section class="panel reveal" style="--d:.12s">
    <form class="filters" method="get" data-live-form role="search">
      <input type="search" name="q" data-live autocomplete="off" placeholder="Rechercher un nom" value="<?= e($q) ?>">
      <button class="btn" type="submit">Rechercher</button>
      <?php if ($q !== ''): ?><a class="btn" href="./duplicates.php">Tout afficher</a><?php endif; ?>
    </form>
    <?php if (!$groups): ?>
      <p class="empty"><?= $q !== '' ? 'Aucun doublon ne correspond à cette recherche.' : 'Aucun doublon : chaque nom n\'apparaît qu\'une fois.' ?></p>
    <?php endif; ?>
  </section>

  <?php foreach ($groups as $key => $g): $cohorts = array_unique(array_column($g, 'cohort')); ?>
    <section class="panel dup-group">
      <form method="post" action="./duplicates.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <?php foreach ($g as $s): ?><input type="hidden" name="group[]" value="<?= (int)$s['id'] ?>"><?php endforeach; ?>

        <div class="dup-head">
          <h2><?= e($g[0]['lastname']) ?> <span class="muted"><?= e($g[0]['firstname']) ?></span></h2>
          <span class="tag tag-new"><?= count($g) ?> inscriptions</span>
          <?php if (count($cohorts) > 1): ?><span class="tag tag-warn">Cohortes différentes</span><?php endif; ?>
        </div>

        <div class="table-wrap">
          <table>
            <thead><tr><th class="col-check"></th><th>Nom</th><th>Identifiant</th><th>E-mail</th><th>Cohorte</th><th>Inscrit le</th><th>Export</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($g as $i => $s): ?>
              <tr>
                <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Sélectionner <?= e($s['username']) ?>"></td>
                <td><strong><?= e($s['lastname']) ?></strong> <?= e($s['firstname']) ?></td>
                <td><code><?= e($s['username']) ?></code></td>
                <td class="small"><?= e($s['email']) ?></td>
                <td><span class="pill"><?= e($s['cohort']) ?></span></td>
                <td class="small"><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?><?= $i === count($g) - 1 ? ' <span class="muted">(dernière)</span>' : '' ?></td>
                <td><?= $s['exported_at'] ? '<span class="tag tag-done">Exporté</span>' : '<span class="tag tag-new">Nouveau</span>' ?></td>
                <td class="row-actions">
                  <button class="btn" type="submit" name="keep" value="<?= (int)$s['id'] ?>"
                          onclick="return confirm('Garder <?= e($s['username']) ?> et supprimer les <?= count($g) - 1 ?> autre(s) inscription(s) de ce groupe ?');">Garder celui-ci</button>
                  <a class="icon-btn" href="./edit.php?<?= e(http_build_query(['id' => (int)$s['id'], 'back' => ltrim($self, './')])) ?>" title="Modifier" aria-label="Modifier">✎</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="export">
          <?php if (array_filter(array_column($g, 'exported_at'))): ?>
            <span class="muted small">Un étudiant déjà exporté a peut-être un compte Moodle : le supprimer ici ne supprime pas ce compte.</span>
          <?php endif; ?>
          <button class="btn" type="submit" name="action" value="delete"
                  onclick="return confirm('Supprimer les inscriptions cochées ?');">Supprimer la sélection</button>
        </div>
      </form>
    </section>
  <?php endforeach; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
