<?php
declare(strict_types=1);
// Données des listes PDF : étudiants DÉJÀ EXPORTÉS vers Moodle des cohortes demandées (cohorts[]=...), réservé à l'admin.
// Les non exportés sont exclus : leur compte n'existe pas encore dans Moodle.
require __DIR__ . '/../../src/bootstrap.php';

if (empty($_SESSION['admin_id'])) json_out(['error' => 'Session expirée, reconnectez-vous.'], 401);
header('Cache-Control: no-store');

$cohorts = array_values(array_unique(array_filter(
    array_map(fn($c) => trim((string)$c), (array)($_GET['cohorts'] ?? [])),
    fn($c) => $c !== ''
)));
if (!$cohorts) json_out(['error' => 'Aucune cohorte demandée.'], 400);
if (count($cohorts) > 200) json_out(['error' => 'Trop de cohortes (200 maximum par export).'], 400);

$in = implode(',', array_fill(0, count($cohorts), '?'));
$st = db()->prepare(
    "SELECT cohort, lastname, firstname, username, password, section_label, class_label
     FROM students WHERE cohort IN ($in) AND exported_at IS NOT NULL ORDER BY cohort, lastname, firstname"
);
$st->execute($cohorts);

$out = [];
foreach ($st->fetchAll() as $r) {
    $c = $r['cohort'];
    $out[$c] ??= ['cohort' => $c, 'section' => $r['section_label'], 'class' => $r['class_label'], 'students' => []];
    $out[$c]['students'][] = [
        'name'     => trim($r['lastname'] . ' ' . $r['firstname']),
        'username' => $r['username'],
        'password' => $r['password'],
    ];
}
$list = array_values($out);
usort($list, fn($a, $b) => strnatcasecmp($a['cohort'], $b['cohort']));

json_out(['generated' => date('d/m/Y à H:i'), 'cohorts' => $list]);
