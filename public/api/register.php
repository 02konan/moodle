<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Referentiel.php';
require __DIR__ . '/../../src/students.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Méthode non autorisée'], 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['error' => 'Requête invalide'], 400);

if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    json_out(['error' => 'Session expirée, rechargez la page.'], 419);
}
if (!empty($in['registration_reference'])) {
    json_out(['error' => 'Impossible de traiter cette inscription.'], 422);
}

// --- Validation des champs ---
[$id, $errors] = validate_identity($in);
if ($errors) json_out(['error' => 'Vérifiez les champs signalés.', 'fields' => $errors], 422);
$dateConnexion = now_sql(); // heure d'Abidjan (fuseau fixé dans src/bootstrap.php)

// --- Le parcours est revalidé auprès de l'API (cascade complète) ---
try {
    $p = resolve_parcours((int)($in['section_id'] ?? 0), (int)($in['cycle_id'] ?? 0), (int)($in['level_id'] ?? 0), (int)($in['class_id'] ?? 0));
} catch (RuntimeException $e) {
    error_log('[register] ' . $e->getMessage());
    json_out(['error' => 'Service indisponible, réessayez dans un instant.'], 502);
}
if (!$p) json_out(['error' => 'Parcours incohérent, recommencez la sélection.'], 422);

try {
    $pdo = db();
    $cohort   = $p['cohort'];
    $username = $id['username'];
    $password = make_password($id['lastname']);

    $insert = $pdo->prepare(
        'INSERT INTO students (lastname, firstname, username, email, password, city,
            section_id, section_code, section_label, cycle_id, cycle_label,
            level_id, level_label, class_id, class_label, cohort, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    try {
        $insert->execute([
            $id['lastname'], $id['firstname'], $username, $id['email'], $password, CITY,
            $p['section_id'], $p['section_code'], $p['section_label'], $p['cycle_id'], $p['cycle_label'],
            $p['level_id'], $p['level_label'], $p['class_id'], $p['class_label'], $cohort, $dateConnexion
        ]);
        json_out(['ok' => true, 'cohort' => $cohort, 'username' => $username, 'password' => $password]);
    } catch (PDOException $ex) {
        $dup = duplicate_error($ex);
        if (!$dup) throw $ex;
        json_out(['error' => $dup[0], 'fields' => $dup[1]], 409);
    }
} catch (Throwable $e) {
    error_log('[register] ' . $e->getMessage());
    json_out(['error' => 'Erreur serveur, réessayez plus tard.'], 500);
}
