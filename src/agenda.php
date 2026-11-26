<?php
declare(strict_types=1);

/**
 * Agenda : programmation des QCM par cohorte (ouverture / fermeture).
 * Nécessite src/course_plans.php et src/moodle_courses.php. Table : sql/004-quiz-schedules.sql.
 */

/** QCM d'un cours, dans l'ordre, avec leur emplacement : [['id', 'title', 'section', 'subsection'], …]. */
function plan_quizzes(array $tree): array
{
    $out = [];
    foreach ($tree as $section) {
        foreach ($section['children'] as $child) {
            if ($child['type'] === 'quiz') {
                $out[] = ['id' => (int)$child['id'], 'title' => $child['title'], 'section' => $section['title'], 'subsection' => null];
            } else {
                foreach ($child['children'] as $q) {
                    $out[] = ['id' => (int)$q['id'], 'title' => $q['title'], 'section' => $section['title'], 'subsection' => $child['title']];
                }
            }
        }
    }
    return $out;
}

/** Toutes les cohortes : ['BTS-2-FC-03-ANGRE' => 'ANGRE', …] (cohorte => code de section). */
function all_cohorts(PDO $pdo): array
{
    $rows = $pdo->query("SELECT cohort, MIN(section_code) code FROM students WHERE cohort <> '' GROUP BY cohort")->fetchAll();
    $out = array_column($rows, 'code', 'cohort');
    uksort($out, 'strnatcasecmp');
    return $out;
}

/**
 * Cohortes de la filière d'un cours, d'après son titre :
 * « BTS 2 FC: MATHÉMATIQUES GÉNÉRALES » → filière « BTS 2 FC » → BTS-2-FC-01-ANGRE, BTS-2-FC-03-YOP…
 */
function plan_filiere_cohorts(PDO $pdo, string $planTitle): array
{
    $key = fn(string $v) => trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(ascii($v))) ?? '');   // « Bts 2 fc » = « BTS-2-FC »
    $prefix = $key(explode(':', $planTitle, 2)[0]);
    if ($prefix === '' || !str_contains($planTitle, ':')) return [];
    $out = [];
    foreach (all_cohorts($pdo) as $cohort => $code) {
        if ($key(moodle_course_prefix((string)$cohort, (string)$code)) === $prefix) $out[] = (string)$cohort;
    }
    return $out;
}

/** « 2026-12-01T08:00 » (champ datetime-local) → « 2026-12-01 08:00:00 ». */
function parse_local_datetime(string $v, string $label): string
{
    $d = DateTime::createFromFormat('Y-m-d\TH:i', trim($v)) ?: DateTime::createFromFormat('Y-m-d H:i:s', trim($v));
    if (!$d) throw new RuntimeException("$label : date et heure invalides.");
    return $d->format('Y-m-d H:i:00');
}

function check_window(string $opens, string $closes): void
{
    if (strtotime($closes) <= strtotime($opens)) throw new RuntimeException('La fermeture doit être postérieure à l\'ouverture.');
}

/** Programme un QCM pour plusieurs cohortes ; une cohorte déjà programmée reçoit les nouvelles dates. Retourne [ajoutées, mises à jour]. */
function schedule_quiz(PDO $pdo, int $planId, int $itemId, array $cohorts, string $opens, string $closes): array
{
    $item = plan_item($pdo, $planId, $itemId);
    if (!$item || $item['type'] !== 'quiz') throw new RuntimeException('QCM introuvable.');
    $known = all_cohorts($pdo);
    $cohorts = array_values(array_unique(array_filter(array_map('trim', $cohorts), fn($c) => $c !== '')));
    if (!$cohorts) throw new RuntimeException('Choisissez au moins une cohorte.');
    foreach ($cohorts as $c) if (!isset($known[$c])) throw new RuntimeException("Cohorte inconnue : $c.");
    check_window($opens, $closes);

    $st = $pdo->prepare('INSERT INTO quiz_schedules (item_id, cohort, opens_at, closes_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE opens_at = VALUES(opens_at), closes_at = VALUES(closes_at)');
    $added = $updated = 0;
    foreach ($cohorts as $c) {
        $st->execute([$itemId, $c, $opens, $closes]);
        if ($st->rowCount() === 1) $added++; elseif ($st->rowCount() === 2) $updated++;
    }
    return [$added, $updated];
}

/** Programmations d'un cours : [item_id => [ligne, …]] triées par ouverture. */
function plan_schedules(PDO $pdo, int $planId): array
{
    $st = $pdo->prepare('SELECT s.* FROM quiz_schedules s JOIN course_plan_items i ON i.id = s.item_id
                         WHERE i.plan_id = ? ORDER BY s.opens_at, s.cohort');
    $st->execute([$planId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['item_id']][] = $r;
    return $out;
}

/** Programmation appartenant bien au cours (contrôle avant modification / suppression). */
function plan_schedule(PDO $pdo, int $planId, int $scheduleId): ?array
{
    $st = $pdo->prepare('SELECT s.* FROM quiz_schedules s JOIN course_plan_items i ON i.id = s.item_id WHERE s.id = ? AND i.plan_id = ?');
    $st->execute([$scheduleId, $planId]);
    return $st->fetch() ?: null;
}

/** État d'une programmation : upcoming | open | closed. */
function schedule_status(array $s, ?int $now = null): string
{
    $now ??= time();
    if ($now < strtotime($s['opens_at'])) return 'upcoming';
    return $now < strtotime($s['closes_at']) ? 'open' : 'closed';
}

const SCHEDULE_STATUS = ['upcoming' => 'À venir', 'open' => 'Ouvert', 'closed' => 'Fermé'];

/**
 * Événements du calendrier entre deux dates (ouvertures et fermetures séparées, comme Moodle) :
 * [['at', 'kind' (open|close), 'schedule', 'quiz', 'plan_id', 'plan', 'cohort'], …]
 */
function agenda_events(PDO $pdo, string $from, string $to, string $cohort = '', int $planId = 0, string $course = ''): array
{
    $where = '((s.opens_at >= ? AND s.opens_at < ?) OR (s.closes_at >= ? AND s.closes_at < ?))';
    $params = [$from, $to, $from, $to];
    if ($cohort !== '') { $where .= ' AND s.cohort LIKE ?'; $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $cohort) . '%'; }
    if ($planId > 0)   { $where .= ' AND p.id = ?'; $params[] = $planId; }
    if ($course !== '') { $where .= ' AND p.title LIKE ?'; $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $course) . '%'; }
    $st = $pdo->prepare("SELECT s.*, i.title quiz, p.id plan_id, p.title plan
                         FROM quiz_schedules s JOIN course_plan_items i ON i.id = s.item_id JOIN course_plans p ON p.id = i.plan_id
                         WHERE $where");
    $st->execute($params);
    $events = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['open' => 'opens_at', 'close' => 'closes_at'] as $kind => $col) {
            if ($r[$col] >= $from && $r[$col] < $to) {
                $events[] = ['at' => $r[$col], 'kind' => $kind, 'schedule' => $r, 'quiz' => $r['quiz'], 'plan_id' => (int)$r['plan_id'], 'plan' => $r['plan'], 'cohort' => $r['cohort']];
            }
        }
    }
    usort($events, fn($a, $b) => [$a['at'], $a['kind'] === 'close'] <=> [$b['at'], $b['kind'] === 'close']);
    return $events;
}
