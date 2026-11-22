<?php
declare(strict_types=1);

/** Construit la clause WHERE commune à la liste et à l'export. */
function student_filters(array $src): array
{
    $where = ['1=1'];
    $params = [];

    $cohort = trim((string)($src['cohort'] ?? ''));
    if ($cohort !== '') { $where[] = 's.cohort LIKE ?'; $params[] = '%' . like_escape($cohort) . '%'; }

    $status = student_status((string)($src['status'] ?? ''));
    if ($status === 'new')      $where[] = 's.exported_at IS NULL';
    if ($status === 'exported') $where[] = 's.exported_at IS NOT NULL';

    // Recherche : chaque mot doit apparaître dans au moins un champ, dans n'importe quel ordre
    // (« Ama Konan », « KONAN Ama », « konan bts-2 »…). Majuscules et accents ignorés (collation unicode_ci).
    $q = trim(preg_replace('/\s+/u', ' ', (string)($src['q'] ?? '')) ?? '');
    if ($q !== '') {
        foreach (array_slice(array_unique(explode(' ', $q)), 0, 8) as $word) {
            $where[] = '(s.lastname LIKE ? OR s.firstname LIKE ? OR s.username LIKE ? OR s.email LIKE ? OR s.cohort LIKE ? OR s.class_label LIKE ?)';
            array_push($params, ...array_fill(0, 6, '%' . like_escape($word) . '%'));
        }
    }
    return [implode(' AND ', $where), $params];
}

function like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/** Statut saisi dans la liste de suggestions (« Non exportés », « Déjà exportés ») ou code (new / exported). */
function student_status(string $v): string
{
    $v = ascii(trim($v));
    if ($v === '' || str_starts_with($v, 'tous')) return '';
    if ($v === 'new' || str_contains($v, 'non') || str_contains($v, 'nouveau')) return 'new';
    if ($v === 'exported' || str_contains($v, 'export')) return 'exported';
    return '';
}
