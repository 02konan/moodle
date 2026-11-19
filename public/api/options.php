<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Referentiel.php';

header('Cache-Control: no-store');

$id = fn(string $k): int => max(0, (int)($_GET[$k] ?? 0));
$need = function (array $v): void {
    foreach ($v as $n => $x) if ($x < 1) json_out(['error' => "Paramètre manquant : $n"], 400);
};

try {
    switch ($_GET['type'] ?? '') {
        case 'sections':
            $items = Referentiel::get('sections');
            break;
        case 'cycles':
            $p = ['section_id' => $id('section_id')]; $need($p);
            $items = Referentiel::get('cycles', $p);
            break;
        case 'levels':
            $p = ['section_id' => $id('section_id'), 'cycle_id' => $id('cycle_id')]; $need($p);
            $items = Referentiel::get('niveaux', $p);
            break;
        case 'classes':
            $p = ['section_id' => $id('section_id'), 'cycle_id' => $id('cycle_id'), 'niveau_id' => $id('level_id')]; $need($p);
            $items = Referentiel::get('classes', $p);
            break;
        default:
            json_out(['error' => 'Type inconnu'], 400);
    }
    json_out(['items' => $items]);
} catch (Throwable $e) {
    error_log('[options] ' . $e->getMessage());
    json_out(['error' => "Impossible de charger les données pour l'instant."], 502);
}
