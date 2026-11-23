<?php
declare(strict_types=1);

/**
 * Doublons : regroupement par nom et plan de nettoyage d'après les connexions Moodle.
 * Nécessite src/students.php (name_key) et, pour le plan, src/MoodleApi.php.
 */

/** Groupes d'étudiants portant le même nom : ['ama konan' => [ligne, ligne…], …], filtrés par $q. */
function duplicate_groups(PDO $pdo, string $q = ''): array
{
    $rows = $pdo->query(
        'SELECT id, lastname, firstname, username, email, password, cohort, created_at, exported_at FROM students ORDER BY created_at, id'
    )->fetchAll();
    $groups = [];
    foreach ($rows as $r) {
        $key = name_key($r['lastname'], $r['firstname']);
        if ($key !== '') $groups[$key][] = $r;
    }
    $groups = array_filter($groups, fn($g) => count($g) > 1);
    if ($q !== '') {
        $words = preg_split('/[^a-z0-9]+/', ascii($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $groups = array_filter($groups, function ($g, $key) use ($words) {
            foreach ($words as $w) if (!str_contains((string)$key, $w)) return false;
            return true;
        }, ARRAY_FILTER_USE_BOTH);
    }
    uksort($groups, 'strnatcasecmp');
    return $groups;
}

/**
 * Plan de nettoyage d'après Moodle. Règles, par groupe :
 *  - un compte qui s'est déjà connecté est toujours gardé ;
 *  - les comptes jamais connectés et les inscriptions absentes de Moodle sont supprimés
 *    dès qu'au moins un compte du groupe a été utilisé ;
 *  - si aucun compte n'a été utilisé, l'inscription la plus récente est gardée.
 * Chaque membre reçoit : moodle (null si absent), used, action (keep|delete), reason.
 * @throws RuntimeException si Moodle est injoignable ou le jeton insuffisant
 */
function moodle_cleanup_plan(array $groups, MoodleApi $api): array
{
    $usernames = [];
    foreach ($groups as $g) foreach ($g as $s) $usernames[] = mb_strtolower($s['username']);
    $accounts = $usernames ? $api->usersByUsername($usernames) : [];

    $plan = [];
    foreach ($groups as $key => $g) {
        $members = [];
        foreach ($g as $s) {
            $m = $accounts[mb_strtolower($s['username'])] ?? null;
            $s['moodle'] = $m;
            $s['used'] = $m !== null && max($m['firstaccess'], $m['lastaccess']) > 0;
            $members[] = $s;
        }
        $used = array_filter($members, fn($s) => $s['used']);
        $note = null;
        if ($used) {
            if (count($used) > 1) $note = 'Plusieurs comptes ont déjà été utilisés : ils sont tous gardés, à vérifier à la main.';
            foreach ($members as &$s) {
                if ($s['used']) {
                    $s['action'] = 'keep';
                    $s['reason'] = 'Déjà connecté (dernier accès le ' . date('d/m/Y', max($s['moodle']['lastaccess'], $s['moodle']['firstaccess'])) . ')';
                } else {
                    $s['action'] = 'delete';
                    $s['reason'] = $s['moodle'] ? 'Jamais connecté : supprimé dans Moodle et dans la base' : 'Absent de Moodle : supprimé de la base';
                }
            }
            unset($s);
        } else {
            // Aucun compte utilisé : on garde la dernière inscription (souvent la correction d'une erreur de classe)
            $latest = array_key_last($members);   // les lignes sont triées par date d'inscription
            foreach ($members as $i => &$s) {
                if ($i === $latest) {
                    $s['action'] = 'keep';
                    $s['reason'] = 'Aucun compte utilisé : inscription la plus récente gardée';
                } else {
                    $s['action'] = 'delete';
                    $s['reason'] = $s['moodle'] ? 'Jamais connecté : supprimé dans Moodle et dans la base' : 'Absent de Moodle : supprimé de la base';
                }
            }
            unset($s);
        }
        $plan[$key] = ['members' => $members, 'note' => $note];
    }
    return $plan;
}

/** Résumé chiffré d'un plan. */
function plan_summary(array $plan): array
{
    $sum = ['moodle' => 0, 'db' => 0, 'kept' => 0, 'notes' => 0];
    foreach ($plan as $p) {
        if ($p['note']) $sum['notes']++;
        foreach ($p['members'] as $s) {
            if ($s['action'] === 'keep') { $sum['kept']++; continue; }
            $sum['db']++;
            if ($s['moodle']) $sum['moodle']++;
        }
    }
    return $sum;
}
