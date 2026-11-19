<?php
declare(strict_types=1);

/** Construit la clause WHERE commune à la liste et à l'export. */
function student_filters(array $src): array
{
    $where = ['1=1'];
    $params = [];

    $cohort = trim((string)($src['cohort'] ?? ''));
    if ($cohort !== '') { $where[] = 's.cohort = ?'; $params[] = $cohort; }

    $status = $src['status'] ?? '';
    if ($status === 'new')      $where[] = 's.exported_at IS NULL';
    if ($status === 'exported') $where[] = 's.exported_at IS NOT NULL';

    $q = trim((string)($src['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(s.firstname LIKE ? OR s.lastname LIKE ? OR s.email LIKE ? OR s.username LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($params, $like, $like, $like, $like);
    }
    return [implode(' AND ', $where), $params];
}
