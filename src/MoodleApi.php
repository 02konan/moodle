<?php
declare(strict_types=1);

/**
 * Client minimal du service web REST de Moodle.
 * Configuration (.env / Dokploy) : MOODLE_URL (ex. https://moodle.exemple.ci) et MOODLE_TOKEN.
 * Fonctions à ajouter au service externe : voir MoodleApi::FUNCTIONS (et le README).
 */
final class MoodleApi
{
    /** Fonctions du service web utilisées par l'application. */
    public const FUNCTIONS = [
        'core_user_get_users_by_field', 'core_user_delete_users', 'core_user_create_users',
        'core_cohort_search_cohorts', 'core_cohort_create_cohorts', 'core_cohort_add_cohort_members',
        'core_course_get_courses_by_field', 'enrol_manual_enrol_users',
        'core_group_get_course_groups', 'core_group_create_groups', 'core_group_add_group_members',
        // Facultatives (recommandées) : évitent de réinscrire / réajouter les étudiants déjà présents
        'core_enrol_get_enrolled_users', 'core_group_get_group_members',
        // Agenda des QCM → dérogations de groupe des tests (Moodle 4.4+)
        'mod_quiz_get_quizzes_by_courses', 'mod_quiz_get_overrides', 'mod_quiz_save_overrides', 'mod_quiz_delete_overrides',
    ];

    /**
     * Erreur d'envoi de message de Moodle (message de bienvenue au cours, e-mail…) : l'action principale
     * a bien été faite (le message part après l'enregistrement), seul l'envoi a échoué.
     */
    public static function isMessageError(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'Message was not sent') !== false;
    }

    public function __construct(private string $url, private string $token) {}

    public static function fromEnv(): ?self
    {
        $url = rtrim((string)env('MOODLE_URL', ''), '/');
        $token = (string)env('MOODLE_TOKEN', '');
        return ($url !== '' && $token !== '') ? new self($url, $token) : null;
    }

    /** @throws RuntimeException */
    public function call(string $function, array $params = []): mixed
    {
        $ch = curl_init($this->url . '/webservice/rest/server.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['wstoken' => $this->token, 'wsfunction' => $function, 'moodlewsrestformat' => 'json'] + $params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) throw new RuntimeException("Moodle injoignable : $err");
        if ($code < 200 || $code >= 300) throw new RuntimeException("Moodle a répondu avec le code HTTP $code.");
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['exception'])) {
            throw new RuntimeException('Moodle : ' . ($json['message'] ?? $json['errorcode'] ?? 'erreur inconnue'));
        }
        if ($json === null && trim($body) !== 'null') throw new RuntimeException('Réponse de Moodle illisible.');
        return $json;
    }

    /**
     * Comptes Moodle par nom d'utilisateur : ['amakonan' => ['id' => 12, 'firstaccess' => …, 'lastaccess' => …], …].
     * Les noms absents du résultat n'existent pas dans Moodle.
     */
    public function usersByUsername(array $usernames): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($usernames)), 100) as $chunk) {
            $users = $this->call('core_user_get_users_by_field', ['field' => 'username', 'values' => $chunk]);
            foreach ((array)$users as $u) {
                if (!isset($u['username'], $u['id'])) continue;
                if (!array_key_exists('lastaccess', $u) && !array_key_exists('firstaccess', $u)) {
                    throw new RuntimeException("Le jeton Moodle n'a pas accès aux dates de connexion (utilisez un compte administrateur).");
                }
                $out[mb_strtolower((string)$u['username'])] = [
                    'id'          => (int)$u['id'],
                    'firstaccess' => (int)($u['firstaccess'] ?? 0),
                    'lastaccess'  => (int)($u['lastaccess'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /** Supprime des comptes Moodle (définitif). */
    public function deleteUsers(array $ids): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 50) as $chunk) {
            $this->call('core_user_delete_users', ['userids' => $chunk]);
        }
    }

    /* ---------- Comptes ---------- */

    /**
     * Crée des comptes (authentification manuelle). $users : [['username','password','firstname','lastname','email','city'], …].
     * Retourne ['username' => id]. Moodle refuse tout le lot si un compte est invalide.
     */
    public function createUsers(array $users): array
    {
        $out = [];
        foreach ((array)$this->call('core_user_create_users', ['users' => array_values($users)]) as $u) {
            if (isset($u['username'], $u['id'])) $out[mb_strtolower((string)$u['username'])] = (int)$u['id'];
        }
        return $out;
    }

    /* ---------- Cohortes (identifiées par leur idnumber, comme l'import CSV) ---------- */

    public function cohortByIdnumber(string $idnumber): ?array
    {
        $res = $this->call('core_cohort_search_cohorts', [
            'query'   => $idnumber,
            'context' => ['contextlevel' => 'system', 'instanceid' => 0],
            'includes' => 'all',
            'limitnum' => 100,
        ]);
        foreach ((array)($res['cohorts'] ?? []) as $c) {
            if ((string)($c['idnumber'] ?? '') === $idnumber) return ['id' => (int)$c['id'], 'name' => (string)$c['name']];
        }
        return null;
    }

    public function createCohort(string $name, string $idnumber): int
    {
        $res = $this->call('core_cohort_create_cohorts', ['cohorts' => [[
            'categorytype' => ['type' => 'system', 'value' => ''],
            'name' => $name, 'idnumber' => $idnumber, 'visible' => 1,
        ]]]);
        return (int)($res[0]['id'] ?? 0);
    }

    /** Ajoute des comptes (par username) à une cohorte (par idnumber). Les membres déjà présents sont ignorés. */
    public function addCohortMembers(string $idnumber, array $usernames): void
    {
        foreach (array_chunk(array_values($usernames), 100) as $chunk) {
            $members = array_map(fn($u) => [
                'cohorttype' => ['type' => 'idnumber', 'value' => $idnumber],
                'usertype'   => ['type' => 'username', 'value' => $u],
            ], $chunk);
            $this->call('core_cohort_add_cohort_members', ['members' => $members]);
        }
    }

    /* ---------- Cours, inscriptions et groupes ---------- */

    public function courseByShortname(string $shortname): ?array
    {
        $res = $this->call('core_course_get_courses_by_field', ['field' => 'shortname', 'value' => $shortname]);
        $c = $res['courses'][0] ?? null;
        return $c ? ['id' => (int)$c['id'], 'shortname' => (string)$c['shortname']] : null;
    }

    /**
     * Inscription manuelle (l'inscription manuelle doit être activée dans le cours).
     * « Message was not sent » (message de bienvenue non envoyé) n'arrête pas les lots suivants :
     * les étudiants du lot sont inscrits. Retourne true si au moins un message n'a pas pu partir.
     */
    public function enrolUsers(int $courseId, array $userIds, int $roleId): bool
    {
        $messageFailed = false;
        foreach (array_chunk(array_values($userIds), 100) as $chunk) {
            try {
                $this->call('enrol_manual_enrol_users', ['enrolments' => array_map(
                    fn($id) => ['roleid' => $roleId, 'userid' => (int)$id, 'courseid' => $courseId], $chunk
                )]);
            } catch (RuntimeException $ex) {
                if (!self::isMessageError($ex)) throw $ex;
                $messageFailed = true;
            }
        }
        return $messageFailed;
    }

    /** Identifiants des inscrits du cours, ou null si la fonction n'est pas autorisée dans le service. */
    public function enrolledUserIds(int $courseId): ?array
    {
        try {
            $res = $this->call('core_enrol_get_enrolled_users', ['courseid' => $courseId, 'options' => [['name' => 'userfields', 'value' => 'id']]]);
        } catch (RuntimeException) {
            return null;
        }
        return array_map(fn($u) => (int)$u['id'], array_filter((array)$res, fn($u) => isset($u['id'])));
    }

    /** Identifiants des membres d'un groupe, ou null si la fonction n'est pas autorisée dans le service. */
    public function groupMemberIds(int $groupId): ?array
    {
        try {
            $res = $this->call('core_group_get_group_members', ['groupids' => [$groupId]]);
        } catch (RuntimeException) {
            return null;
        }
        return array_map('intval', (array)($res[0]['userids'] ?? []));
    }

    /** Groupes du cours : ['GP/BTS-2-FC-03-ANGRE' => id, …]. */
    public function courseGroups(int $courseId): array
    {
        $out = [];
        foreach ((array)$this->call('core_group_get_course_groups', ['courseid' => $courseId]) as $g) {
            if (isset($g['name'], $g['id'])) $out[(string)$g['name']] = (int)$g['id'];
        }
        return $out;
    }

    public function createGroup(int $courseId, string $name): int
    {
        $res = $this->call('core_group_create_groups', ['groups' => [['courseid' => $courseId, 'name' => $name, 'description' => '']]]);
        return (int)($res[0]['id'] ?? 0);
    }

    public function addGroupMembers(int $groupId, array $userIds): void
    {
        foreach (array_chunk(array_values($userIds), 100) as $chunk) {
            $this->call('core_group_add_group_members', ['members' => array_map(fn($id) => ['groupid' => $groupId, 'userid' => (int)$id], $chunk)]);
        }
    }

    /* ---------- Tests (quiz) et dérogations de groupe ---------- */

    /** Tests d'un cours : [['id' => instance, 'cmid' => …, 'name' => …], …]. */
    public function quizzesByCourse(int $courseId): array
    {
        $res = $this->call('mod_quiz_get_quizzes_by_courses', ['courseids' => [$courseId]]);
        return array_map(fn($q) => ['id' => (int)$q['id'], 'cmid' => (int)($q['coursemodule'] ?? 0), 'name' => (string)$q['name']],
            array_values(array_filter((array)($res['quizzes'] ?? []), fn($q) => (int)($q['course'] ?? $courseId) === $courseId)));
    }

    /** Dérogations de groupe d'un test : [groupid => dérogation]. */
    public function groupOverrides(int $quizId): array
    {
        $out = [];
        foreach ((array)($this->call('mod_quiz_get_overrides', ['quizid' => $quizId])['overrides'] ?? []) as $o) {
            if (!empty($o['groupid'])) $out[(int)$o['groupid']] = $o;
        }
        return $out;
    }

    /** Crée (sans id) ou met à jour (avec id) une dérogation ; retourne son id. */
    public function saveOverride(int $quizId, array $override): int
    {
        $res = $this->call('mod_quiz_save_overrides', ['data' => ['quizid' => $quizId, 'overrides' => [$override]]]);
        return (int)($res['ids'][0] ?? 0);
    }

    public function deleteOverrides(int $quizId, array $ids): void
    {
        if ($ids) $this->call('mod_quiz_delete_overrides', ['data' => ['quizid' => $quizId, 'ids' => array_values(array_map('intval', $ids))]]);
    }
}
