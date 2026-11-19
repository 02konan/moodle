<?php
declare(strict_types=1);

/**
 * Inscription aux cours et groupes Moodle via le CSV d'import (colonnes courseN / groupN).
 *
 * - Les cours sont identifiés par leur NOM ABRÉGÉ Moodle (ex. « BTS 2 FCGE: MATHÉMATIQUES GÉNÉRALES »),
 *   saisis dans le catalogue (table moodle_courses).
 * - Un étudiant de la classe « BTS 2 FC 03 » est inscrit dans tous les cours dont le nom abrégé
 *   commence par « BTS 2 FC » (mot entier) — ou par l'alias défini pour la filière (FC → FCGE).
 * - Dans chacun de ces cours, il est placé dans le groupe « GP/<cohorte> »
 *   (Moodle crée le groupe s'il n'existe pas encore dans le cours).
 */

const GROUP_PREFIX = 'GP/';

function moodle_group_name(string $cohort): string
{
    return GROUP_PREFIX . $cohort;
}

/** Forme de comparaison : majuscules sans accents, tout séparateur réduit à un espace. */
function course_key(string $s): string
{
    $s = strtoupper(ascii($s));
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '');
}

/** « BTS 2 FC 03 » → « BTS 2 FC » (le numéro de classe final est retiré). */
function class_course_prefix(string $classLabel, array $aliases = []): string
{
    $tokens = explode(' ', course_key($classLabel));
    if (count($tokens) >= 2 && ctype_digit(end($tokens))) array_pop($tokens);
    $last = array_key_last($tokens);
    if ($last !== null && isset($aliases[$tokens[$last]])) {
        $tokens[$last] = $aliases[$tokens[$last]];
    }
    return implode(' ', $tokens);
}

/** Noms abrégés du catalogue correspondant à la classe. */
function courses_for_class(string $classLabel, array $catalogue, array $aliases): array
{
    $prefix = class_course_prefix($classLabel, $aliases);
    if ($prefix === '') return [];
    $out = [];
    foreach ($catalogue as $shortname) {
        $k = course_key($shortname);
        if ($k === $prefix || str_starts_with($k, $prefix . ' ')) $out[] = $shortname;
    }
    return $out;
}

function course_catalogue(PDO $pdo): array
{
    return $pdo->query('SELECT shortname FROM moodle_courses ORDER BY shortname')->fetchAll(PDO::FETCH_COLUMN);
}

/** ['FC' => 'FCGE', ...] (clés et valeurs normalisées). */
function filiere_aliases(PDO $pdo): array
{
    $rows = $pdo->query('SELECT filiere, course_filiere FROM filiere_aliases')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($rows as $from => $to) $out[course_key((string)$from)] = course_key((string)$to);
    return $out;
}

/** Découpe une liste collée (une par ligne ou séparée par des virgules), sans doublons. */
function parse_course_list(string $text): array
{
    $out = [];
    foreach (preg_split('/[\r\n,]+/', $text) ?: [] as $item) {
        $item = preg_replace('/\s+/u', ' ', trim($item)) ?? '';
        if ($item !== '') $out[mb_strtoupper($item, 'UTF-8')] = $item;
    }
    return array_values($out);
}

/** « FC = FCGE » par ligne → ['FC' => 'FCGE']. */
function parse_aliases(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (!str_contains($line, '=')) continue;
        [$from, $to] = array_map('course_key', explode('=', $line, 2));
        if ($from !== '' && $to !== '') $out[$from] = $to;
    }
    return $out;
}
