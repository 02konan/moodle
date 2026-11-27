<?php
declare(strict_types=1);
// Tableau de bord du backoffice : indicateurs, évolution des inscriptions, points à traiter, activité récente.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/students.php';
require __DIR__ . '/../../src/duplicates.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/agenda.php';
require __DIR__ . '/../../src/MoodleApi.php';
require_admin();

$pdo = db();

/* ---------- Indicateurs ---------- */
$stats = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(exported_at IS NULL), 0) fresh, COUNT(DISTINCT cohort) cohorts FROM students')->fetch();
$weekStart = strtotime('monday this week 00:00');
$st = $pdo->prepare('SELECT COALESCE(SUM(created_at >= ?), 0) this_week, COALESCE(SUM(created_at >= ? AND created_at < ?), 0) last_week FROM students');
$st->execute([date('Y-m-d H:i:s', $weekStart), date('Y-m-d H:i:s', $weekStart - 7 * 86400), date('Y-m-d H:i:s', $weekStart)]);
$week = $st->fetch();
$delta = (int)$week['last_week'] > 0 ? (int)round(((int)$week['this_week'] - (int)$week['last_week']) / (int)$week['last_week'] * 100) : null;

/* ---------- Inscriptions par semaine (8 semaines) ---------- */
$from = $weekStart - 7 * 7 * 86400;
$st = $pdo->prepare('SELECT YEARWEEK(created_at, 3) yw, COALESCE(SUM(exported_at IS NOT NULL), 0) exported, COALESCE(SUM(exported_at IS NULL), 0) fresh
                     FROM students WHERE created_at >= ? GROUP BY yw');
$st->execute([date('Y-m-d H:i:s', $from)]);
$byWeek = [];
foreach ($st->fetchAll() as $r) $byWeek[(string)$r['yw']] = $r;
$weeks = [];
for ($i = 0; $i < 8; $i++) {
    $t = $from + $i * 7 * 86400;
    $r = $byWeek[date('oW', $t)] ?? ['exported' => 0, 'fresh' => 0];
    $weeks[] = ['label' => date('d/m', $t), 'exported' => (int)$r['exported'], 'fresh' => (int)$r['fresh']];
}
$chartMax = max(4, ...array_map(fn($w) => $w['exported'] + $w['fresh'], $weeks));
$chartMax = (int)(ceil($chartMax / 4) * 4);   // graduations entières

/* ---------- Points à traiter ---------- */
$dupGroups = count(duplicate_groups($pdo));
$noSubject = (int)$pdo->query("SELECT COUNT(DISTINCT s.cohort) FROM students s LEFT JOIN cohort_subjects cs ON cs.cohort = s.cohort WHERE s.cohort <> '' AND cs.id IS NULL")->fetchColumn();
$moodleReady = MoodleApi::fromEnv() !== null;
$todo = [];
if ($stats['fresh'] > 0) $todo[] = ['warn', (int)$stats['fresh'] . ' étudiant(s) à exporter vers Moodle', 'Exporter ou créer dans Moodle', './inscriptions.php?status=' . urlencode('Non exportés')];
if ($dupGroups > 0) $todo[] = ['danger', "$dupGroups nom(s) en double", 'Vérifier les doublons', './duplicates.php'];
if ($noSubject > 0) $todo[] = ['info', "$noSubject cohorte(s) sans matière (aucun cours Moodle)", 'Ajouter les matières', './cohort-subjects.php'];
if (!$moodleReady) $todo[] = ['info', 'Service web Moodle non configuré', 'Création en un clic indisponible', './cohorts.php'];

/* ---------- Répartition, activité récente, agenda ---------- */
$sections = $pdo->query('SELECT section_label, COUNT(*) n FROM students GROUP BY section_label ORDER BY n DESC LIMIT 6')->fetchAll();
$sectionMax = max(1, ...array_map('intval', array_column($sections, 'n') ?: [1]));
$recent = $pdo->query('SELECT id, username, lastname, firstname, cohort, created_at, exported_at FROM students ORDER BY created_at DESC, id DESC LIMIT 6')->fetchAll();
$upcoming = array_slice(agenda_schedules($pdo, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+30 days'))), 0, 5);
$st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM course_plans) plans, (SELECT COUNT(*) FROM course_plan_items WHERE type = 'quiz') quizzes,
                                 (SELECT COUNT(*) FROM quiz_schedules WHERE closes_at >= ?) active");
$st->execute([now_sql()]);
$quizStats = $st->fetch();

/* ---------- Salutation ---------- */
$email = (string)($_SESSION['admin_email'] ?? '');
$name = $email !== '' ? ucfirst(preg_split('/[._@-]/', $email)[0]) : '';
$h = (int)date('G');
$hello = $h < 12 ? 'Bonjour' : ($h < 18 ? 'Bon après-midi' : 'Bonsoir');
$days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$today = ucfirst($days[(int)date('w')]) . ' ' . date('j') . ' ' . $months[(int)date('n') - 1] . ' ' . date('Y');
$ico = fn(string $p) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';

$pageTitle = 'Tableau de bord';
$active = 'dashboard';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head dash-head">
    <div>
      <h1><?= $hello ?><?= $name !== '' ? ', ' . e($name) : '' ?></h1>
      <p class="muted"><?= e($today) ?> · suivez les inscriptions, les exports vers Moodle et la programmation des QCM.</p>
    </div>
    <div class="export">
      <a class="btn" href="./agenda.php"><?= $ico('<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>') ?>Agenda</a>
      <a class="btn btn-primary" href="./inscriptions.php"><?= $ico('<path d="M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="8" r="3.5"/><path d="M19 8v6M22 11h-6"/>') ?>Gérer les inscriptions</a>
    </div>
  </header>

  <section class="dash-grid">
    <!-- Carte mise en avant -->
    <article class="card kpi-hero reveal">
      <div class="kpi-hero-top">
        <span class="kpi-label">Inscriptions cette semaine</span>
        <span class="kpi-icon"><?= $ico('<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>') ?></span>
      </div>
      <p class="kpi-hero-value"><?= (int)$week['this_week'] ?></p>
      <p class="kpi-hero-foot">
        <?php if ($delta !== null): ?>
          <span class="delta <?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '↑' : '↓' ?> <?= abs($delta) ?> %</span> vs semaine dernière (<?= (int)$week['last_week'] ?>)
        <?php else: ?>
          <span class="delta">—</span> aucune inscription la semaine dernière
        <?php endif; ?>
      </p>
      <div class="kpi-hero-actions">
        <a class="btn btn-light" href="./inscriptions.php">Voir la liste</a>
        <a class="btn btn-ghost-light" href="./cohorts.php">Cohortes</a>
      </div>
    </article>

    <!-- Indicateurs -->
    <div class="kpi-grid">
      <a class="card kpi reveal" style="--d:.04s" href="./inscriptions.php">
        <span class="kpi-top"><span class="kpi-label">Étudiants</span><span class="kpi-icon"><?= $ico('<path d="M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="8" r="3.5"/><path d="M21 20v-1a4 4 0 0 0-3-3.87M15.5 4.1a3.5 3.5 0 0 1 0 6.8"/>') ?></span></span>
        <span class="kpi-value"><?= number_format((int)$stats['total'], 0, ',', ' ') ?></span>
        <span class="kpi-foot">inscrits au total</span>
      </a>
      <a class="card kpi reveal" style="--d:.08s" href="./inscriptions.php?status=<?= urlencode('Non exportés') ?>">
        <span class="kpi-top"><span class="kpi-label">À exporter</span><span class="kpi-icon is-warn"><?= $ico('<path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/>') ?></span></span>
        <span class="kpi-value"><?= (int)$stats['fresh'] ?></span>
        <span class="kpi-foot">pas encore dans Moodle</span>
      </a>
      <a class="card kpi reveal" style="--d:.12s" href="./cohorts.php">
        <span class="kpi-top"><span class="kpi-label">Cohortes</span><span class="kpi-icon"><?= $ico('<rect x="3" y="4" width="7" height="7" rx="2"/><rect x="14" y="4" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>') ?></span></span>
        <span class="kpi-value"><?= (int)$stats['cohorts'] ?></span>
        <span class="kpi-foot"><?= $noSubject ? "$noSubject sans matière" : 'toutes avec matières' ?></span>
      </a>
      <a class="card kpi reveal" style="--d:.16s" href="./agenda.php">
        <span class="kpi-top"><span class="kpi-label">QCM programmés</span><span class="kpi-icon"><?= $ico('<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>') ?></span></span>
        <span class="kpi-value"><?= (int)$quizStats['active'] ?></span>
        <span class="kpi-foot"><?= (int)$quizStats['quizzes'] ?> QCM dans <?= (int)$quizStats['plans'] ?> cours</span>
      </a>
    </div>

    <!-- Graphique -->
    <article class="card chart-card reveal" style="--d:.08s">
      <div class="card-head">
        <div><h2>Inscriptions par semaine</h2><p class="muted small">8 dernières semaines</p></div>
        <div class="chart-legend"><span><i class="lg-fresh"></i>À exporter</span><span><i class="lg-exported"></i>Exportés</span></div>
      </div>
      <?php
        $W = 560; $H = 230; $padL = 34; $padB = 26; $padT = 8;
        $plotH = $H - $padB - $padT; $plotW = $W - $padL; $slot = $plotW / count($weeks); $bw = min(30, $slot * .5);
      ?>
      <svg class="chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Inscriptions par semaine sur 8 semaines">
        <defs>
          <pattern id="stripes" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <rect width="6" height="6" fill="#f98012"/><rect width="2.5" height="6" fill="#fdba74"/>
          </pattern>
        </defs>
        <?php for ($g = 0; $g <= 4; $g++): $y = $padT + $plotH - $plotH * $g / 4; ?>
          <line x1="<?= $padL ?>" x2="<?= $W ?>" y1="<?= $y ?>" y2="<?= $y ?>" class="grid"/>
          <text x="<?= $padL - 8 ?>" y="<?= $y + 4 ?>" class="axis" text-anchor="end"><?= (int)($chartMax * $g / 4) ?></text>
        <?php endfor; ?>
        <?php foreach ($weeks as $i => $w):
          $x = $padL + $slot * $i + ($slot - $bw) / 2;
          $hE = $plotH * $w['exported'] / $chartMax; $hF = $plotH * $w['fresh'] / $chartMax;
          $yE = $padT + $plotH - $hE; $yF = $yE - $hF; ?>
          <g class="bar">
            <title>Semaine du <?= e($w['label']) ?> : <?= $w['exported'] + $w['fresh'] ?> inscription(s) — <?= $w['exported'] ?> exportée(s), <?= $w['fresh'] ?> à exporter</title>
            <rect x="<?= $padL + $slot * $i ?>" y="<?= $padT ?>" width="<?= $slot ?>" height="<?= $plotH ?>" class="hit"/>
            <?php if ($hF > 0): ?><rect x="<?= $x ?>" y="<?= $yF ?>" width="<?= $bw ?>" height="<?= $hF ?>" rx="6" fill="url(#stripes)"/><?php endif; ?>
            <?php if ($hE > 0): ?><rect x="<?= $x ?>" y="<?= $yE ?>" width="<?= $bw ?>" height="<?= $hE ?>" rx="6" class="exported"/><?php endif; ?>
            <?php if ($hE + $hF == 0): ?><rect x="<?= $x ?>" y="<?= $padT + $plotH - 3 ?>" width="<?= $bw ?>" height="3" rx="1.5" class="zero"/><?php endif; ?>
            <text x="<?= $x + $bw / 2 ?>" y="<?= $H - 6 ?>" class="axis" text-anchor="middle"><?= e($w['label']) ?></text>
          </g>
        <?php endforeach; ?>
      </svg>
    </article>
  </section>

  <section class="dash-grid dash-grid-2">
    <div class="dash-col">
      <article class="card reveal">
        <div class="card-head"><h2>À traiter</h2><?php if (!$todo): ?><span class="tag tag-done">Tout est à jour</span><?php endif; ?></div>
        <?php if ($todo): ?>
          <ul class="todo">
            <?php foreach ($todo as [$level, $text, $cta, $href]): ?>
              <li class="todo-<?= $level ?>">
                <span class="todo-dot" aria-hidden="true"></span>
                <span class="todo-text"><strong><?= e($text) ?></strong><a href="<?= e($href) ?>"><?= e($cta) ?> →</a></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="muted small">Aucun doublon, aucun étudiant à exporter, toutes les cohortes ont leurs matières.</p>
        <?php endif; ?>
      </article>

      <article class="card reveal" style="--d:.06s">
        <div class="card-head"><h2>Répartition par section</h2><a class="link small" href="./cohorts.php">Cohortes</a></div>
        <?php if ($sections): ?>
          <ul class="bar-list">
            <?php foreach ($sections as $sec): $pct = round((int)$sec['n'] / $sectionMax * 100); ?>
              <li>
                <span class="bar-list-label" title="<?= e($sec['section_label']) ?>"><?= e(str_ireplace('INSTITUT DE FORMATION SAINTE MARIE', 'IFSM', $sec['section_label'])) ?></span>
                <span class="bar-list-track"><span style="width: <?= $pct ?>%"></span></span>
                <span class="bar-list-n"><?= (int)$sec['n'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?><p class="empty">Aucune inscription pour l'instant.</p><?php endif; ?>
      </article>
    </div>

    <div class="dash-col">
      <article class="card reveal" style="--d:.04s">
        <div class="card-head"><h2>Inscriptions récentes</h2><a class="btn btn-sm" href="./inscriptions.php">Tout voir</a></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Étudiant</th><th>Cohorte</th><th>Inscrit le</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td><a class="person" href="./edit.php?<?= e(http_build_query(['id' => (int)$r['id'], 'back' => 'index.php'])) ?>">
                  <span class="person-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($r['firstname'], 0, 1) . mb_substr($r['lastname'], 0, 1))) ?></span>
                  <span class="person-name"><span><strong><?= e($r['lastname']) ?></strong> <?= e($r['firstname']) ?></span><small><?= e($r['username']) ?></small></span></a></td>
                <td><span class="pill"><?= e($r['cohort']) ?></span></td>
                <td class="small muted nowrap"><?= e(date('d/m/Y', strtotime($r['created_at']))) ?><br><?= e(date('H:i', strtotime($r['created_at']))) ?></td>
                <td><?= $r['exported_at'] ? '<span class="tag tag-done">Exporté</span>' : '<span class="tag tag-new">Nouveau</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="4" class="empty">Aucune inscription pour l'instant.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </article>

      <article class="card reveal" style="--d:.08s">
        <div class="card-head"><h2>Prochaines programmations</h2><a class="btn btn-sm" href="./agenda.php">Agenda</a></div>
        <?php if ($upcoming): ?>
          <ul class="sched-list">
            <?php foreach ($upcoming as $sc): $stt = schedule_status($sc); $o = strtotime($sc['opens_at']); ?>
              <li>
                <span class="sched-date"><strong><?= date('d', $o) ?></strong><small><?= ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int)date('n', $o) - 1] ?></small></span>
                <a class="sched-info" href="./quiz-agenda.php?plan=<?= (int)$sc['plan_id'] ?>&amp;item=<?= (int)$sc['item_id'] ?>">
                  <strong><?= e($sc['quiz']) ?></strong>
                  <small><?= e($sc['plan']) ?> · <?= e($sc['cohort']) ?></small>
                </a>
                <span class="sched-badge ev-<?= $stt ?>"><?= e(schedule_hours($sc)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="empty">Aucune programmation dans les 30 prochains jours. <a class="link" href="./programmes.php">Programmer un QCM</a></p>
        <?php endif; ?>
      </article>
    </div>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
