<?php
declare(strict_types=1);
// Données des exports PDF de l'agenda (général et par cohorte) : programmations qui chevauchent la période.
// GET : from, to (AAAA-MM-JJ, inclus), cohort, course (filtres de la page Agenda). Réservé à l'admin.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/agenda.php';

if (empty($_SESSION['admin_id'])) json_out(['error' => 'Session expirée, reconnectez-vous.'], 401);
header('Cache-Control: no-store');

$from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['from'] ?? ''));
$to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['to'] ?? ''));
if (!$from || !$to) json_out(['error' => 'Période invalide : choisissez les dates de début et de fin.'], 400);
if ($to < $from) json_out(['error' => 'La date de fin doit être postérieure à la date de début.'], 400);
if ($from->diff($to)->days > 400) json_out(['error' => 'Période trop longue (400 jours maximum).'], 400);

$days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$long = fn(int $t) => $days[(int)date('w', $t)] . ' ' . date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);

$rows = agenda_schedules($pdo = db(), $from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00'),
    trim((string)($_GET['cohort'] ?? '')), trim((string)($_GET['course'] ?? '')));

$out = [];
foreach ($rows as $s) {
    $o = strtotime($s['opens_at']);
    $out[] = [
        'day'    => ucfirst($long($o)),
        'hours'  => schedule_hours($s),
        'opens'  => date('d/m/Y H:i', $o),
        'closes' => date('d/m/Y H:i', strtotime($s['closes_at'])),
        'plan'   => $s['plan'],
        'quiz'   => $s['quiz'],
        'cohort' => $s['cohort'],
        'status' => SCHEDULE_STATUS[schedule_status($s)],
    ];
}

json_out([
    'generated' => date('d/m/Y à H:i'),
    'period'    => 'Du ' . $from->format('d/m/Y') . ' au ' . $to->format('d/m/Y'),
    'filters'   => array_values(array_filter([
        ($c = trim((string)($_GET['cohort'] ?? ''))) !== '' ? "Cohorte : $c" : null,
        ($p = trim((string)($_GET['course'] ?? ''))) !== '' ? "Cours : $p" : null,
    ])),
    'schedules' => $out,
]);
