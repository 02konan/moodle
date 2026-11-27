<?php
declare(strict_types=1);
// Usage : php bin/create-admin.php admin@exemple.com "MotDePasseSolide"
require __DIR__ . '/../src/bootstrap.php';

[$script, $email, $password] = $argv + [null, null, null];
if (!$email || !$password || strlen($password) < 10) {
    fwrite(STDERR, "Usage : php bin/create-admin.php email motdepasse(10+ caractères)\n");
    exit(1);
}
$st = db()->prepare('INSERT INTO admins (email, password_hash, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)');
$st->execute([mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT), now_sql()]);
echo "Administrateur enregistré : $email\n";
