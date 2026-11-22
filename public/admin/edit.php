<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/Referentiel.php';
require __DIR__ . '/../../src/students.php';
require_admin();

$pdo  = db();
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back = (string)($_GET['back'] ?? $_POST['back'] ?? '');
$backUrl = './' . ($back !== '' ? '?' . $back : '');

$st = $pdo->prepare('SELECT * FROM students WHERE id = ?');
$st->execute([$id]);
$student = $st->fetch();
if (!$student) {
    flash('Étudiant introuvable.', 'error');
    redirect($backUrl);
}

$form   = $student;   // valeurs affichées
$errors = [];
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, rechargez la page.';
    } else {
        [$identity, $errors] = validate_identity($_POST);
        $form = array_merge($form, $identity);

        // Mot de passe : saisi à la main, ou régénéré
        if (!empty($_POST['regen_password'])) {
            $password = make_password($identity['lastname'] ?: $student['lastname']);
        } else {
            $password = trim((string)($_POST['password'] ?? ''));
            if (strlen($password) < 8 || strlen($password) > 100) $errors['password'] = 'Mot de passe : 8 à 100 caractères.';
        }
        $form['password'] = $password;

        // Parcours : revalidé auprès de l'API seulement s'il a changé
        $ids = [];
        foreach (['section_id', 'cycle_id', 'level_id', 'class_id'] as $k) $ids[$k] = (int)($_POST[$k] ?? 0);
        $form = array_merge($form, $ids);
        $parcours = null;
        $changed = false;
        foreach ($ids as $k => $v) if ($v !== (int)$student[$k]) $changed = true;
        if ($changed) {
            try {
                $parcours = resolve_parcours($ids['section_id'], $ids['cycle_id'], $ids['level_id'], $ids['class_id']);
                if (!$parcours) $errors['class_id'] = 'Parcours incohérent : choisissez à nouveau la section, le cycle, le niveau et la classe.';
            } catch (RuntimeException $ex) {
                error_log('[edit] ' . $ex->getMessage());
                $errors['class_id'] = "Le référentiel est indisponible : impossible de changer de classe pour l'instant.";
            }
        }

        if (!$errors) {
            $data = $identity + ['password' => $password] + ($parcours ?? []);
            if (!empty($_POST['reexport'])) $data['exported_at'] = null;
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            try {
                $pdo->prepare("UPDATE students SET $set WHERE id = ?")->execute([...array_values($data), $id]);
                $msg = "Étudiant {$identity['lastname']} {$identity['firstname']} modifié.";
                if ($parcours && $parcours['cohort'] !== $student['cohort']) $msg .= " Nouvelle cohorte : {$parcours['cohort']}.";
                flash($msg, 'success');
                redirect($backUrl);
            } catch (PDOException $ex) {
                $dup = duplicate_error($ex);
                if (!$dup) throw $ex;
                [$error, $errors] = $dup;
            }
        }
        $error ??= 'Vérifiez les champs signalés.';
    }
    flash($error, 'error');   // affiché en toast par le gabarit
}

$err = fn(string $k) => isset($errors[$k]) ? '<small class="err">' . e($errors[$k]) . '</small>' : '';
// Chaque liste affiche d'abord la valeur actuelle ; le script charge ensuite les autres choix.
$levels = [
    'section_id' => ['Section', 'section_label'],
    'cycle_id'   => ['Cycle',   'cycle_label'],
    'level_id'   => ['Niveau',  'level_label'],
    'class_id'   => ['Classe',  'class_label'],
];
$pageTitle = 'Modifier un étudiant';
$active = 'students';
require __DIR__ . '/../../includes/header.php';
?>

  <header class="reveal page-head">
    <div>
      <h1>Modifier l'étudiant</h1>
      <p class="muted">Cohorte actuelle : <span class="pill"><?= e($student['cohort']) ?></span>
        <?= $student['exported_at'] ? '<span class="tag tag-done">Déjà exporté</span>' : '<span class="tag tag-new">Pas encore exporté</span>' ?></p>
    </div>
    <a class="btn" href="<?= e($backUrl) ?>">← Retour à la liste</a>
  </header>

  <form method="post" class="panel form reveal" id="edit-form" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="back" value="<?= e($back) ?>">

    <h2>Identité</h2>
    <div class="grid-2">
      <label class="field<?= isset($errors['lastname']) ? ' has-error' : '' ?>"><span>Nom</span>
        <input name="lastname" required maxlength="100" value="<?= e($form['lastname']) ?>"><?= $err('lastname') ?></label>
      <label class="field<?= isset($errors['firstname']) ? ' has-error' : '' ?>"><span>Prénom(s)</span>
        <input name="firstname" required maxlength="100" value="<?= e($form['firstname']) ?>"><?= $err('firstname') ?></label>
      <label class="field<?= isset($errors['email']) ? ' has-error' : '' ?>"><span>Adresse e-mail</span>
        <input name="email" type="email" required maxlength="190" value="<?= e($form['email']) ?>"><?= $err('email') ?></label>
      <label class="field<?= isset($errors['username']) ? ' has-error' : '' ?>"><span>Nom d'utilisateur</span>
        <input name="username" required maxlength="50" autocapitalize="none" spellcheck="false" value="<?= e($form['username']) ?>"><?= $err('username') ?></label>
      <label class="field<?= isset($errors['password']) ? ' has-error' : '' ?>"><span>Mot de passe</span>
        <input name="password" maxlength="100" autocomplete="off" spellcheck="false" value="<?= e($form['password']) ?>"><?= $err('password') ?></label>
      <label class="check" style="align-self:end;padding-bottom:.9rem"><input type="checkbox" name="regen_password" value="1"> Générer un nouveau mot de passe</label>
    </div>

    <h2>Parcours</h2>
    <p class="muted small">Changer la classe recalcule la cohorte, et donc les cours et le groupe Moodle.</p>
    <div class="grid-2">
      <?php foreach ($levels as $name => [$label, $labelCol]): ?>
        <label class="field<?= isset($errors[$name]) ? ' has-error' : '' ?>"><span><?= $label ?></span>
          <?php $v = (int)$form[$name]; ?>
          <select name="<?= $name ?>" data-current="<?= $v ?>">
            <option value="<?= $v ?>" selected><?= $v === (int)$student[$name] ? e($student[$labelCol]) : 'Choix modifié' ?></option>
          </select><?= $err($name) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <label class="check"><input type="checkbox" name="reexport" value="1" <?= $student['exported_at'] ? 'checked' : '' ?>>
      Repasser en « Nouveau » pour l'inclure dans le prochain export</label>

    <div class="export">
      <button class="btn btn-primary" type="submit">Enregistrer les modifications</button>
      <a class="btn" href="<?= e($backUrl) ?>">Annuler</a>
    </div>
  </form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
