<?php
declare(strict_types=1);
// Envoi de l'agenda d'un cours (ou d'un QCM) vers Moodle : dérogations de groupe des tests. POST : csrf, plan, item? → rapport JSON.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/MoodleApi.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/course_plans.php';
require __DIR__ . '/../../src/agenda.php';
require __DIR__ . '/../../src/moodle_agenda.php';
require_admin();

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Méthode non autorisée'], 405);
if (!csrf_valid($_POST['csrf'] ?? null)) json_out(['error' => 'Session expirée, rechargez la page.'], 419);

$api = MoodleApi::fromEnv();
if (!$api) json_out(['error' => 'Service web Moodle non configuré (MOODLE_URL / MOODLE_TOKEN).'], 503);

$planId = (int)($_POST['plan'] ?? 0);
$itemId = (int)($_POST['item'] ?? 0) ?: null;

@set_time_limit(300);
try {
    json_out(['ok' => true, 'report' => moodle_push_agenda(db(), $api, $planId, $itemId)]);
} catch (RuntimeException $ex) {
    error_log('[moodle-agenda] ' . $planId . ' : ' . $ex->getMessage());
    json_out(['error' => $ex->getMessage()], 502);
}
