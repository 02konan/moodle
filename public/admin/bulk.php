<?php
declare(strict_types=1);
// Actions de masse sur les étudiants : action=edit | delete, ids[] = identifiants cochés.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Referentiel.php';
require __DIR__ . '/../../src/students.php';
require_admin();

const BULK_MAX = 500;

$back    = (string)($_POST['back'] ?? '');
$backUrl = './' . ($back !== '' ? '?' . $back : '');
$done = function (string $msg, string $type) use ($backUrl): never {
    flash($msg, $type);
    header('Location: ' . $backUrl);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $done('Requête invalide.', 'error');
if (!csrf_valid($_POST['csrf'] ?? null))   $done('Session expirée, rechargez la page.', 'error');

$ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['ids'] ?? [])),
    fn(int $i) => $i > 0
)));
if (!$ids) $done('Aucun étudiant sélectionné.', 'error');
if (count($ids) > BULK_MAX) $done('Sélection trop grande (' . BULK_MAX . ' étudiants maximum).', 'error');

$pdo    = db();
$action = (string)($_POST['action'] ?? '');
$in     = implode(',', array_fill(0, count($ids), '?'));
$n      = count($ids);
$plural = fn(int $k) => $k > 1 ? 's' : '';

/* ---------- Suppression de masse ---------- */
if ($action === 'delete') {
    $st = $pdo->prepare("DELETE FROM students WHERE id IN ($in)");
    $st->execute($ids);
    $k = $st->rowCount();
    $done("$k étudiant{$plural($k)} supprimé{$plural($k)}.", 'success');
}

/* ---------- Modification de masse ---------- */
if ($action !== 'edit') $done('Action inconnue.', 'error');

$data = [];

// Parcours : tout ou rien
$p = [];
foreach (['section_id', 'cycle_id', 'level_id', 'class_id'] as $k) $p[$k] = (int)($_POST[$k] ?? 0);
$filled = count(array_filter($p));
if ($filled > 0 && $filled < 4) {
    $done('Parcours incomplet : choisissez la section, le cycle, le niveau et la classe (ou laissez tout vide).', 'error');
}
$parcours = null;
if ($filled === 4) {
    try {
        $parcours = resolve_parcours($p['section_id'], $p['cycle_id'], $p['level_id'], $p['class_id']);
    } catch (RuntimeException $ex) {
        error_log('[bulk] ' . $ex->getMessage());
        $done('Le référentiel est indisponible : impossible de changer de classe pour l\'instant.', 'error');
    }
    if (!$parcours) $done('Parcours incohérent : choisissez à nouveau la section, le cycle, le niveau et la classe.', 'error');
    $data += $parcours;
}

// Statut d'export
switch ((string)($_POST['export_status'] ?? 'keep')) {
    case 'new':      $data['exported_at'] = null; break;
    case 'exported': $data['exported_at'] = date('Y-m-d H:i:s'); break;
}

$regen = !empty($_POST['regen_password']);

if (!$data && !$regen) $done('Aucune modification demandée.', 'error');

// Noms nécessaires à la génération des mots de passe
$lastnames = [];
if ($regen) {
    $st = $pdo->prepare("SELECT id, lastname FROM students WHERE id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) $lastnames[(int)$r['id']] = (string)$r['lastname'];
}

try {
    $pdo->beginTransaction();
    $updated = 0;
    foreach ($ids as $id) {
        $row = $data;
        if ($regen) {
            if (!isset($lastnames[$id])) continue; // étudiant supprimé entre-temps
            $row['password'] = make_password($lastnames[$id]);
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        $st  = $pdo->prepare("UPDATE students SET $set WHERE id = ?");
        $st->execute([...array_values($row), $id]);
        // rowCount() = 0 si les valeurs étaient déjà identiques : on compte quand même l'étudiant existant
        $updated++;
    }
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[bulk] ' . $ex->getMessage());
    $done('Erreur pendant la modification de masse : aucune modification n\'a été appliquée.', 'error');
}

$msg = "$updated étudiant{$plural($updated)} modifié{$plural($updated)}.";
if ($parcours) $msg .= " Nouvelle cohorte : {$parcours['cohort']}.";
$done($msg, 'success');
