<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

if (!empty($_SESSION['admin_id'])) { header('Location: ./'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locked = ($_SESSION['login_lock'] ?? 0) > time();
    if ($locked) {
        $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM admins WHERE email = ?');
        $st->execute([mb_strtolower(trim((string)($_POST['email'] ?? '')))]);
        $admin = $st->fetch();
        if ($admin && password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_email'] = mb_strtolower(trim((string)$_POST['email']));
            unset($_SESSION['login_fails'], $_SESSION['login_lock']);
            header('Location: ./');
            exit;
        }
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            $_SESSION['login_lock'] = time() + 900;
            $_SESSION['login_fails'] = 0;
        }
        usleep(600000);
        $error = 'Identifiants incorrects.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Connexion — Backoffice</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="auth-body">
  <main class="auth-card reveal">
    <div class="brand"><span class="brand-dot"></span> Backoffice</div>
    <h1>Connexion</h1>
    <p class="muted">Accès réservé à l'administration.</p>
    <form method="post" class="form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field"><span>E-mail</span>
        <input type="email" name="email" required autofocus></label>
      <label class="field"><span>Mot de passe</span>
        <input type="password" name="password" required></label>
      <button class="btn btn-primary" type="submit">Se connecter</button>
    </form>
  </main>
  <div id="toasts" class="toasts" aria-live="polite"></div>
  <script>window.__flash = <?= json_encode($error ? [['message' => $error, 'type' => 'error']] : [], JSON_UNESCAPED_UNICODE) ?>;</script>
  <script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
