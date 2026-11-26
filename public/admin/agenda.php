<?php
declare(strict_types=1);
// Agenda : calendrier mensuel des ouvertures et fermetures de QCM (comme le calendrier Moodle).
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/agenda.php';
require_admin();

$pdo = db();
$cohort = trim((string)($_GET['cohort'] ?? ''));
$course = trim((string)($_GET['course'] ?? ''));
$planId = (int)($_GET['plan'] ?? 0);
if ($planId && ($p = plan_get($pdo, $planId))) { $course = $p['title']; $planId = 0; }   // lien « Calendrier du cours »

$month = DateTimeImmutable::createFromFormat('!Y-m', (string)($_GET['m'] ?? '')) ?: new DateTimeImmutable('first day of this month midnight');
$month = $month->modify('first day of this month');
$next = $month->modify('+1 month');
$prev = $month->modify('-1 month');

$events = agenda_events($pdo, $month->format('Y-m-d 00:00:00'), $next->format('Y-m-d 00:00:00'), $cohort, 0, $course);
$byDay = [];
foreach ($events as $ev) $byDay[substr($ev['at'], 0, 10)][] = $ev;
$upcoming = array_slice(agenda_events($pdo, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+60 days')), $cohort, 0, $course), 0, 15);

$filters = array_filter(['cohort' => $cohort, 'course' => $course]);
$url = fn(array $extra = []) => './agenda.php?' . http_build_query(array_merge($filters, $extra));
$quizUrl = fn(array $ev) => './quiz-agenda.php?plan=' . $ev['plan_id'] . '&item=' . (int)$ev['schedule']['item_id'];

$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
$start = $month->modify('-' . ((int)$month->format('N') - 1) . ' days');          // lundi de la 1re semaine
$end = $next->modify('-1 day');
$end = $end->modify('+' . (7 - (int)$end->format('N')) . ' days');               // dimanche de la dernière semaine
$today = date('Y-m-d');
$plans = $pdo->query('SELECT id, title FROM course_plans ORDER BY title')->fetchAll(PDO::FETCH_KEY_PAIR);
$exactPlan = $course !== '' ? array_search($course, $plans, true) : false;   // cours filtré exactement : export PDF possible

$pageTitle = 'Agenda';
$active = 'agenda';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head">
    <div>
      <h1>Agenda des QCM</h1>
      <p class="muted">Ouvertures et fermetures programmées par cohorte. Pour programmer un QCM : <a class="link" href="./programmes.php">Cours</a> → ouvrir un cours → bouton « Agenda » du QCM.</p>
    </div>
  </header>

  <section class="panel reveal">
    <form class="filters" method="get" data-live-form>
      <input type="hidden" name="m" value="<?= e($month->format('Y-m')) ?>">
      <input name="cohort" list="agenda-cohorts" data-live autocomplete="off" placeholder="Toutes les cohortes" value="<?= e($cohort) ?>" aria-label="Filtrer par cohorte">
      <datalist id="agenda-cohorts"><?php foreach (array_keys(all_cohorts($pdo)) as $c): ?><option value="<?= e((string)$c) ?>"></option><?php endforeach; ?></datalist>
      <input name="course" list="agenda-courses" data-live autocomplete="off" placeholder="Tous les cours" value="<?= e($course) ?>" aria-label="Filtrer par cours">
      <datalist id="agenda-courses"><?php foreach ($plans as $t): ?><option value="<?= e((string)$t) ?>"></option><?php endforeach; ?></datalist>
      <button class="btn" type="submit">Filtrer</button>
      <?php if ($filters): ?><a class="btn" href="./agenda.php?m=<?= e($month->format('Y-m')) ?>">Effacer</a><?php endif; ?>
      <?php if ($exactPlan !== false): ?>
        <button class="btn btn-primary" type="button" data-agenda-pdf="<?= (int)$exactPlan ?>"><span class="btn-label">Agenda du cours en PDF</span></button>
      <?php endif; ?>
    </form>

    <div class="cal-head">
      <a class="btn" href="<?= e($url(['m' => $prev->format('Y-m')])) ?>" aria-label="Mois précédent">←</a>
      <h2><?= ucfirst($months[(int)$month->format('n') - 1]) ?> <?= $month->format('Y') ?></h2>
      <a class="btn" href="<?= e($url(['m' => $next->format('Y-m')])) ?>" aria-label="Mois suivant">→</a>
      <span class="bulk-spacer"></span>
      <span class="cal-legend"><span class="cal-ev ev-open">▶ ouverture</span><span class="cal-ev ev-close">■ fermeture</span></span>
      <a class="btn" href="<?= e($url()) ?>">Aujourd'hui</a>
    </div>

    <div class="cal-grid" role="grid">
      <?php foreach ($days as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
      <?php for ($day = $start; $day <= $end; $day = $day->modify('+1 day')): $key = $day->format('Y-m-d'); ?>
        <div class="cal-day<?= $day->format('m') !== $month->format('m') ? ' is-out' : '' ?><?= $key === $today ? ' is-today' : '' ?>">
          <span class="cal-num"><?= $day->format('j') ?></span>
          <?php foreach ($byDay[$key] ?? [] as $ev): ?>
            <a class="cal-ev ev-<?= $ev['kind'] ?>" href="<?= e($quizUrl($ev)) ?>"
               title="<?= e(($ev['kind'] === 'open' ? 'Ouverture' : 'Fermeture') . ' ' . substr($ev['at'], 11, 5) . ' — ' . $ev['quiz'] . ' — ' . $ev['plan'] . ' — ' . $ev['cohort']) ?>">
              <?= $ev['kind'] === 'open' ? '▶' : '■' ?> <?= e(substr($ev['at'], 11, 5)) ?> <?= e($ev['quiz']) ?>
              <small><?= e($ev['cohort']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
    <?php if (!$events): ?><p class="muted small">Aucune ouverture ni fermeture ce mois-ci<?= $filters ? ' pour ces filtres' : '' ?>.</p><?php endif; ?>
  </section>

  <section class="panel reveal">
    <h2>Prochaines échéances <span class="muted small">(60 jours)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th></th><th>QCM</th><th>Cours</th><th>Cohorte</th></tr></thead>
        <tbody>
        <?php foreach ($upcoming as $ev): ?>
          <tr>
            <td class="small"><?= e(date('d/m/Y H:i', strtotime($ev['at']))) ?></td>
            <td><span class="tag <?= $ev['kind'] === 'open' ? 'tag-open' : 'tag-closed' ?>"><?= $ev['kind'] === 'open' ? 'Ouverture' : 'Fermeture' ?></span></td>
            <td><a class="link" href="<?= e($quizUrl($ev)) ?>"><?= e($ev['quiz']) ?></a></td>
            <td class="small"><?= e($ev['plan']) ?></td>
            <td><span class="pill"><?= e($ev['cohort']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$upcoming): ?><tr><td colspan="5" class="empty">Aucune échéance dans les 60 prochains jours.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
