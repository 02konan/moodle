<?php
declare(strict_types=1);
// Cours : édition de la structure (sections → sous-sections / QCM) et accès à la fiche Word.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require_admin();

$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$plan = plan_get($pdo, $id);
if (!$plan) { flash('Cours introuvable.', 'error'); redirect('./programmes.php'); }
$self = './programme.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) { flash('Session expirée, rechargez la page.', 'error'); redirect($self); }
    $itemId = (int)($_POST['item_id'] ?? 0);
    try {
        switch ($_POST['action'] ?? '') {
            case 'rename_plan':
                $pdo->prepare('UPDATE course_plans SET title = ? WHERE id = ?')->execute([plan_title((string)($_POST['title'] ?? '')), $id]);
                flash('Titre du cours enregistré.', 'success');
                break;
            case 'add':
                $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
                $type = (string)($_POST['type'] ?? '');
                plan_add_item($pdo, $id, $parent, $type, (string)($_POST['title'] ?? ''));
                flash((PLAN_ITEM_TYPES[$type] ?? 'Élément') . ' ajouté' . ($type === 'section' || $type === 'subsection' ? 'e' : '') . '.', 'success');
                break;
            case 'rename':
                plan_rename_item($pdo, $id, $itemId, (string)($_POST['title'] ?? ''));
                break;
            case 'move':
                plan_move_item($pdo, $id, $itemId, (int)($_POST['dir'] ?? 1));
                break;
            case 'delete':
                plan_delete_item($pdo, $id, $itemId);
                flash('Élément supprimé.', 'success');
                break;
            default:
                throw new RuntimeException('Action inconnue.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect($self);
}

$tree = plan_tree($pdo, $id);
// Nombre de cohortes programmées par QCM (bouton « Agenda »)
$st = $pdo->prepare('SELECT s.item_id, COUNT(*) n FROM quiz_schedules s JOIN course_plan_items i ON i.id = s.item_id WHERE i.plan_id = ? GROUP BY s.item_id');
$st->execute([$id]);
$agendaCounts = array_map('intval', array_column($st->fetchAll(), 'n', 'item_id'));
$nQuiz = 0;

/* ---------- Composants ---------- */
$hidden = fn(array $f) => implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">', array_keys($f), $f));
$base = ['csrf' => csrf_token(), 'id' => $id];

/** Ligne d'un élément : numéro, titre modifiable, monter / descendre / supprimer. */
$row = function (array $it, string $label, bool $first, bool $last) use ($hidden, $base, $agendaCounts): string {
    $id = (int)$it['id'];
    $what = PLAN_ITEM_TYPES[$it['type']];
    $deleteMsg = $it['type'] === 'quiz' ? "Supprimer ce QCM ?" : "Supprimer cette " . mb_strtolower($what) . " et tout son contenu ?";
    $btn = fn(string $action, string $text, string $title, array $extra = [], bool $disabled = false, string $confirm = '') =>
        '<form method="post" action="./programme.php">' . $hidden($base + ['action' => $action, 'item_id' => $id] + $extra)
        . '<button class="icon-btn" type="submit" title="' . e($title) . '" aria-label="' . e($title) . '"' . ($disabled ? ' disabled' : '')
        . ($confirm !== '' ? ' onclick="return confirm(\'' . e(addslashes($confirm)) . '\');"' : '') . '>' . $text . '</button></form>';
    return '<div class="plan-row plan-' . $it['type'] . '">'
        . '<span class="plan-label">' . e($label) . '</span>'
        . '<form method="post" action="./programme.php" class="plan-rename" data-key="rename-' . $id . '">' . $hidden($base + ['action' => 'rename', 'item_id' => $id])
        . '<input type="text" name="title" value="' . e($it['title']) . '" maxlength="255" required aria-label="Titre : ' . e($what) . '">'
        . '<button class="icon-btn" type="submit" title="Enregistrer le titre" aria-label="Enregistrer le titre">✓</button></form>'
        . '<span class="plan-tools">'
        . ($it['type'] === 'quiz'
            ? '<a class="btn plan-agenda' . (!empty($agendaCounts[$id]) ? ' has-dates' : '') . '" href="./quiz-agenda.php?plan=' . (int)$base['id'] . '&amp;item=' . $id . '" title="Programmer ce QCM par cohorte">Agenda'
              . (!empty($agendaCounts[$id]) ? ' <span class="plan-agenda-n">' . $agendaCounts[$id] . '</span>' : '') . '</a>'
            : '')
        . $btn('move', '↑', 'Monter', ['dir' => -1], $first)
        . $btn('move', '↓', 'Descendre', ['dir' => 1], $last)
        . $btn('delete', '✕', 'Supprimer', [], false, $deleteMsg)
        . '</span></div>';
};

/** Formulaire d'ajout dans un conteneur : QCM (et sous-section si c'est une section). */
$addForm = function (int $parentId, bool $allowSub) use ($hidden, $base): string {
    return '<form method="post" action="./programme.php" class="plan-add" data-key="add-' . $parentId . '">' . $hidden($base + ['action' => 'add', 'parent_id' => $parentId])
        . '<input type="text" name="title" maxlength="255" required autocomplete="off" placeholder="' . ($allowSub ? 'Titre du QCM ou de la sous-section' : 'Titre du QCM') . '">'
        . '<button class="btn" type="submit" name="type" value="quiz">＋ QCM</button>'
        . ($allowSub ? '<button class="btn" type="submit" name="type" value="subsection">＋ Sous-section</button>' : '')
        . '</form>';
};

$pageTitle = $plan['title'];
$active = 'plans';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head">
    <div>
      <p class="muted small"><a class="link" href="./programmes.php">← Tous les cours</a></p>
      <h1><?= e($plan['title']) ?></h1>
    </div>
    <span class="export">
      <button class="btn" type="button" data-agenda-pdf="<?= $id ?>"><span class="btn-label">Agenda PDF</span></button>
      <a class="btn btn-primary" href="./programme-word.php?id=<?= $id ?>" data-no-async>Télécharger la fiche Word</a>
    </span>
  </header>

  <section class="panel reveal">
    <form method="post" action="./programme.php" class="filters" data-key="plan-title">
      <?= $hidden($base + ['action' => 'rename_plan']) ?>
      <label class="field" style="flex:1"><span>Titre du cours</span>
        <input type="text" name="title" value="<?= e($plan['title']) ?>" maxlength="255" required></label>
      <button class="btn" type="submit" style="align-self:end">Enregistrer</button>
    </form>
  </section>

  <section class="panel reveal plan-tree">
    <h2>Structure du cours</h2>
    <?php if (!$tree): ?><p class="muted small">Commencez par ajouter une section.</p><?php endif; ?>

    <?php foreach ($tree as $si => $section): $sNo = $si + 1; $subNo = 0; ?>
      <div class="plan-section">
        <?= $row($section, "Section $sNo", $si === 0, $si === count($tree) - 1) ?>
        <div class="plan-children">
          <?php foreach ($section['children'] as $ci => $child): $firstC = $ci === 0; $lastC = $ci === count($section['children']) - 1; ?>
            <?php if ($child['type'] === 'quiz'): $nQuiz++; ?>
              <?= $row($child, "QCM $nQuiz", $firstC, $lastC) ?>
            <?php else: $subNo++; ?>
              <div class="plan-sub">
                <?= $row($child, "$sNo.$subNo", $firstC, $lastC) ?>
                <div class="plan-children">
                  <?php foreach ($child['children'] as $qi => $quiz): $nQuiz++; ?>
                    <?= $row($quiz, "QCM $nQuiz", $qi === 0, $qi === count($child['children']) - 1) ?>
                  <?php endforeach; ?>
                  <?= $addForm((int)$child['id'], false) ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
          <?= $addForm((int)$section['id'], true) ?>
        </div>
      </div>
    <?php endforeach; ?>

    <form method="post" action="./programme.php" class="plan-add plan-add-section" data-key="add-root">
      <?= $hidden($base + ['action' => 'add', 'type' => 'section']) ?>
      <input type="text" name="title" maxlength="255" required autocomplete="off" placeholder="Titre de la nouvelle section">
      <button class="btn btn-primary" type="submit">＋ Section</button>
    </form>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
