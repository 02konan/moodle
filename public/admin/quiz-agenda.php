<?php
declare(strict_types=1);
// Agenda d'un QCM : cohortes programmées avec ouverture et fermeture (comme les dérogations de groupe Moodle).
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/agenda.php';
require __DIR__ . '/../../src/moodle_agenda.php';
require_admin();

$pdo = db();
$planId = (int)($_GET['plan'] ?? $_POST['plan'] ?? 0);
$itemId = (int)($_GET['item'] ?? $_POST['item'] ?? 0);
$plan = plan_get($pdo, $planId);
$quiz = $plan ? plan_item($pdo, $planId, $itemId) : null;
if (!$plan || !$quiz || $quiz['type'] !== 'quiz') { flash('QCM introuvable.', 'error'); redirect('./programmes.php'); }
$self = "./quiz-agenda.php?plan=$planId&item=$itemId";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) { flash('Session expirée, rechargez la page.', 'error'); redirect($self); }
    try {
        switch ($_POST['action'] ?? '') {
            case 'add':
                $cohorts = array_map('strval', (array)($_POST['cohorts'] ?? []));
                $opens = parse_local_datetime((string)($_POST['opens_at'] ?? ''), 'Ouverture');
                $closes = parse_local_datetime((string)($_POST['closes_at'] ?? ''), 'Fermeture');
                // Ce QCM + les autres QCM cochés : mêmes cohortes, même intervalle, en une seule fois
                $items = array_values(array_unique(array_merge([$itemId], array_map('intval', (array)($_POST['also'] ?? [])))));
                $pdo->beginTransaction();
                try {
                    $added = $updated = 0;
                    foreach ($items as $it) {
                        [$a, $u] = schedule_quiz($pdo, $planId, $it, $cohorts, $opens, $closes);
                        $added += $a; $updated += $u;
                    }
                    $pdo->commit();
                } catch (Throwable $ex) {
                    $pdo->rollBack();   // rien n'est enregistré si un QCM pose problème
                    throw $ex;
                }
                $n = count($items);
                flash("$added programmation(s) ajoutée(s)" . ($updated ? ", $updated mise(s) à jour" : '')
                    . ($n > 1 ? " sur $n QCM." : '.'), 'success');
                break;
            case 'update':
                $s = plan_schedule($pdo, $planId, (int)($_POST['schedule'] ?? 0));
                if (!$s) throw new RuntimeException('Programmation introuvable.');
                $opens = parse_local_datetime((string)($_POST['opens_at'] ?? ''), 'Ouverture');
                $closes = parse_local_datetime((string)($_POST['closes_at'] ?? ''), 'Fermeture');
                check_window($opens, $closes);
                $pdo->prepare('UPDATE quiz_schedules SET opens_at = ?, closes_at = ?, updated_at = ? WHERE id = ?')->execute([$opens, $closes, now_sql(), $s['id']]);
                flash("Dates de {$s['cohort']} enregistrées.", 'success');
                break;
            case 'delete':
                $s = plan_schedule($pdo, $planId, (int)($_POST['schedule'] ?? 0));
                if (!$s) throw new RuntimeException('Programmation introuvable.');
                $pdo->prepare('DELETE FROM quiz_schedules WHERE id = ?')->execute([$s['id']]);
                flash("Programmation de {$s['cohort']} supprimée.", 'success');
                break;
            default:
                throw new RuntimeException('Action inconnue.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect($self);
}

$schedules = plan_schedules($pdo, $planId)[$itemId] ?? [];
$scheduled = array_column($schedules, 'cohort');
$filiere = plan_filiere_cohorts($pdo, $plan['title']);
$others = array_values(array_diff(array_keys(all_cohorts($pdo)), $filiere));
$local = fn(string $dt) => date('Y-m-d\TH:i', strtotime($dt));
$fmt = fn(string $dt) => date('d/m/Y H:i', strtotime($dt));

// Emplacement du QCM dans le cours (section › sous-section)
$path = [];
$allQuizzes = plan_quizzes(plan_tree($pdo, $planId));
foreach ($allQuizzes as $q) if ($q['id'] === $itemId) $path = array_filter([$q['section'], $q['subsection']]);
$otherQuizzes = array_values(array_filter($allQuizzes, fn($q) => $q['id'] !== $itemId));
$scheduleCounts = array_map('count', plan_schedules($pdo, $planId));

$pageTitle = 'Agenda — ' . $quiz['title'];
$active = 'agenda';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head">
    <div>
      <p class="muted small"><a class="link" href="./programme.php?id=<?= $planId ?>">← <?= e($plan['title']) ?></a></p>
      <h1><?= e($quiz['title']) ?></h1>
      <p class="muted"><?= e(implode(' › ', $path)) ?></p>
    </div>
    <span class="export">
      <a class="btn" href="./agenda.php?plan=<?= $planId ?>">Calendrier du cours</a>
      <button class="btn" type="button" data-moodle-agenda data-plan="<?= $planId ?>" data-item="<?= $itemId ?>" data-label="<?= e($quiz['title']) ?>"><span class="btn-label">Envoyer à Moodle</span></button>
      <button class="btn btn-primary" type="button" data-agenda-pdf="<?= $planId ?>"><span class="btn-label">Agenda du cours en PDF</span></button>
    </span>
  </header>

  <section class="panel reveal">
    <h2>Cohortes programmées <span class="muted">(<?= count($schedules) ?>)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cohorte</th><th>Ouverture</th><th>Fermeture</th><th>État</th><th>Moodle</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($schedules as $s): $st = schedule_status($s); $fid = 'sched-' . (int)$s['id']; ?>
          <tr>
            <td><span class="pill"><?= e($s['cohort']) ?></span></td>
            <td><input form="<?= $fid ?>" type="datetime-local" name="opens_at" value="<?= e($local($s['opens_at'])) ?>" required aria-label="Ouverture"></td>
            <td><input form="<?= $fid ?>" type="datetime-local" name="closes_at" value="<?= e($local($s['closes_at'])) ?>" required aria-label="Fermeture"></td>
            <td><span class="tag tag-<?= $st ?>"><?= SCHEDULE_STATUS[$st] ?></span></td>
            <td><?php $sync = schedule_sync_state($s); ?><span class="tag tag-sync-<?= $sync ?>"<?= $s['moodle_synced_at'] ? ' title="Dernier envoi : ' . e($fmt($s['moodle_synced_at'])) . '"' : '' ?>><?= SCHEDULE_SYNC_LABELS[$sync] ?></span></td>
            <td class="row-actions">
              <form id="<?= $fid ?>" method="post" action="./quiz-agenda.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="plan" value="<?= $planId ?>"><input type="hidden" name="item" value="<?= $itemId ?>">
                <input type="hidden" name="schedule" value="<?= (int)$s['id'] ?>">
                <button class="icon-btn" type="submit" name="action" value="update" title="Enregistrer les dates" aria-label="Enregistrer les dates"><?= ui_icon('check') ?></button>
                <button class="icon-btn" type="submit" name="action" value="delete" title="Supprimer" aria-label="Supprimer"
                        data-confirm-title="Supprimer la programmation ?" data-confirm="<?= e($s['cohort']) ?> ne sera plus programmée pour ce QCM." data-confirm-danger><?= ui_icon('trash') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$schedules): ?>
          <tr><td colspan="6" class="empty">Aucune cohorte programmée pour ce QCM.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel reveal">
    <h2>Programmer des classes</h2>
    <form method="post" action="./quiz-agenda.php" class="form" data-key="schedule-add">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="plan" value="<?= $planId ?>"><input type="hidden" name="item" value="<?= $itemId ?>">
      <input type="hidden" name="action" value="add">

      <?php if ($filiere): ?>
        <div class="field"><span>Cohortes de la filière <?= e(explode(':', $plan['title'], 2)[0]) ?>
          <button class="link small" type="button" data-check-all="cohorts[]" data-check-scope=".field">tout cocher</button></span>
          <div class="chip-grid">
            <?php foreach ($filiere as $c): ?>
              <label class="chip-check"><input type="checkbox" name="cohorts[]" value="<?= e($c) ?>">
                <span class="chip-check-box"><?= ui_icon('check') ?></span>
                <span class="chip-check-text"><?= e($c) ?><small><?= in_array($c, $scheduled, true) ? 'Déjà programmée pour ce QCM' : 'Pas encore programmée' ?></small></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php $pick = $filiere ? $others : array_merge($filiere, $others); ?>
      <?php if ($pick): ?>
        <details class="also-quizzes class-picker"<?= $filiere ? '' : ' open' ?>>
          <summary>
            <span><?= $filiere ? 'Autres classes' : 'Classes' ?></span>
            <span class="muted small">sélection multiple<?= $filiere ? ', hors filière' : '' ?></span>
            <span class="tag tag-warn also-count" data-count-for="cohorts[]" hidden></span>
          </summary>
          <div class="also-toolbar">
            <input type="search" data-chip-filter autocomplete="off" placeholder="Filtrer les classes (ex. ANGRE, BTS 1…)" aria-label="Filtrer les classes">
          </div>
          <div class="chip-grid class-grid">
            <?php foreach ($pick as $c): ?>
              <label class="chip-check" data-chip-text="<?= e(mb_strtolower((string)$c)) ?>"><input type="checkbox" name="cohorts[]" value="<?= e((string)$c) ?>">
                <span class="chip-check-box"><?= ui_icon('check') ?></span>
                <span class="chip-check-text"><?= e((string)$c) ?><small><?= in_array($c, $scheduled, true) ? 'Déjà programmée pour ce QCM' : 'Pas encore programmée' ?></small></span></label>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($otherQuizzes): ?>
        <?php
          // QCM regroupés par section puis sous-section, dans l'ordre du cours
          $alsoGroups = [];
          foreach ($otherQuizzes as $q) $alsoGroups[$q['section']][$q['subsection'] ?? ''][] = $q;
          $chip = fn(array $q) => '<label class="chip-check"><input type="checkbox" name="also[]" value="' . $q['id'] . '">'
              . '<span class="chip-check-box">' . ui_icon('check') . '</span>'
              . '<span class="chip-check-text">' . e($q['title'])
              . (!empty($scheduleCounts[$q['id']]) ? '<small>' . $scheduleCounts[$q['id']] . ' cohorte(s) déjà programmée(s)</small>' : '<small>Non programmé</small>')
              . '</span></label>';
        ?>
        <details class="also-quizzes">
          <summary>
            <span>Appliquer aussi à d'autres QCM du cours</span>
            <span class="muted small">mêmes cohortes, même ouverture et fermeture</span>
            <span class="tag tag-warn also-count" data-count-for="also[]" hidden></span>
          </summary>
          <div class="also-toolbar">
            <button class="btn btn-sm" type="button" data-check-all="also[]">Tout cocher / décocher</button>
          </div>
          <div class="also-groups">
            <?php foreach ($alsoGroups as $section => $subs): ?>
              <section class="also-section">
                <header class="also-section-head">
                  <h4><?= e($section) ?></h4>
                  <button class="link small" type="button" data-check-all="also[]" data-check-scope=".also-section">tout cocher</button>
                </header>
                <?php foreach ($subs as $sub => $quizzes): ?>
                  <?php if ($sub !== ''): ?><p class="also-sub-title"><?= e($sub) ?></p><?php endif; ?>
                  <div class="chip-grid<?= $sub !== '' ? ' is-sub' : '' ?>"><?= implode('', array_map($chip, $quizzes)) ?></div>
                <?php endforeach; ?>
              </section>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <div class="grid-2">
        <label class="field"><span>Ouverture</span><input type="datetime-local" name="opens_at" required></label>
        <label class="field"><span>Fermeture</span><input type="datetime-local" name="closes_at" required></label>
      </div>
      <p class="muted small">Une cohorte déjà programmée pour ce QCM reçoit simplement les nouvelles dates.</p>
      <div class="export"><button class="btn btn-primary" type="submit">Programmer</button></div>
    </form>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
