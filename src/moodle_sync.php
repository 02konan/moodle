<?php
declare(strict_types=1);

/**
 * Création automatique dans Moodle d'une cohorte de l'application :
 * comptes, cohorte, inscriptions aux cours de la filière et groupe GP/<cohorte>.
 * Opération rejouable : ce qui existe déjà dans Moodle est réutilisé, rien n'est créé en double.
 * Nécessite src/MoodleApi.php et src/moodle_courses.php.
 */

/** @throws RuntimeException si Moodle est injoignable ou refuse une étape indispensable */
function moodle_sync_cohort(PDO $pdo, MoodleApi $api, string $cohort): array
{
    $st = $pdo->prepare('SELECT * FROM students WHERE cohort = ? ORDER BY lastname, firstname');
    $st->execute([$cohort]);
    $students = $st->fetchAll();
    if (!$students) throw new RuntimeException("La cohorte $cohort ne contient aucun étudiant.");

    $report = [
        'cohort' => $cohort, 'students' => count($students),
        'created' => 0, 'existing' => 0, 'failed' => [],
        'cohort_created' => false, 'courses' => [], 'warnings' => [],
    ];

    /* 1. Comptes : on crée seulement ceux qui n'existent pas encore */
    $byUsername = [];
    foreach ($students as $s) $byUsername[mb_strtolower($s['username'])] = $s;
    $ids = [];
    foreach ($api->usersByUsername(array_keys($byUsername)) as $username => $u) $ids[$username] = $u['id'];
    $report['existing'] = count($ids);

    $toCreate = [];
    foreach ($byUsername as $username => $s) {
        if (isset($ids[$username])) continue;
        $toCreate[$username] = [
            'username'  => $username,
            'password'  => $s['password'],
            'firstname' => $s['firstname'],
            'lastname'  => moodle_lastname($s),
            'email'     => $s['email'],
            'city'      => $s['city'],
        ];
    }
    foreach (array_chunk($toCreate, 20, true) as $batch) {
        try {
            $ids += $api->createUsers($batch);
        } catch (RuntimeException $ex) {
            // Moodle refuse tout le lot au premier compte invalide : on reprend un par un pour isoler l'erreur
            foreach ($batch as $username => $user) {
                try {
                    $ids += $api->createUsers([$user]);
                } catch (RuntimeException $one) {
                    $report['failed'][] = ['username' => $username, 'message' => $one->getMessage()];
                }
            }
        }
    }
    $report['created'] = count($ids) - $report['existing'];
    if (!$ids) return $report;   // aucun compte disponible : rien d'autre à faire

    /* 2. Cohorte (idnumber = nom, comme l'import CSV) */
    if (!$api->cohortByIdnumber($cohort)) {
        $api->createCohort($cohort, $cohort);
        $report['cohort_created'] = true;
    }
    $api->addCohortMembers($cohort, array_keys($ids));

    /* 3. Cours de la filière : inscription + groupe GP/<cohorte> */
    $group = moodle_group_name($cohort);
    $roleId = (int)env('MOODLE_STUDENT_ROLE_ID', '5');   // 5 = rôle « Étudiant » par défaut
    $courses = moodle_courses_for($cohort, $students[0]['section_code'], subjects_by_cohort($pdo), course_overrides($pdo));
    if (!$courses) $report['warnings'][] = "Aucune matière pour cette cohorte : aucun cours ni groupe (voir « Matières par cohorte »).";

    foreach ($courses as $shortname) {
        $line = ['shortname' => $shortname, 'found' => false, 'enrolled' => 0, 'group_created' => false, 'error' => null];
        try {
            $course = $api->courseByShortname($shortname);
            if (!$course) {
                $line['error'] = 'Cours introuvable dans Moodle (vérifiez « Noms des cours »)';
            } else {
                $line['found'] = true;
                $api->enrolUsers($course['id'], array_values($ids), $roleId);
                $line['enrolled'] = count($ids);
                $groups = $api->courseGroups($course['id']);
                $groupId = $groups[$group] ?? null;
                if (!$groupId) { $groupId = $api->createGroup($course['id'], $group); $line['group_created'] = true; }
                $api->addGroupMembers($groupId, array_values($ids));
            }
        } catch (RuntimeException $ex) {
            $line['error'] = $ex->getMessage();
        }
        $report['courses'][] = $line;
    }

    /* 4. Les étudiants présents dans Moodle passent en « Exporté » */
    $synced = [];
    foreach ($ids as $username => $id) $synced[] = (int)$byUsername[$username]['id'];
    $in = implode(',', array_fill(0, count($synced), '?'));
    $pdo->prepare("UPDATE students SET exported_at = NOW() WHERE exported_at IS NULL AND id IN ($in)")->execute($synced);

    return $report;
}
