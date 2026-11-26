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
$pageTitle = 'Inscriptions';
$active = 'inscriptions';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head">
    <div>
      <h1>Inscriptions</h1>
      <p class="muted">Étudiants inscrits : recherche, correction, actions de masse et export CSV pour Moodle.</p>
    </div>
    <a class="btn" href="./duplicates.php">Vérifier les doublons</a>
  </header>

  <section class="stats">
    <div class="stat reveal"><span class="stat-n"><?= (int)$stats['total'] ?></span><span class="muted">Étudiants</span></div>
    <div class="stat reveal" style="--d:.06s"><span class="stat-n"><?= (int)$stats['fresh'] ?></span><span class="muted">Pas encore exportés</span></div>
    <div class="stat reveal" style="--d:.12s"><span class="stat-n"><?= (int)$stats['cohorts'] ?></span><span class="muted">Cohortes</span></div>
  </section>

  <section class="panel reveal" style="--d:.18s">
    <form class="filters" method="get" data-live-form role="search">
      <input type="search" name="q" data-live autocomplete="off" aria-label="Rechercher un étudiant"
             placeholder="Nom complet, identifiant, e-mail, cohorte…" value="<?= e($_GET['q'] ?? '') ?>">
      <input name="cohort" list="cohort-list" data-live autocomplete="off" aria-label="Filtrer par cohorte"
             placeholder="Toutes les cohortes" value="<?= e($_GET['cohort'] ?? '') ?>">
      <datalist id="cohort-list">
        <?php foreach ($cohorts as $c): ?>
          <option value="<?= e($c['cohort']) ?>"><?= (int)$c['n'] ?> non exporté(s)</option>
        <?php endforeach; ?>
      </datalist>
      <input name="status" list="status-list" data-live autocomplete="off" aria-label="Filtrer par statut"
             placeholder="Tous les statuts" value="<?= e($_GET['status'] ?? '') ?>">
      <datalist id="status-list">
        <option value="Non exportés"></option>
        <option value="Déjà exportés"></option>
      </datalist>
      <button class="btn" type="submit">Filtrer</button>
      <?php if (array_filter([$_GET['q'] ?? '', $_GET['cohort'] ?? '', $_GET['status'] ?? ''])): ?>
        <a class="btn" href="./inscriptions.php">Effacer</a>
      <?php endif; ?>
    </form>

    <form class="export" method="post" action="./export.php" data-no-async>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="q" value="<?= e($_GET['q'] ?? '') ?>">
      <input type="hidden" name="cohort" value="<?= e($_GET['cohort'] ?? '') ?>">
      <input type="hidden" name="status" value="<?= e($_GET['status'] ?? '') ?>">
      <label class="check"><input type="checkbox" name="mark" value="1" checked> Marquer comme exportés</label>
      <button class="btn btn-primary" type="submit" <?= $total === 0 ? 'disabled' : '' ?>>Exporter CSV (<?= $total ?>)</button>
    </form>

    <!-- Actions de masse : les cases du tableau y sont rattachées via l'attribut form="bulk-form"
         (pas de formulaire imbriqué avec les boutons « Supprimer » de chaque ligne). -->
    <form id="bulk-form" method="post" action="./bulk.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="back" value="<?= e($query()) ?>">

      <div class="bulk-bar" id="bulk-bar" hidden>
        <span class="bulk-count"><strong id="bulk-count">0</strong> sélectionné(s)</span>
        <button class="btn" type="button" id="bulk-clear">Désélectionner</button>
        <span class="bulk-spacer"></span>
        <button class="btn" type="button" id="bulk-edit-open"><?= ui_icon('pencil') ?>Modifier</button>
        <button class="btn btn-danger" type="submit" name="action" value="delete" id="bulk-delete"><?= ui_icon('trash') ?>Supprimer</button>
      </div>

      <dialog class="modal" id="bulk-dialog" aria-labelledby="bulk-title">
        <div class="modal-body form">
          <header>
            <h2 id="bulk-title">Modifier <span id="bulk-count-2">0</span> étudiant(s)</h2>
            <p class="muted small">Seuls les champs renseignés sont appliqués à tous les étudiants sélectionnés. Le reste est conservé.</p>
          </header>

          <h3>Parcours</h3>
          <p class="muted small">Changer la classe recalcule la cohorte, et donc les cours et le groupe Moodle. Complétez toute la chaîne ou laissez vide.</p>
          <div class="grid-2">
            <label class="field"><span>Section</span>
              <select name="section_id"><option value="">— Inchangé —</option></select></label>
            <label class="field"><span>Cycle</span>
              <select name="cycle_id" disabled><option value="">— Inchangé —</option></select></label>
            <label class="field"><span>Niveau</span>
              <select name="level_id" disabled><option value="">— Inchangé —</option></select></label>
            <label class="field"><span>Classe</span>
              <select name="class_id" disabled><option value="">— Inchangé —</option></select></label>
          </div>

          <h3>Export &amp; accès</h3>
          <div class="grid-2">
            <label class="field"><span>Statut d'export</span>
              <select name="export_status">
                <option value="keep">— Inchangé —</option>
                <option value="new">Repasser en « Nouveau »</option>
                <option value="exported">Marquer « Exporté »</option>
              </select></label>
            <label class="check" style="align-self:end;padding-bottom:.9rem">
              <input type="checkbox" name="regen_password" value="1"> Générer un nouveau mot de passe pour chacun</label>
          </div>

          <div class="export">
            <button class="btn" type="button" id="bulk-cancel">Annuler</button>
            <button class="btn btn-primary" type="submit" name="action" value="edit">Appliquer à la sélection</button>
          </div>
        </div>
      </dialog>
    </form>

    <div class="table-wrap">
      <table>
        <thead><tr>
          <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout sélectionner sur cette page"></th>
          <th>Identifiant</th><th>Nom</th><th>Mot de passe</th><th>Cohorte</th><th>Cours / groupe Moodle</th><th>Export</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $courses = moodle_courses_for($r['cohort'], $r['section_code'], $subjects, $overrides);
          $det = 'det-' . (int)$r['id']; ?>
          <tr class="row-main" data-modal="<?= $det ?>" data-modal-title="<?= e($r['lastname'] . ' ' . $r['firstname']) ?>" title="Cliquer pour afficher la fiche">
            <td class="col-check"><input type="checkbox" class="row-check" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulk-form" aria-label="Sélectionner <?= e($r['lastname']) ?>"></td>
            <td><code><?= e($r['username']) ?></code></td>
            <td><strong><?= e($r['lastname']) ?></strong> <?= e($r['firstname']) ?></td>
            <td class="muted"><?= e($r['password']) ?></td>
            <td><span class="pill"><?= e($r['cohort']) ?></span></td>
            <td class="small col-courses">
              <?php if ($courses): ?>
                <div class="clamp-2"><?= implode('<br>', array_map('e', $courses)) ?><br><span class="muted"><?= e(moodle_group_name($r['cohort'])) ?></span></div>
              <?php else: ?>
                <a class="tag tag-new" href="./cohort-subjects.php?cohort=<?= e(urlencode($r['cohort'])) ?>">Aucune matière</a>
              <?php endif; ?>
            </td>
            <td><?= $r['exported_at'] ? '<span class="tag tag-done">Exporté</span>' : '<span class="tag tag-new">Nouveau</span>' ?></td>
            <td class="row-actions">
              <button class="icon-btn" type="button" data-open-modal title="Voir la fiche" aria-label="Voir la fiche de <?= e($r['lastname'] . ' ' . $r['firstname']) ?>"><?= ui_icon('eye') ?></button>
              <a class="icon-btn" href="./edit.php?<?= e(http_build_query(['id' => (int)$r['id'], 'back' => $query()])) ?>" title="Modifier" aria-label="Modifier"><?= ui_icon('pencil') ?></a>
              <form method="post" action="./delete.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="back" value="<?= e($query()) ?>">
                <button class="icon-btn" title="Supprimer" aria-label="Supprimer" data-confirm-title="Supprimer <?= e($r['lastname'] . ' ' . $r['firstname']) ?> ?" data-confirm="Cette action est irréversible." data-confirm-danger><?= ui_icon('trash') ?></button>
              </form>
            </td>
          </tr>
          <template id="<?= $det ?>">
              <div class="details-grid">
                <div><span>Nom complet</span><strong><?= e($r['lastname'] . ' ' . $r['firstname']) ?></strong></div>
                <div><span>Nom d'utilisateur</span><code><?= e($r['username']) ?></code></div>
                <div><span>Mot de passe</span><code><?= e($r['password']) ?></code></div>
                <div><span>E-mail</span><?= e($r['email']) ?></div>
                <div><span>Parcours</span><?= e(implode(' › ', array_filter([$r['section_label'], $r['cycle_label'], $r['level_label'], $r['class_label']]))) ?></div>
                <div><span>Inscrit le</span><?= e(date('d/m/Y à H:i', strtotime($r['created_at']))) ?></div>
                <div><span>Export Moodle</span><?= $r['exported_at'] ? 'Exporté le ' . e(date('d/m/Y à H:i', strtotime($r['exported_at']))) : 'Pas encore exporté' ?></div>
                <div><span>Groupe Moodle</span><code><?= e(moodle_group_name($r['cohort'])) ?></code></div>
                <div class="details-wide"><span>Cours Moodle (<?= count($courses) ?>)</span>
                  <?php if ($courses): ?>
                    <div class="chip-list"><?php foreach ($courses as $c): ?><span class="chip"><?= e($c) ?></span><?php endforeach; ?></div>
                  <?php else: ?>
                    <a class="link small" href="./cohort-subjects.php?cohort=<?= e(urlencode($r['cohort'])) ?>">Aucune matière : ajouter les matières de la cohorte</a>
                  <?php endif; ?>
                </div>
              </div>
              <div class="modal-actions">
                <a class="btn btn-primary" href="./edit.php?<?= e(http_build_query(['id' => (int)$r['id'], 'back' => $query()])) ?>"><?= ui_icon('pencil') ?>Modifier</a>
              </div>
          </template>
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
<?php require __DIR__ . '/../../includes/footer.php'; ?>
