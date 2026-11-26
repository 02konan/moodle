<?php
declare(strict_types=1);
// Données de l'agenda PDF d'un cours (plan=ID) : chaque QCM avec ses cohortes programmées. Réservé à l'admin.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/agenda.php';

if (empty($_SESSION['admin_id'])) json_out(['error' => 'Session expirée, reconnectez-vous.'], 401);
header('Cache-Control: no-store');

$pdo = db();
$plan = plan_get($pdo, (int)($_GET['plan'] ?? 0));
if (!$plan) json_out(['error' => 'Cours introuvable.'], 404);

$schedules = plan_schedules($pdo, (int)$plan['id']);
$fmt = fn(string $dt) => date('d/m/Y H:i', strtotime($dt));
$quizzes = [];
foreach (plan_quizzes(plan_tree($pdo, (int)$plan['id'])) as $q) {
    $quizzes[] = [
        'section'    => $q['section'],
        'subsection' => $q['subsection'],
        'quiz'       => $q['title'],
        'schedules'  => array_map(fn($s) => [
            'cohort' => $s['cohort'], 'opens' => $fmt($s['opens_at']), 'closes' => $fmt($s['closes_at']),
            'status' => SCHEDULE_STATUS[schedule_status($s)],
        ], $schedules[$q['id']] ?? []),
    ];
}

json_out(['plan' => $plan['title'], 'generated' => date('d/m/Y à H:i'), 'quizzes' => $quizzes]);
