<?php
declare(strict_types=1);
// Doublons : étudiants regroupés par nom (majuscules, accents, ponctuation et ordre des mots ignorés).
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/students.php';
require __DIR__ . '/../../src/MoodleApi.php';
require __DIR__ . '/../../src/duplicates.php';
require_admin();

$api = MoodleApi::fromEnv();   // null si MOODLE_URL / MOODLE_TOKEN ne sont pas configurés

$pdo = db();
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$self = './duplicates.php' . ($q !== '' ? '?q=' . urlencode($q) : '');

/* ---------- Actions : garder un étudiant du groupe, ou supprimer la sélection ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('Session expirée, rechargez la page.', 'error');
        redirect($self);
    }
    /* --- Nettoyage d'après Moodle : le plan est recalculé ici, jamais repris du navigateur --- */
    if (($_POST['action'] ?? '') === 'moodle_apply') {
        if (!$api) { flash('Service web Moodle non configuré.', 'error'); redirect($self); }
        try {
            $plan = moodle_cleanup_plan(duplicate_groups($pdo, $q), $api);
        } catch (RuntimeException $ex) {
            error_log('[duplicates] ' . $ex->getMessage());
            flash($ex->getMessage(), 'error');
            redirect($self);
        }
        $moodleIds = $dbIds = [];
        foreach ($plan as $p) foreach ($p['members'] as $m) {
            if ($m['action'] !== 'delete') continue;
            $dbIds[] = (int)$m['id'];
            if ($m['moodle']) $moodleIds[] = $m['moodle']['id'];
        }
        if (!$dbIds) { flash('Rien à supprimer.', 'info'); redirect($self); }
        try {
            if ($moodleIds) $api->deleteUsers($moodleIds);   // Moodle d'abord : en cas d'échec, la base reste intacte
        } catch (RuntimeException $ex) {
            error_log('[duplicates] ' . $ex->getMessage());
            flash('Suppression dans Moodle impossible, rien n\'a été supprimé : ' . $ex->getMessage(), 'error');
            redirect($self);
        }
        $in = implode(',', array_fill(0, count($dbIds), '?'));
        $st = $pdo->prepare("DELETE FROM students WHERE id IN ($in)");
        $st->execute($dbIds);
        flash(count($moodleIds) . ' compte(s) Moodle et ' . $st->rowCount() . ' inscription(s) supprimés.', 'success');
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

/* ---------- Regroupement par nom (+ vérification Moodle si demandée) ---------- */
$groups = duplicate_groups($pdo, $q);
$plan = null;
if ($api && !empty($_GET['moodle']) && $groups) {
    try {
        $plan = moodle_cleanup_plan($groups, $api);
    } catch (RuntimeException $ex) {
        error_log('[duplicates] ' . $ex->getMessage());
        flash($ex->getMessage(), 'error');
    }
}

// CSV de suppression pour l'import Moodle (alternative manuelle au bouton « Appliquer »)
if ($plan && !empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="moodle-suppression-doublons-' . date('Y-m-d-H\hi') . '.csv"');
    echo "\xEF\xBB\xBFusername;deleted\r\n";
    foreach ($plan as $p) foreach ($p['members'] as $m) {
        if ($m['action'] === 'delete' && $m['moodle']) echo $m['username'] . ";1\r\n";
    }
    exit;
}
$summary = $plan ? plan_summary($plan) : null;
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

  <?php if ($groups): $qs = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
    <section class="panel reveal moodle-panel" style="--d:.16s">
      <?php if (!$api): ?>
        <h2>Vérification dans Moodle</h2>
        <p class="muted small">Pour repérer automatiquement les comptes jamais utilisés et les supprimer dans Moodle,
          renseignez <code>MOODLE_URL</code> et <code>MOODLE_TOKEN</code> dans les variables d'environnement (voir le README).</p>
      <?php elseif (!$plan): ?>
        <div class="dup-head">
          <h2>Vérification dans Moodle</h2>
          <span class="bulk-spacer"></span>
          <a class="btn btn-primary" href="./duplicates.php?moodle=1<?= e($qs) ?>">Vérifier les connexions dans Moodle</a>
        </div>
        <p class="muted small">Moodle indique quels comptes ont déjà servi. Le compte utilisé est gardé, les accès jamais utilisés sont proposés à la suppression.
          Rien n'est supprimé sans votre confirmation.</p>
      <?php else: ?>
        <div class="dup-head">
          <h2>Plan de nettoyage</h2>
          <span class="tag tag-del"><?= $summary['moodle'] ?> compte(s) Moodle à supprimer</span>
          <span class="tag tag-new"><?= $summary['db'] ?> inscription(s) à supprimer</span>
          <span class="tag tag-done"><?= $summary['kept'] ?> gardé(s)</span>
          <?php if ($summary['notes']): ?><span class="tag tag-warn"><?= $summary['notes'] ?> groupe(s) à vérifier</span><?php endif; ?>
        </div>
        <form method="post" action="./duplicates.php" class="export">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <a class="btn" href="./duplicates.php<?= $q !== '' ? '?q=' . e(urlencode($q)) : '' ?>">Annuler</a>
          <a class="btn" href="./duplicates.php?moodle=1&amp;csv=1<?= e($qs) ?>" data-no-async>CSV de suppression (import Moodle)</a>
          <button class="btn btn-primary" type="submit" name="action" value="moodle_apply" <?= $summary['db'] ? '' : 'disabled' ?>
                  onclick="return confirm('Supprimer définitivement <?= $summary['moodle'] ?> compte(s) dans Moodle et <?= $summary['db'] ?> inscription(s) dans la base ?');">
            Appliquer le nettoyage</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php foreach ($groups as $key => $g):
        $cohorts = array_unique(array_column($g, 'cohort'));
        if ($plan) $g = $plan[$key]['members'];   // mêmes lignes, enrichies des informations Moodle ?>
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
        <?php if ($plan && $plan[$key]['note']): ?><p class="small dup-note"><?= e($plan[$key]['note']) ?></p><?php endif; ?>

        <div class="table-wrap">
          <table>
            <thead><tr><th class="col-check"></th><th>Nom</th><th>Identifiant</th><th>E-mail</th><th>Cohorte</th><th>Inscrit le</th><th>Export</th><?= $plan ? '<th>Moodle</th>' : '' ?><th></th></tr></thead>
            <tbody>
            <?php foreach ($g as $i => $s): ?>
              <tr class="<?= $plan ? 'plan-' . $s['action'] : '' ?>">
                <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Sélectionner <?= e($s['username']) ?>"></td>
                <td><strong><?= e($s['lastname']) ?></strong> <?= e($s['firstname']) ?></td>
                <td><code><?= e($s['username']) ?></code></td>
                <td class="small"><?= e($s['email']) ?></td>
                <td><span class="pill"><?= e($s['cohort']) ?></span></td>
                <td class="small"><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?><?= $i === count($g) - 1 ? ' <span class="muted">(dernière)</span>' : '' ?></td>
                <td><?= $s['exported_at'] ? '<span class="tag tag-done">Exporté</span>' : '<span class="tag tag-new">Nouveau</span>' ?></td>
                <?php if ($plan): ?>
                  <td class="small">
                    <span class="tag <?= $s['action'] === 'keep' ? 'tag-keep' : 'tag-del' ?>"><?= $s['action'] === 'keep' ? 'Gardé' : 'Supprimé' ?></span>
                    <br><span class="muted"><?= e($s['reason']) ?></span>
                  </td>
                <?php endif; ?>
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
