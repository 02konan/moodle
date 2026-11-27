<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

// Fuseau de l'application : Côte d'Ivoire (UTC+0, sans heure d'été), quel que soit celui du serveur.
const APP_TIMEZONE = 'Africa/Abidjan';
date_default_timezone_set(APP_TIMEZONE);

/** Date et heure courantes (Abidjan) au format SQL : les dates sont écrites par l'application, pas par la base. */
function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

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
        // La session SQL suit le fuseau de l'application (NOW(), conversions éventuelles) même si le serveur est en Europe
        $pdo->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
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

/** Icône SVG du backoffice (trait, hérite de la couleur du texte) : trash, pencil, check, up, down, x, layers. */
function ui_icon(string $name): string
{
    static $paths = [
        'trash'  => '<path d="M3 6h18"/><path d="M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6"/><path d="M5.5 6l.9 13.6A2 2 0 0 0 8.4 21.5h7.2a2 2 0 0 0 2-1.9L18.5 6"/><path d="M10 11v6M14 11v6"/>',
        'pencil' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'up'     => '<path d="m6 15 6-6 6 6"/>',
        'down'   => '<path d="m6 9 6 6 6-6"/>',
        'x'      => '<path d="M18 6 6 18M6 6l12 12"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'copy'   => '<rect x="8" y="8" width="13" height="13" rx="2.5"/><path d="M16 8V5.5A2.5 2.5 0 0 0 13.5 3h-8A2.5 2.5 0 0 0 3 5.5v8A2.5 2.5 0 0 0 5.5 16H8"/>',
        'eye'    => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'left'   => '<path d="m15 6-6 6 6 6"/>',
        'right'  => '<path d="m9 6 6 6-6 6"/>',
        'layers' => '<path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="m2 13 10 5 10-5"/><path d="m2 17.5 10 5 10-5"/>',
    ];
    return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

const PER_PAGE_OPTIONS = [10, 20, 50, 100];

/** Nombre de lignes par page demandé (?per_page=), parmi PER_PAGE_OPTIONS ; 20 par défaut. */
function per_page(array $get, int $default = 20): int
{
    $n = (int)($get['per_page'] ?? 0);
    return in_array($n, PER_PAGE_OPTIONS, true) ? $n : $default;
}

/** Pages à afficher : 1 … 4 5 [6] 7 8 … 23 (null = trou « … »). */
function pager_pages(int $page, int $pages, int $around = 1): array
{
    $keep = [1, $pages];
    for ($i = $page - $around; $i <= $page + $around; $i++) $keep[] = $i;
    // Au début et à la fin, on affiche quelques pages de plus plutôt qu'un « … » qui masquerait une seule page
    if ($page <= 3) array_push($keep, 2, 3, 4);
    if ($page >= $pages - 2) array_push($keep, $pages - 1, $pages - 2, $pages - 3);
    $keep = array_values(array_unique(array_filter($keep, fn($p) => $p >= 1 && $p <= $pages)));
    sort($keep);
    $out = [];
    foreach ($keep as $i => $p) {
        if ($i > 0 && $p - $keep[$i - 1] === 2) $out[] = $p - 1;        // trou d'une seule page : on l'affiche
        elseif ($i > 0 && $p - $keep[$i - 1] > 2) $out[] = null;
        $out[] = $p;
    }
    return $out;
}

/**
 * Pagination : « Afficher [20] par page » à gauche ; [<] [1] … [5] [6] [7] … [23] [>] à droite.
 * $url(array $params) construit le lien en conservant les filtres.
 */
function pager_html(int $page, int $pages, int $perPage, int $total, callable $url, array $get): string
{
    if ($total === 0) return '';
    $from = ($page - 1) * $perPage + 1;
    $to = min($total, $page * $perPage);
    $hidden = '';
    foreach ($get as $k => $v) {
        if (in_array($k, ['page', 'per_page'], true) || !is_string($v) || $v === '') continue;
        $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    $opts = '';
    foreach (PER_PAGE_OPTIONS as $n) $opts .= '<option value="' . $n . '"' . ($n === $perPage ? ' selected' : '') . '>' . $n . '</option>';
    $h = '<nav class="pager" aria-label="Pagination">'
       . '<form class="pager-size" method="get" data-live-form>' . $hidden
       . '<label><span>Afficher</span><select name="per_page" data-submit-on-change aria-label="Lignes par page">' . $opts . '</select><span>par page</span></label>'
       . '<span class="pager-range">' . $from . '–' . $to . ' sur ' . $total . '</span></form>';
    if ($pages > 1) {
        $link = fn(int $p, string $label, string $cls, string $aria = '') => '<a class="pager-btn ' . $cls . '" href="?' . e($url(['page' => $p])) . '"'
            . ($aria !== '' ? ' aria-label="' . e($aria) . '" title="' . e($aria) . '"' : '') . ($cls === 'is-current' ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        $off = fn(string $label, string $aria) => '<span class="pager-btn is-disabled" aria-disabled="true" aria-label="' . e($aria) . '">' . $label . '</span>';
        $h .= '<div class="pager-pages">';
        $h .= $page > 1 ? $link($page - 1, ui_icon('left'), 'pager-arrow', 'Page précédente') : $off(ui_icon('left'), 'Page précédente');
        $list = pager_pages($page, $pages);
        foreach ($list as $i => $p) {
            if ($p === null) {
                // « … » : saute au milieu des pages masquées
                $jump = intdiv($list[$i - 1] + $list[$i + 1], 2);
                $h .= $link($jump, '…', 'pager-gap', 'Page ' . $jump);
            } else {
                $h .= $link($p, (string)$p, $p === $page ? 'is-current' : '', 'Page ' . $p);
            }
        }
        $h .= $page < $pages ? $link($page + 1, ui_icon('right'), 'pager-arrow', 'Page suivante') : $off(ui_icon('right'), 'Page suivante');
        $h .= '</div>';
    }
    return $h . '</nav>';
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
