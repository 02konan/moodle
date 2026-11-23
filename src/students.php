<?php
declare(strict_types=1);

/**
 * Règles communes à l'inscription (api/register.php) et à la modification (admin/edit.php).
 * Nécessite src/Referentiel.php.
 */

/** Nettoie et valide l'identité. Retourne [données normalisées, erreurs par champ]. */
function validate_identity(array $in): array
{
    $data = [
        'firstname' => trim((string)($in['firstname'] ?? '')),
        'lastname'  => trim((string)($in['lastname'] ?? '')),
        'email'     => mb_strtolower(trim((string)($in['email'] ?? '')), 'UTF-8'),
        'username'  => clean_username((string)($in['username'] ?? '')),
    ];
    $errors = [];
    $nameOk = fn(string $v) => $v !== '' && mb_strlen($v) <= 100 && preg_match('/^[\p{L}\p{M}\'’\- ]+$/u', $v);
    if (!$nameOk($data['firstname'])) $errors['firstname'] = 'Prénom invalide.';
    if (!$nameOk($data['lastname']))  $errors['lastname']  = 'Nom invalide.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 190) $errors['email'] = 'Adresse e-mail invalide.';
    if (strlen($data['username']) < 3 || strlen($data['username']) > 50) $errors['username'] = "Nom d'utilisateur : 3 à 50 caractères (lettres minuscules et chiffres).";

    $data['firstname'] = mb_convert_case($data['firstname'], MB_CASE_TITLE, 'UTF-8');
    $data['lastname']  = mb_strtoupper($data['lastname'], 'UTF-8');
    return [$data, $errors];
}

/**
 * Revalide le parcours auprès de l'API référentiel (cascade complète).
 * Retourne les colonnes à enregistrer (libellés + cohorte) ou null si incohérent.
 * @throws RuntimeException si l'API est indisponible
 */
function resolve_parcours(int $sectionId, int $cycleId, int $levelId, int $classId): ?array
{
    $section = Referentiel::find(Referentiel::get('sections'), $sectionId);
    $cycle = $section ? Referentiel::find(Referentiel::get('cycles', ['section_id' => $sectionId]), $cycleId) : null;
    $level = $cycle ? Referentiel::find(Referentiel::get('niveaux', ['section_id' => $sectionId, 'cycle_id' => $cycleId]), $levelId) : null;
    $class = $level ? Referentiel::find(Referentiel::get('classes', ['section_id' => $sectionId, 'cycle_id' => $cycleId, 'niveau_id' => $levelId]), $classId) : null;
    if (!$section || !$cycle || !$level || !$class) return null;

    $sectionCode = $section['code'] ?? $section['label'];
    return [
        'section_id' => $section['id'], 'section_code' => $sectionCode, 'section_label' => $section['label'],
        'cycle_id'   => $cycle['id'],   'cycle_label'  => $cycle['label'],
        'level_id'   => $level['id'],   'level_label'  => $level['label'],
        'class_id'   => $class['id'],   'class_label'  => $class['label'],
        'cohort'     => cohort_name($class['label'], $sectionCode),
    ];
}

/** Message d'erreur pour un doublon (code MySQL 1062), ou null si ce n'est pas un doublon. */
function duplicate_error(PDOException $ex): ?array
{
    if (($ex->errorInfo[1] ?? 0) !== 1062) return null;
    if (stripos($ex->getMessage(), 'email') !== false) {
        return ['Cette adresse e-mail est déjà inscrite.', ['email' => 'Déjà inscrite.']];
    }
    return ["Ce nom d'utilisateur est déjà pris.", ['username' => 'Déjà pris, essayez-en un autre.']];
}

/**
 * Clé de comparaison des noms pour repérer les doublons : majuscules, accents, ponctuation
 * et ordre des mots ignorés (« KONAN Ama », « Konan  Ama », « Ama KONAN » → « ama konan »).
 */
function name_key(string $lastname, string $firstname): string
{
    $words = preg_split('/[^a-z0-9]+/', ascii($lastname . ' ' . $firstname), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    sort($words);
    return implode(' ', $words);
}
