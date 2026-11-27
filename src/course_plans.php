<?php
declare(strict_types=1);

/**
 * Structure des cours (page « Cours ») : cours → sections → sous-sections / QCM.
 * Règles : une section est à la racine ; une sous-section est dans une section ;
 * un QCM est dans une section ou une sous-section. Tables : sql/003-course-plans.sql.
 */

const PLAN_ITEM_TYPES = ['section' => 'Section', 'subsection' => 'Sous-section', 'quiz' => 'QCM'];

function plan_title(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    if ($s === '') throw new RuntimeException('Le titre est obligatoire.');
    if (mb_strlen($s) > 255) throw new RuntimeException('Titre trop long (255 caractères maximum).');
    return $s;
}

function plan_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM course_plans WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function plan_item(PDO $pdo, int $planId, int $itemId): ?array
{
    $st = $pdo->prepare('SELECT * FROM course_plan_items WHERE id = ? AND plan_id = ?');
    $st->execute([$itemId, $planId]);
    return $st->fetch() ?: null;
}

/** Arbre du cours : sections (avec 'children'), dans l'ordre. */
function plan_tree(PDO $pdo, int $planId): array
{
    $st = $pdo->prepare('SELECT * FROM course_plan_items WHERE plan_id = ? ORDER BY position, id');
    $st->execute([$planId]);
    $byParent = [];
    foreach ($st->fetchAll() as $it) $byParent[(int)($it['parent_id'] ?? 0)][] = $it;
    $build = function (int $parent) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $it) { $it['children'] = $build((int)$it['id']); $out[] = $it; }
        return $out;
    };
    return $build(0);
}

/** Ajoute un élément en dernière position de son parent, en respectant la hiérarchie. */
function plan_add_item(PDO $pdo, int $planId, ?int $parentId, string $type, string $title): int
{
    if (!isset(PLAN_ITEM_TYPES[$type])) throw new RuntimeException('Type d\'élément inconnu.');
    $title = plan_title($title);
    $parent = $parentId ? plan_item($pdo, $planId, $parentId) : null;
    if ($parentId && !$parent) throw new RuntimeException('Élément parent introuvable.');

    $ok = match ($type) {
        'section'    => $parent === null,
        'subsection' => $parent !== null && $parent['type'] === 'section',
        'quiz'       => $parent !== null && in_array($parent['type'], ['section', 'subsection'], true),
    };
    if (!$ok) throw new RuntimeException(match ($type) {
        'section'    => 'Une section se crée à la racine du cours.',
        'subsection' => 'Une sous-section se crée dans une section.',
        'quiz'       => 'Un QCM se crée dans une section ou une sous-section.',
    });

    $st = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM course_plan_items WHERE plan_id = ? AND parent_id <=> ?');
    $st->execute([$planId, $parentId]);
    $pdo->prepare('INSERT INTO course_plan_items (plan_id, parent_id, type, title, position) VALUES (?, ?, ?, ?, ?)')
        ->execute([$planId, $parentId, $type, $title, (int)$st->fetchColumn()]);
    plan_touch($pdo, $planId);
    return (int)$pdo->lastInsertId();
}

function plan_rename_item(PDO $pdo, int $planId, int $itemId, string $title): void
{
    if (!plan_item($pdo, $planId, $itemId)) throw new RuntimeException('Élément introuvable.');
    $pdo->prepare('UPDATE course_plan_items SET title = ? WHERE id = ?')->execute([plan_title($title), $itemId]);
    plan_touch($pdo, $planId);
}

/** Supprime un élément et tout ce qu'il contient (clé étrangère en cascade). */
function plan_delete_item(PDO $pdo, int $planId, int $itemId): void
{
    if (!plan_item($pdo, $planId, $itemId)) throw new RuntimeException('Élément introuvable.');
    $pdo->prepare('DELETE FROM course_plan_items WHERE id = ?')->execute([$itemId]);
    plan_touch($pdo, $planId);
}

/** Échange l'élément avec son voisin (même parent) : $dir = -1 (monter) ou 1 (descendre). */
function plan_move_item(PDO $pdo, int $planId, int $itemId, int $dir): void
{
    $it = plan_item($pdo, $planId, $itemId);
    if (!$it) throw new RuntimeException('Élément introuvable.');
    $st = $pdo->prepare('SELECT id, position FROM course_plan_items WHERE plan_id = ? AND parent_id <=> ? ORDER BY position, id');
    $st->execute([$planId, $it['parent_id']]);
    $siblings = $st->fetchAll();
    $i = array_search($itemId, array_map('intval', array_column($siblings, 'id')), true);
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($i === false || !isset($siblings[$j])) return;   // déjà en premier / dernier
    // Renumérote pour garantir des positions distinctes, puis échange
    $order = array_map('intval', array_column($siblings, 'id'));
    [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
    $up = $pdo->prepare('UPDATE course_plan_items SET position = ? WHERE id = ?');
    foreach ($order as $pos => $id) $up->execute([$pos + 1, $id]);
    plan_touch($pdo, $planId);
}

function plan_touch(PDO $pdo, int $planId): void
{
    $pdo->prepare('UPDATE course_plans SET updated_at = ? WHERE id = ?')->execute([now_sql(), $planId]);
}

/** Nombre de sections et de QCM par cours : [plan_id => ['sections' => n, 'quizzes' => n]]. */
function plan_counts(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT plan_id, SUM(type = 'section') s, SUM(type = 'subsection') ss, SUM(type = 'quiz') q FROM course_plan_items GROUP BY plan_id") as $r) {
        $out[(int)$r['plan_id']] = ['sections' => (int)$r['s'], 'subsections' => (int)$r['ss'], 'quizzes' => (int)$r['q']];
    }
    return $out;
}

/**
 * Duplique un cours : nouvelle structure identique (sections, sous-sections, QCM, ordre),
 * titre « … (copie) » (ou « (copie 2) »…). L'agenda n'est pas copié (il dépend des cohortes).
 * Retourne l'identifiant du nouveau cours.
 */
function plan_duplicate(PDO $pdo, int $planId): int
{
    $plan = plan_get($pdo, $planId);
    if (!$plan) throw new RuntimeException('Cours introuvable.');

    // « X (copie) » ou « X (copie 3) » redonne « X (copie N) » plutôt que « X (copie) (copie) »
    $original = preg_replace('/ \(copie(?: \d+)?\)$/u', '', $plan['title']) ?? $plan['title'];
    $base = mb_substr($original, 0, 240) . ' (copie';
    $st = $pdo->prepare('SELECT title FROM course_plans WHERE title LIKE ?');
    $st->execute([str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base) . '%']);
    $taken = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    $title = $base . ')';
    for ($n = 2; isset($taken[$title]); $n++) $title = $base . " $n)";

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO course_plans (title, created_at, updated_at) VALUES (?, ?, ?)')->execute([$title, now_sql(), now_sql()]);
        $newId = (int)$pdo->lastInsertId();
        // Parents avant enfants : sections, puis sous-sections, puis QCM
        $st = $pdo->prepare("SELECT * FROM course_plan_items WHERE plan_id = ?
                             ORDER BY FIELD(type, 'section', 'subsection', 'quiz'), position, id");
        $st->execute([$planId]);
        $ins = $pdo->prepare('INSERT INTO course_plan_items (plan_id, parent_id, type, title, position) VALUES (?, ?, ?, ?, ?)');
        $map = [];
        foreach ($st->fetchAll() as $it) {
            $parent = $it['parent_id'] !== null ? ($map[(int)$it['parent_id']] ?? null) : null;
            $ins->execute([$newId, $parent, $it['type'], $it['title'], (int)$it['position']]);
            $map[(int)$it['id']] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        return $newId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}
