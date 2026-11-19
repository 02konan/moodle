<?php
declare(strict_types=1);

/**
 * Cours et groupe Moodle déduits automatiquement de la cohorte (colonnes course1 / group1 du CSV).
 *
 *   cohorte « BTS-2-FC-03-ANY » (classe BTS 2 FC 03, section ANY)
 *     → course1 = « BTS 2 FC »               (nom abrégé du cours de la filière)
 *     → group1  = « GP/BTS-2-FC-03-ANY »     (créé par Moodle dans le cours s'il n'existe pas)
 */

const GROUP_PREFIX = 'GP/';

function moodle_group_name(string $cohort): string
{
    return GROUP_PREFIX . $cohort;
}

/** Retire la section et le numéro de classe de la cohorte : « BTS-2-FC-03-ANY » → « BTS 2 FC ». */
function moodle_course_name(string $cohort, string $sectionCode = ''): string
{
    $suffix = '-' . slug_upper($sectionCode);
    if ($sectionCode !== '' && str_ends_with($cohort, $suffix)) {
        $cohort = substr($cohort, 0, -strlen($suffix));
        $tokens = explode('-', $cohort);
    } else {
        $tokens = explode('-', $cohort);
        if (count($tokens) >= 2) array_pop($tokens); // section = dernier segment
    }
    if (count($tokens) >= 2 && ctype_digit(end($tokens))) array_pop($tokens);
    return implode(' ', array_filter($tokens, fn($t) => $t !== ''));
}
