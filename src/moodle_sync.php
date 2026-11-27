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
    // Un compte refusé peut quand même avoir été créé (ex. « Message was not sent » à l'envoi de l'e-mail) : on vérifie
    if ($report['failed']) {
        $found = $api->usersByUsername(array_column($report['failed'], 'username'));
        foreach ($found as $username => $u) $ids[$username] = $u['id'];
        $report['failed'] = array_values(array_filter($report['failed'], fn($f) => !isset($found[mb_strtolower($f['username'])])));
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

    $noListing = false;
    foreach ($courses as $shortname) {
        $line = ['shortname' => $shortname, 'found' => false, 'enrolled' => 0, 'already_enrolled' => 0, 'group_created' => false,
                 'group_added' => 0, 'already_in_group' => 0, 'message_failed' => false, 'error' => null];
        $course = null;
        try {
            $course = $api->courseByShortname($shortname);
            if (!$course) $line['error'] = 'Cours introuvable dans Moodle (vérifiez « Noms des cours »)';
        } catch (RuntimeException $ex) {
            $line['error'] = $ex->getMessage();
        }
        if ($course) {
            $line['found'] = true;
            $all = array_values(array_map('intval', $ids));
            // Inscription : seulement les étudiants pas encore inscrits (si Moodle permet de lister les inscrits)
            $enrolled = $api->enrolledUserIds($course['id']);
            if ($enrolled === null) $noListing = true;
            $toEnrol = $enrolled === null ? $all : array_values(array_diff($all, $enrolled));
            $line['already_enrolled'] = count($all) - count($toEnrol);
            $inCourse = $all;   // étudiants inscrits au cours après cette étape
            try {
                if ($toEnrol) $line['message_failed'] = $api->enrolUsers($course['id'], $toEnrol, $roleId);
                $line['enrolled'] = count($toEnrol);
            } catch (RuntimeException $ex) {
                $line['error'] = 'Inscription : ' . $ex->getMessage();
                $inCourse = array_values(array_intersect($all, $enrolled ?? []));   // seuls ceux déjà inscrits avant
            }
            // Groupe : traité même si l'envoi du message de bienvenue a échoué
            if ($inCourse) try {
                $groups = $api->courseGroups($course['id']);
                $groupId = $groups[$group] ?? null;
                if (!$groupId) { $groupId = $api->createGroup($course['id'], $group); $line['group_created'] = true; }
                $members = $line['group_created'] ? [] : $api->groupMemberIds($groupId);
                if ($members === null) $noListing = true;
                $toAdd = $members === null ? $inCourse : array_values(array_diff($inCourse, $members));
                $line['already_in_group'] = count($inCourse) - count($toAdd);
                if ($toAdd) $api->addGroupMembers($groupId, $toAdd);
                $line['group_added'] = count($toAdd);
            } catch (RuntimeException $ex) {
                $line['error'] = trim(($line['error'] ? $line['error'] . ' · ' : '') . 'Groupe : ' . $ex->getMessage());
            }
        }
        $report['courses'][] = $line;
    }

    if ($noListing) $report['warnings'][] = "Ajoutez core_enrol_get_enrolled_users et core_group_get_group_members au service web Moodle : sans elles, tous les étudiants sont renvoyés à Moodle à chaque fois, même ceux déjà inscrits.";
    if (array_filter(array_column($report['courses'], 'message_failed'))) {
        $report['warnings'][] = "Moodle n'a pas pu envoyer le message de bienvenue au cours (« Message was not sent ») : les inscriptions et les groupes sont faits. Désactivez ce message dans l'inscription manuelle ou configurez l'e-mail sortant de Moodle.";
    }

    /* 4. Les étudiants présents dans Moodle passent en « Exporté » */
    $synced = [];
    foreach ($ids as $username => $id) $synced[] = (int)$byUsername[$username]['id'];
    $in = implode(',', array_fill(0, count($synced), '?'));
    $pdo->prepare("UPDATE students SET exported_at = ? WHERE exported_at IS NULL AND id IN ($in)")->execute([now_sql(), ...$synced]);

    return $report;
}
