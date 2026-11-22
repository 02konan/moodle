<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

function env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = ROOT . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    if (isset($vars[$key]) && $vars[$key] !== '') return $vars[$key];
    $fromEnv = getenv($key);
    return ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : $default;
}

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_NAME', 'moodle_signup'));
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * URL d'un fichier de public/assets avec sa date de modification (?v=…) :
 * après un déploiement, le navigateur recharge le fichier au lieu d'utiliser son cache.
 */
function asset(string $path, string $prefix = '../assets/'): string
{
    $file = ROOT . '/public/assets/' . $path;
    return $prefix . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function flash_pull(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/** Requête envoyée par fetch() depuis le backoffice (assets/admin-ajax.js). */
function is_async(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

/** Redirection compatible fetch : en asynchrone, renvoie l'URL et les messages en JSON. */
function redirect(string $url): never
{
    if (is_async()) json_out(['redirect' => $url, 'flash' => flash_pull()]);
    header('Location: ' . $url);
    exit;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        if (is_async()) json_out(['redirect' => '../admin/login.php', 'flash' => [['message' => 'Session expirée, reconnectez-vous.', 'type' => 'error']]], 401);
        header('Location: ../admin/login.php');
        exit;
    }
}

/** Minuscules sans accents. */
function ascii(string $s): string
{
    $map = [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','œ'=>'oe',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','æ'=>'ae',
    ];
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

function slug_upper(string $s): string
{
    $s = strtoupper(ascii($s));
    return trim(preg_replace('/[^A-Z0-9]+/', '-', $s) ?? '', '-');
}

/** Cohorte = [classe]-[section] */
function cohort_name(string $class, string $section): string
{
    return slug_upper($class) . '-' . slug_upper($section);
}

const CITY = 'Abidjan'; // même ville pour tous les étudiants

/** Nom d'utilisateur Moodle : minuscules, sans accent, sans espace ni caractère spécial. */
function clean_username(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', ascii($s)) ?? '';
}

/**
 * Mot de passe automatique : Nom (1re lettre en majuscule) + chiffres aléatoires + # ou @.
 * 8 caractères minimum (conforme à la politique Moodle par défaut : maj., min., chiffre, symbole).
 */
function make_password(string $lastname): string
{
    $base = preg_replace('/[^a-z]/', '', ascii($lastname)) ?? '';
    if (strlen($base) < 2) $base .= 'x';
    $base   = ucfirst($base);
    $symbol = random_int(0, 1) ? '#' : '@';
    $digits = (string)random_int(100, 999);
    while (strlen($base . $digits . $symbol) < 8) $digits .= (string)random_int(0, 9);
    return $base . $digits . $symbol;
}
