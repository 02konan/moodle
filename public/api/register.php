<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Referentiel.php';

// Définir le fuseau horaire au début du fichier
date_default_timezone_set('Africa/Abidjan'); // Côte d'Ivoire

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
$firstname = trim((string)($in['firstname'] ?? ''));
$lastname  = trim((string)($in['lastname'] ?? ''));
$email     = mb_strtolower(trim((string)($in['email'] ?? '')), 'UTF-8');
$username  = clean_username((string)($in['username'] ?? ''));
$sectionId = (int)($in['section_id'] ?? 0);
$cycleId   = (int)($in['cycle_id'] ?? 0);
$levelId   = (int)($in['level_id'] ?? 0);
$classId   = (int)($in['class_id'] ?? 0);
$dateConnexion = date('Y-m-d H:i:s');

$errors = [];
$nameOk = fn(string $v) => $v !== '' && mb_strlen($v) <= 100 && preg_match('/^[\p{L}\p{M}\'’\- ]+$/u', $v);
if (!$nameOk($firstname)) $errors['firstname'] = 'Prénom invalide.';
if (!$nameOk($lastname))  $errors['lastname']  = 'Nom invalide.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) $errors['email'] = 'Adresse e-mail invalide.';
if (strlen($username) < 3 || strlen($username) > 50) $errors['username'] = "Nom d'utilisateur : 3 à 50 caractères (lettres minuscules et chiffres).";
if ($errors) json_out(['error' => 'Vérifiez les champs signalés.', 'fields' => $errors], 422);

// --- Le parcours est revalidé auprès de l'API (cascade complète) ---
try {
    $section = Referentiel::find(Referentiel::get('sections'), $sectionId);
    $cycle = $section ? Referentiel::find(Referentiel::get('cycles', ['section_id' => $sectionId]), $cycleId) : null;
    $level = $cycle ? Referentiel::find(Referentiel::get('niveaux', ['section_id' => $sectionId, 'cycle_id' => $cycleId]), $levelId) : null;
    $class = $level ? Referentiel::find(Referentiel::get('classes', ['section_id' => $sectionId, 'cycle_id' => $cycleId, 'niveau_id' => $levelId]), $classId) : null;
} catch (RuntimeException $e) {
    error_log('[register] ' . $e->getMessage());
    json_out(['error' => 'Service indisponible, réessayez dans un instant.'], 502);
}
if (!$section || !$cycle || !$level || !$class) {
    json_out(['error' => 'Parcours incohérent, recommencez la sélection.'], 422);
}

try {
    $pdo = db();
    $firstname = mb_convert_case($firstname, MB_CASE_TITLE, 'UTF-8');
    $lastname  = mb_strtoupper($lastname, 'UTF-8');
    $sectionCode = $section['code'] ?? $section['label'];
    $cohort = cohort_name($class['label'], $sectionCode);

    $password = make_password($lastname);

    $insert = $pdo->prepare(
        'INSERT INTO students (lastname, firstname, username, email, password, city,
            section_id, section_code, section_label, cycle_id, cycle_label,
            level_id, level_label, class_id, class_label, cohort, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    try {
        $insert->execute([
            $lastname, $firstname, $username, $email, $password, CITY,
            $section['id'], $sectionCode, $section['label'], $cycle['id'], $cycle['label'],
            $level['id'], $level['label'], $class['id'], $class['label'], $cohort, $dateConnexion
        ]);
        json_out(['ok' => true, 'cohort' => $cohort, 'username' => $username, 'password' => $password]);
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? 0) !== 1062) throw $ex;
        if (stripos($ex->getMessage(), 'email') !== false) {
            json_out(['error' => 'Cette adresse e-mail est déjà inscrite.', 'fields' => ['email' => 'Déjà inscrite.']], 409);
        }
        json_out(['error' => "Ce nom d'utilisateur est déjà pris.", 'fields' => ['username' => 'Déjà pris, essayez-en un autre.']], 409);
    }
} catch (Throwable $e) {
    error_log('[register] ' . $e->getMessage());
    json_out(['error' => 'Erreur serveur, réessayez plus tard.'], 500);
}
