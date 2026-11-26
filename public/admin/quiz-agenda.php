<?php
declare(strict_types=1);
// Agenda d'un QCM : cohortes programmées avec ouverture et fermeture (comme les dérogations de groupe Moodle).
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/agenda.php';
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
                $cohorts = array_merge((array)($_POST['cohorts'] ?? []), [(string)($_POST['other'] ?? '')]);
                [$added, $updated] = schedule_quiz($pdo, $planId, $itemId, $cohorts,
                    parse_local_datetime((string)($_POST['opens_at'] ?? ''), 'Ouverture'),
                    parse_local_datetime((string)($_POST['closes_at'] ?? ''), 'Fermeture'));
                flash("$added cohorte(s) programmée(s)" . ($updated ? ", $updated mise(s) à jour" : '') . '.', 'success');
                break;
            case 'update':
                $s = plan_schedule($pdo, $planId, (int)($_POST['schedule'] ?? 0));
                if (!$s) throw new RuntimeException('Programmation introuvable.');
                $opens = parse_local_datetime((string)($_POST['opens_at'] ?? ''), 'Ouverture');
                $closes = parse_local_datetime((string)($_POST['closes_at'] ?? ''), 'Fermeture');
                check_window($opens, $closes);
                $pdo->prepare('UPDATE quiz_schedules SET opens_at = ?, closes_at = ? WHERE id = ?')->execute([$opens, $closes, $s['id']]);
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
foreach (plan_quizzes(plan_tree($pdo, $planId)) as $q) if ($q['id'] === $itemId) $path = array_filter([$q['section'], $q['subsection']]);

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
      <button class="btn btn-primary" type="button" data-agenda-pdf="<?= $planId ?>"><span class="btn-label">Agenda du cours en PDF</span></button>
    </span>
  </header>

  <section class="panel reveal">
    <h2>Cohortes programmées <span class="muted">(<?= count($schedules) ?>)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cohorte</th><th>Ouverture</th><th>Fermeture</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($schedules as $s): $st = schedule_status($s); $fid = 'sched-' . (int)$s['id']; ?>
          <tr>
            <td><span class="pill"><?= e($s['cohort']) ?></span></td>
            <td><input form="<?= $fid ?>" type="datetime-local" name="opens_at" value="<?= e($local($s['opens_at'])) ?>" required aria-label="Ouverture"></td>
            <td><input form="<?= $fid ?>" type="datetime-local" name="closes_at" value="<?= e($local($s['closes_at'])) ?>" required aria-label="Fermeture"></td>
            <td><span class="tag tag-<?= $st ?>"><?= SCHEDULE_STATUS[$st] ?></span></td>
            <td class="row-actions">
              <form id="<?= $fid ?>" method="post" action="./quiz-agenda.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="plan" value="<?= $planId ?>"><input type="hidden" name="item" value="<?= $itemId ?>">
                <input type="hidden" name="schedule" value="<?= (int)$s['id'] ?>">
                <button class="icon-btn" type="submit" name="action" value="update" title="Enregistrer les dates" aria-label="Enregistrer les dates">✓</button>
                <button class="icon-btn" type="submit" name="action" value="delete" title="Supprimer" aria-label="Supprimer"
                        onclick="return confirm('Supprimer la programmation de <?= e($s['cohort']) ?> ?');">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$schedules): ?>
          <tr><td colspan="5" class="empty">Aucune cohorte programmée pour ce QCM.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel reveal">
    <h2>Programmer des cohortes</h2>
    <form method="post" action="./quiz-agenda.php" class="form" data-key="schedule-add">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="plan" value="<?= $planId ?>"><input type="hidden" name="item" value="<?= $itemId ?>">
      <input type="hidden" name="action" value="add">

      <?php if ($filiere): ?>
        <div class="field"><span>Cohortes de la filière <?= e(explode(':', $plan['title'], 2)[0]) ?>
          <button class="link small" type="button" data-check-all="cohorts[]">tout cocher</button></span>
          <div class="cohort-picks">
            <?php foreach ($filiere as $c): ?>
              <label class="check"><input type="checkbox" name="cohorts[]" value="<?= e($c) ?>">
                <?= e($c) ?><?= in_array($c, $scheduled, true) ? ' <span class="tag tag-done">déjà programmée</span>' : '' ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <label class="field"><span><?= $filiere ? 'Autre cohorte (facultatif)' : 'Cohorte' ?></span>
        <input name="other" list="agenda-cohorts" autocomplete="off" placeholder="Tapez ou choisissez une cohorte">
        <datalist id="agenda-cohorts"><?php foreach (($filiere ? $others : array_merge($filiere, $others)) as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?></datalist>
      </label>

      <div class="grid-2">
        <label class="field"><span>Ouverture</span><input type="datetime-local" name="opens_at" required></label>
        <label class="field"><span>Fermeture</span><input type="datetime-local" name="closes_at" required></label>
      </div>
      <p class="muted small">Une cohorte déjà programmée pour ce QCM reçoit simplement les nouvelles dates.</p>
      <div class="export"><button class="btn btn-primary" type="submit">Programmer</button></div>
    </form>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
