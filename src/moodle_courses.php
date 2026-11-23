<?php
declare(strict_types=1);

/**
 * Cours et groupes Moodle déduits automatiquement (colonnes courseN / groupN du CSV).
 *
 *   cohorte « BTS-2-FC-03-ANY » + matières saisies dans « Matières par cohorte »
 *     → course1 = « BTS 2 FC: MATHÉMATIQUES GÉNÉRALES »   group1 = « GP/BTS-2-FC-03-ANY »
 *     → course2 = « BTS 2 FC: COMPTABILITÉ »              group2 = « GP/BTS-2-FC-03-ANY »
 * Le nom du cours doit être identique au nom abrégé dans Moodle ; sinon, le remplacer
 * dans « Noms des cours » (table course_overrides).
 * Le groupe est créé par Moodle dans chaque cours s'il n'existe pas.
 */

const GROUP_PREFIX = 'GP/';

function moodle_group_name(string $cohort): string
{
    return GROUP_PREFIX . $cohort;
}

/** Filière de la cohorte (section et numéro de classe retirés) : « BTS-2-FC-03-ANY » → « BTS 2 FC ». */
function moodle_course_prefix(string $cohort, string $sectionCode = ''): string
{
    $suffix = '-' . slug_upper($sectionCode);
    if ($sectionCode !== '' && str_ends_with($cohort, $suffix)) {
        $cohort = substr($cohort, 0, -strlen($suffix));
        $tokens = explode('-', $cohort);
    } else {
        $tokens = explode('-', $cohort);
        if (count($tokens) >= 2) array_pop($tokens); // section = dernier segment
    }
    if (count($tokens) >= 2 && ctype_digit(end($tokens))) array_pop($tokens);
    return implode(' ', array_filter($tokens, fn($t) => $t !== ''));
}

/** Nom abrégé Moodle : « BTS 2 FC: MATHÉMATIQUES GÉNÉRALES ». */
function moodle_course_name(string $prefix, string $subject): string
{
    $subject = preg_replace('/\s+/u', ' ', trim($subject)) ?? '';
    return $prefix . ': ' . mb_strtoupper($subject, 'UTF-8');
}

/** Noms générés automatiquement pour la cohorte, un par matière affectée. */
function moodle_auto_courses_for(string $cohort, string $sectionCode, array $subjectsByCohort): array
{
    $prefix = moodle_course_prefix($cohort, $sectionCode);
    return array_map(fn($s) => moodle_course_name($prefix, $s), $subjectsByCohort[$cohort] ?? []);
}

/** Cours de la cohorte, avec les noms personnalisés appliqués. */
function moodle_courses_for(string $cohort, string $sectionCode, array $subjectsByCohort, array $overrides = []): array
{
    return array_map(fn($n) => $overrides[$n] ?? $n, moodle_auto_courses_for($cohort, $sectionCode, $subjectsByCohort));
}

/** ['BTS 2 FC: MATHÉMATIQUES GÉNÉRALES' => 'BTS 2 FCGE: MATHÉMATIQUES GÉNÉRALES', ...] */
function course_overrides(PDO $pdo): array
{
    return $pdo->query('SELECT auto_name, moodle_name FROM course_overrides')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** ['BTS-2-FC-03-ANY' => ['COMPTABILITÉ', 'MATHÉMATIQUES GÉNÉRALES'], ...] */
function subjects_by_cohort(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT cs.cohort, s.label FROM cohort_subjects cs JOIN subjects s ON s.id = cs.subject_id ORDER BY cs.cohort, s.label'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['cohort']][] = $r['label'];
    return $out;
}

/** Nom de famille envoyé à Moodle (CSV et service web) : « KONAN (BTS-2-FC-03-ANGRE) ». */
function moodle_lastname(array $student): string
{
    return $student['lastname'] . ' (' . $student['cohort'] . ')';
}

/**
 * Cohortes de la même filière que $cohort (« BTS-2-ELT-01-ANGRE » → toutes les « BTS 2 ELT », toutes sections).
 * Retourne ['prefix' => 'BTS 2 ELT', 'others' => ['BTS-2-ELT-02-ANGRE', 'BTS-2-ELT-01-YOP', …]].
 */
function filiere_cohorts(PDO $pdo, string $cohort): array
{
    $rows = $pdo->query("SELECT cohort, MIN(section_code) section_code FROM students WHERE cohort <> '' GROUP BY cohort")->fetchAll();
    $codes = array_column($rows, 'section_code', 'cohort');
    $prefix = moodle_course_prefix($cohort, (string)($codes[$cohort] ?? ''));
    $others = [];
    foreach ($codes as $c => $code) {
        if ($c !== $cohort && $prefix !== '' && moodle_course_prefix((string)$c, (string)$code) === $prefix) $others[] = (string)$c;
    }
    natcasesort($others);
    return ['prefix' => $prefix, 'others' => array_values($others)];
}

/**
 * Copie des matières dans d'autres cohortes, sans toucher aux matières qu'elles ont déjà
 * (enseignant et heures repris de la ligne d'origine). Retourne le nombre de lignes ajoutées.
 * $rows : [['subject_id' => …, 'teacher_id' => …, 'hours' => …], …]
 */
function copy_subjects_to_cohorts(PDO $pdo, array $rows, array $cohorts): int
{
    $ins = $pdo->prepare('INSERT IGNORE INTO cohort_subjects (cohort, subject_id, teacher_id, hours) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach ($cohorts as $c) foreach ($rows as $r) {
        $ins->execute([$c, (int)$r['subject_id'], (int)$r['teacher_id'], $r['hours'] !== null ? (int)$r['hours'] : null]);
        $n += $ins->rowCount();
    }
    return $n;
}
