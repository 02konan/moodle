<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/filters.php';
require __DIR__ . '/../../src/moodle_courses.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
    flash('Requête invalide.', 'error');
    header('Location: /admin/');
    exit;
}

$pdo = db();
[$where, $params] = student_filters($_POST);
$st = $pdo->prepare("SELECT s.id, s.username, s.lastname, s.firstname, s.email, s.password, s.city, s.cohort, s.section_code
                     FROM students s WHERE $where ORDER BY s.cohort, s.lastname, s.firstname");
$st->execute($params);
$rows = $st->fetchAll();

if (!$rows) {
    flash('Aucun étudiant à exporter.', 'error');
    header('Location: /admin/');
    exit;
}

$filename = 'cohortes_' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// Séparateur « ; », CRLF — mêmes colonnes que Cohorte_enseignant_3.csv (nom avant prénom)
// course1 / group1 : déduits de la cohorte (ex. BTS 2 FC + GP/BTS-2-FC-03-ANY)
$cols = ['username', 'firstname', 'lastname', 'email', 'password', 'city', 'cohort1', 'course1', 'group1'];

$buf = fopen('php://temp', 'w+');
fputcsv($buf, $cols, ';', '"', '');
foreach ($rows as $r) {
    $line = [
        $r['username'],
        $r['firstname'],
        $r['lastname'].' ('.$r['cohort'].')',
        $r['email'],
        $r['password'],
        $r['city'],
        $r['cohort'],
        moodle_course_name($r['cohort'], $r['section_code']),
        moodle_group_name($r['cohort']),
    ];
    fputcsv($buf, $line, ';', '"', '');
}
rewind($buf);

$content = (string) stream_get_contents($buf);
fclose($buf);

// Normalise les fins de ligne puis force CRLF (comme le fichier modèle)
$content = str_replace(["\r\n", "\r"], "\n", $content);
$content = str_replace("\n", "\r\n", $content);

// BOM UTF-8 : indispensable pour qu'Excel (FR) reconnaisse l'encodage
echo "\xEF\xBB\xBF" . $content;

if (!empty($_POST['mark'])) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE students SET exported_at = NOW() WHERE id IN ($in) AND exported_at IS NULL")
        ->execute($ids);
}