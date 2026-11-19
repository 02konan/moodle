<?php
declare(strict_types=1);

/**
 * Client de l'API référentiel (api-moodle.php).
 * Types : sections | cycles | niveaux | classes — réponse {"data":[{id,label,code?}]}
 * Cache fichier court + repli sur le dernier cache si l'API est indisponible.
 */
final class Referentiel
{
    /** @throws RuntimeException si l'API est injoignable et sans cache */
    public static function get(string $type, array $params = []): array
    {
        $base = env('REFERENTIEL_API_URL');
        if (!$base) throw new RuntimeException('REFERENTIEL_API_URL non configurée');

        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(['type' => $type] + $params);
        $cache = ROOT . '/storage/ref_' . md5($url) . '.json';
        $ttl = (int)env('REFERENTIEL_CACHE_TTL', '300');

        if (is_file($cache) && time() - filemtime($cache) < $ttl) {
            $hit = json_decode((string)file_get_contents($cache), true);
            if (is_array($hit)) return $hit;
        }

        $items = self::fetch($url);
        if ($items !== null) {
            @file_put_contents($cache, json_encode($items, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $items;
        }
        if (is_file($cache)) {
            $stale = json_decode((string)file_get_contents($cache), true);
            if (is_array($stale)) return $stale;
        }
        throw new RuntimeException("API référentiel indisponible ($type)");
    }

    public static function find(array $items, int $id): ?array
    {
        foreach ($items as $it) {
            if ((int)$it['id'] === $id) return $it;
        }
        return null;
    }

    private static function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) return null;

        $json = json_decode($body, true);
        if (!isset($json['data']) || !is_array($json['data'])) return null;

        $out = [];
        foreach ($json['data'] as $row) {
            if (!is_array($row) || !isset($row['id'], $row['label'])) continue;
            $item = ['id' => (int)$row['id'], 'label' => (string)$row['label']];
            if (isset($row['code'])) $item['code'] = (string)$row['code'];
            $out[] = $item;
        }
        return $out;
    }
}
