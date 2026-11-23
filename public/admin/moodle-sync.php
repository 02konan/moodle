<?php
declare(strict_types=1);
// Création dans Moodle d'une cohorte (comptes, cohorte, inscriptions, groupes). POST : csrf, cohort → rapport JSON.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/MoodleApi.php';
require __DIR__ . '/../../src/moodle_courses.php';
require __DIR__ . '/../../src/moodle_sync.php';
require_admin();

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Méthode non autorisée'], 405);
if (!csrf_valid($_POST['csrf'] ?? null)) json_out(['error' => 'Session expirée, rechargez la page.'], 419);

$api = MoodleApi::fromEnv();
if (!$api) json_out(['error' => 'Service web Moodle non configuré (MOODLE_URL / MOODLE_TOKEN).'], 503);

$cohort = trim((string)($_POST['cohort'] ?? ''));
if ($cohort === '') json_out(['error' => 'Cohorte manquante.'], 400);

@set_time_limit(300);
try {
    json_out(['ok' => true, 'report' => moodle_sync_cohort(db(), $api, $cohort)]);
} catch (RuntimeException $ex) {
    error_log('[moodle-sync] ' . $cohort . ' : ' . $ex->getMessage());
    json_out(['error' => $ex->getMessage()], 502);
}
