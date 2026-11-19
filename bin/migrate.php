<?php
declare(strict_types=1);
// Usage : php bin/migrate.php — attend la base puis applique sql/*.sql (scripts idempotents, ordre alphabétique).
require __DIR__ . '/../src/bootstrap.php';

$deadline = time() + (int)env('DB_WAIT_SECONDS', '60');
while (true) {
    try { db(); break; }
    catch (PDOException $e) {
        if (time() >= $deadline) { fwrite(STDERR, "Base injoignable : {$e->getMessage()}\n"); exit(1); }
        echo "En attente de la base de données…\n";
        sleep(2);
    }
}

foreach (glob(ROOT . '/sql/*.sql') ?: [] as $file) {
    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    foreach (preg_split('/;\s*$/m', $sql) ?: [] as $stmt) {
        if (trim($stmt) !== '') db()->exec($stmt);
    }
    echo 'Migration appliquée : ' . basename($file) . "\n";
}
