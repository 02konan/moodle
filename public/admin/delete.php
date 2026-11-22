<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf'] ?? null)) {
    $st = db()->prepare('DELETE FROM students WHERE id = ?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    flash($st->rowCount() ? 'Étudiant supprimé.' : 'Étudiant introuvable.', $st->rowCount() ? 'success' : 'error');
} else {
    flash('Requête invalide.', 'error');
}
$back = (string)($_POST['back'] ?? '');
redirect('./' . ($back !== '' ? '?' . $back : ''));
