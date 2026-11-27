<?php
declare(strict_types=1);

/**
 * Agenda des QCM → Moodle : chaque programmation (QCM, cohorte, ouverture, fermeture) devient une
 * dérogation du groupe GP/<cohorte> sur le test du même nom dans le cours Moodle. Moodle affiche ces
 * dates dans le calendrier des étudiants du groupe. Rejouable : les dérogations existantes sont mises à jour,
 * celles des groupes GP/… qui ne sont plus programmés sont supprimées.
 * Nécessite src/MoodleApi.php, src/moodle_courses.php, src/course_plans.php et src/agenda.php.
 */

/** Nom comparable : minuscules, sans accent, espaces et ponctuation normalisés. */
function moodle_name_key(string $s): string
{
    $s = mb_strtolower(trim($s));
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($s, Normalizer::FORM_D)) ?? $s;
    } else {   // extension intl absente : accents courants du français
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae']);
    }
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
}

/** Statut d'envoi d'une programmation : none (jamais envoyée), stale (modifiée depuis), synced. */
function schedule_sync_state(array $s): string
{
    if (empty($s['moodle_synced_at'])) return 'none';
    return $s['moodle_synced_at'] < $s['updated_at'] ? 'stale' : 'synced';
}
const SCHEDULE_SYNC_LABELS = ['none' => 'Non envoyé', 'stale' => 'À renvoyer', 'synced' => 'Envoyé'];

/**
 * Envoie l'agenda d'un cours (ou d'un seul QCM si $itemId) vers Moodle.
 * @throws RuntimeException si le cours est introuvable ou Moodle injoignable
 */
function moodle_push_agenda(PDO $pdo, MoodleApi $api, int $planId, ?int $itemId = null): array
{
    $plan = plan_get($pdo, $planId);
    if (!$plan) throw new RuntimeException('Cours introuvable.');
    $shortname = course_overrides($pdo)[$plan['title']] ?? $plan['title'];
    $course = $api->courseByShortname($shortname);
    if (!$course) throw new RuntimeException("Cours « $shortname » introuvable dans Moodle (vérifiez le titre du cours ou « Noms des cours »).");

    $quizzes = $api->quizzesByCourse($course['id']);
    $byName = [];
    foreach ($quizzes as $q) $byName[moodle_name_key($q['name'])][] = $q;
    $groups = $api->courseGroups($course['id']);
    $schedules = plan_schedules($pdo, $planId);
    $items = array_filter(plan_quizzes(plan_tree($pdo, $planId)), fn($q) => $itemId === null || $q['id'] === $itemId);

    $report = ['course' => $shortname, 'quizzes' => [], 'saved' => 0, 'deleted' => 0, 'errors' => 0];
    $mark = $pdo->prepare('UPDATE quiz_schedules SET moodle_synced_at = ? WHERE id = ?');

    foreach ($items as $it) {
        $rows = $schedules[$it['id']] ?? [];
        $line = ['title' => $it['title'], 'quiz' => null, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => [], 'warning' => null];
        $match = $byName[moodle_name_key($it['title'])] ?? [];
        if (!$match) {
            if ($rows) {
                $line['errors'][] = 'Test introuvable dans le cours Moodle : son nom doit être identique au titre du QCM.';
                $report['errors']++;
            } else {
                $line['warning'] = 'Pas de test du même nom dans Moodle (aucune programmation, rien à envoyer).';
            }
            $report['quizzes'][] = $line;
            continue;
        }
        if (count($match) > 1) $line['warning'] = count($match) . ' tests portent ce nom dans Moodle : le premier est utilisé.';
        $quiz = $match[0];
        $line['quiz'] = $quiz['name'];

        try {
            $existing = $api->groupOverrides($quiz['id']);
        } catch (RuntimeException $ex) {
            $line['errors'][] = 'Lecture des dérogations : ' . $ex->getMessage();
            $report['errors']++; $report['quizzes'][] = $line;
            continue;
        }

        $wanted = [];
        foreach ($rows as $s) {
            $group = moodle_group_name($s['cohort']);
            $gid = $groups[$group] ?? null;
            if (!$gid) {
                $line['errors'][] = "$group : groupe absent du cours (lancez « Créer dans Moodle » pour la cohorte {$s['cohort']}).";
                $report['errors']++;
                continue;
            }
            $wanted[$gid] = true;
            $data = ['groupid' => $gid, 'timeopen' => strtotime($s['opens_at']), 'timeclose' => strtotime($s['closes_at'])];
            $old = $existing[$gid] ?? null;
            if ($old) {
                // Mise à jour : les autres réglages de la dérogation (durée, tentatives, mot de passe) sont conservés
                $data = ['id' => (int)$old['id']] + $data
                      + array_filter(['timelimit' => $old['timelimit'] ?? null, 'attempts' => $old['attempts'] ?? null, 'password' => $old['password'] ?? null], fn($v) => $v !== null && $v !== '');
            }
            try {
                $api->saveOverride($quiz['id'], $data);
                $old ? $line['updated']++ : $line['created']++;
                $report['saved']++;
                $mark->execute([now_sql(), $s['id']]);
            } catch (RuntimeException $ex) {
                $line['errors'][] = "$group : " . $ex->getMessage();
                $report['errors']++;
            }
        }

        // Dérogations des groupes GP/… (préfixe GROUP_PREFIX) qui ne sont plus programmés : supprimées (les autres groupes ne sont pas touchés)
        $gpIds = array_flip(array_filter($groups, fn($name) => str_starts_with((string)$name, GROUP_PREFIX), ARRAY_FILTER_USE_KEY));
        $obsolete = [];
        foreach ($existing as $gid => $o) if (isset($gpIds[$gid]) && !isset($wanted[$gid])) $obsolete[] = (int)$o['id'];
        if ($obsolete) {
            try {
                $api->deleteOverrides($quiz['id'], $obsolete);
                $line['deleted'] = count($obsolete);
                $report['deleted'] += count($obsolete);
            } catch (RuntimeException $ex) {
                $line['errors'][] = 'Suppression des anciennes dérogations : ' . $ex->getMessage();
                $report['errors']++;
            }
        }
        $report['quizzes'][] = $line;
    }
    return $report;
}
