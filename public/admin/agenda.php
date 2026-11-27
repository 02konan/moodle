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

// Un badge par programmation, le jour de l'ouverture (ou le 1er du mois si elle a ouvert avant)
$monthStart = $month->format('Y-m-d 00:00:00');
$schedules = agenda_schedules($pdo, $monthStart, $next->format('Y-m-d 00:00:00'), $cohort, $course);
$byDay = [];
foreach ($schedules as $s) $byDay[substr(max($s['opens_at'], $monthStart), 0, 10)][] = $s;
// Programmations pas encore fermées qui ouvrent dans les 60 jours (ou déjà ouvertes)
$upcoming = array_slice(agenda_schedules($pdo, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+60 days')), $cohort, $course), 0, 20);

$filters = array_filter(['cohort' => $cohort, 'course' => $course]);
$url = fn(array $extra = []) => './agenda.php?' . http_build_query(array_merge($filters, $extra));
$quizUrl = fn(array $s) => './quiz-agenda.php?plan=' . (int)$s['plan_id'] . '&item=' . (int)$s['item_id'];

$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
$weekdays = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$monthStartDay = $month->format('Y-m-d');
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
      <span class="cal-legend"><?php foreach (SCHEDULE_STATUS as $k => $l): ?><span class="cal-legend-item"><i class="dot-<?= $k ?>"></i><?= $l ?></span><?php endforeach; ?></span>
      <a class="btn" href="<?= e($url()) ?>">Aujourd'hui</a>
    </div>

    <div class="cal-grid" role="grid">
      <?php foreach ($days as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
      <?php for ($day = $start; $day <= $end; $day = $day->modify('+1 day')): $key = $day->format('Y-m-d'); ?>
        <?php $evs = $byDay[$key] ?? []; $cls = 'cal-day' . ($day->format('m') !== $month->format('m') ? ' is-out' : '') . ($key === $today ? ' is-today' : ''); ?>
        <?php if (!$evs): ?>
          <div class="<?= $cls ?>"><span class="cal-num"><?= $day->format('j') ?></span></div>
        <?php else:
          $label = $weekdays[(int)$day->format('N') - 1] . ' ' . $day->format('j') . ' ' . $months[(int)$day->format('n') - 1] . ' ' . $day->format('Y');
          $n = count($evs); $statuses = array_count_values(array_map('schedule_status', $evs)); ?>
          <button type="button" class="<?= $cls ?> has-ev" data-modal="day-<?= $key ?>" data-modal-title="<?= e(ucfirst($label)) ?>"
                  aria-label="<?= e($label . ' : ' . $n . ' programmation' . ($n > 1 ? 's' : '')) ?>">
            <span class="cal-num"><?= $day->format('j') ?></span>
            <span class="cal-count"><?= $n ?> QCM</span>
            <span class="cal-dots"><?php foreach ($statuses as $k => $c): ?><i class="dot-<?= $k ?>" title="<?= e($c . ' ' . mb_strtolower(SCHEDULE_STATUS[$k])) ?>"></i><?php endforeach; ?></span>
          </button>
          <template id="day-<?= $key ?>">
            <p class="day-sum muted small"><?= $n ?> programmation<?= $n > 1 ? 's' : '' ?> ouvrant ce jour<?= $key === $monthStartDay && $evs[0]['opens_at'] < $monthStart ? ' (ou déjà ouvertes en début de mois)' : '' ?></p>
            <ul class="day-list">
              <?php foreach ($evs as $sc): $stt = schedule_status($sc); ?>
                <li class="day-item ev-<?= $stt ?>">
                  <div class="day-item-main">
                    <span class="cal-hours"><?= e(schedule_hours($sc)) ?></span>
                    <strong><?= e($sc['quiz']) ?></strong>
                    <span class="small muted"><?= e($sc['plan']) ?></span>
                    <span class="day-item-meta"><span class="pill"><?= e($sc['cohort']) ?></span><span class="tag tag-<?= $stt ?>"><?= SCHEDULE_STATUS[$stt] ?></span></span>
                    <span class="small muted">Du <?= e(date('d/m/Y H:i', strtotime($sc['opens_at']))) ?> au <?= e(date('d/m/Y H:i', strtotime($sc['closes_at']))) ?></span>
                  </div>
                  <a class="btn btn-sm" href="<?= e($quizUrl($sc)) ?>">Agenda du QCM</a>
                </li>
              <?php endforeach; ?>
            </ul>
          </template>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
    <?php if (!$schedules): ?><p class="muted small">Aucune programmation ce mois-ci<?= $filters ? ' pour ces filtres' : '' ?>.</p><?php endif; ?>
  </section>

  <section class="panel reveal">
    <h2>Exporter l'agenda</h2>
    <form class="filters agenda-export" data-no-async onsubmit="return false">
      <input type="hidden" name="cohort" value="<?= e($cohort) ?>">
      <input type="hidden" name="course" value="<?= e($course) ?>">
      <label class="field"><span>Du</span><input type="date" name="from" value="<?= e($month->format('Y-m-d')) ?>" required></label>
      <label class="field"><span>Au</span><input type="date" name="to" value="<?= e($next->modify('-1 day')->format('Y-m-d')) ?>" required></label>
      <button class="btn btn-primary" type="button" data-agenda-export="general"><span class="btn-label">Agenda général (PDF)</span></button>
      <button class="btn" type="button" data-agenda-export="cohort"><span class="btn-label">Agenda par cohorte (un PDF par cohorte)</span></button>
    </form>
    <p class="muted small">Format paysage, regroupé par jour. <?= $filters ? 'Les filtres ci-dessus (' . e(implode(', ', $filters)) . ') sont appliqués.' : 'Toutes les cohortes et tous les cours ; utilisez les filtres ci-dessus pour restreindre.' ?></p>
  </section>

  <section class="panel reveal">
    <h2>Prochaines programmations <span class="muted small">(en cours ou dans les 60 jours)</span></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Ouverture → fermeture</th><th>QCM</th><th>Cours</th><th>Cohorte</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($upcoming as $sc): $stt = schedule_status($sc); ?>
          <tr>
            <td><span class="sched-badge ev-<?= $stt ?>"><?= e(date('d/m/Y H:i', strtotime($sc['opens_at']))) ?> → <?= e(date('d/m/Y H:i', strtotime($sc['closes_at']))) ?></span></td>
            <td><a class="link" href="<?= e($quizUrl($sc)) ?>"><?= e($sc['quiz']) ?></a></td>
            <td class="small"><?= e($sc['plan']) ?></td>
            <td><span class="pill"><?= e($sc['cohort']) ?></span></td>
            <td><span class="tag tag-<?= $stt ?>"><?= SCHEDULE_STATUS[$stt] ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$upcoming): ?><tr><td colspan="5" class="empty">Aucune programmation en cours ni dans les 60 prochains jours.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
