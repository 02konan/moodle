<?php
declare(strict_types=1);

/**
 * Client minimal du service web REST de Moodle.
 * Configuration (.env / Dokploy) : MOODLE_URL (ex. https://moodle.exemple.ci) et MOODLE_TOKEN.
 * Fonctions à autoriser dans le service externe : core_user_get_users_by_field, core_user_delete_users.
 */
final class MoodleApi
{
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
}
