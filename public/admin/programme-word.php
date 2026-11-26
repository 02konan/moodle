<?php
declare(strict_types=1);
// Téléchargement de la fiche Word « Programmation des évaluations » d'un cours.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/ProgrammationDocx.php';
require_admin();

$pdo = db();
$plan = plan_get($pdo, (int)($_GET['id'] ?? 0));
if (!$plan) { flash('Cours introuvable.', 'error'); redirect('./programmes.php'); }

$docx = (new ProgrammationDocx($plan, plan_tree($pdo, (int)$plan['id']), ROOT . '/public/assets/logo.png'))->build();

// Nom de fichier : programmation-<titre>-<date>-<heure>.docx
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', ascii($plan['title'])) ?? '', '-') ?: 'cours';
$name = 'programmation-' . substr($slug, 0, 80) . '-' . date('Y-m-d-H\hi') . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($docx));
header('Cache-Control: no-store');
echo $docx;
