<?php
declare(strict_types=1);
// Cours : liste, création et suppression. Le détail (sections, sous-sections, QCM) est dans programme.php.
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/course_plans.php';
require_admin();

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) { flash('Session expirée, rechargez la page.', 'error'); redirect('./programmes.php'); }
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $pdo->prepare('INSERT INTO course_plans (title) VALUES (?)')->execute([plan_title((string)($_POST['title'] ?? ''))]);
                $id = (int)$pdo->lastInsertId();
                flash('Cours créé : ajoutez maintenant ses sections.', 'success');
                redirect('./programme.php?id=' . $id);
            case 'delete':
                $plan = plan_get($pdo, (int)($_POST['id'] ?? 0));
                if (!$plan) throw new RuntimeException('Cours introuvable.');
                $pdo->prepare('DELETE FROM course_plans WHERE id = ?')->execute([$plan['id']]);
                flash("Cours « {$plan['title']} » supprimé.", 'success');
                break;
            default:
                throw new RuntimeException('Action inconnue.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('./programmes.php' . ($q !== '' ? '?q=' . urlencode($q) : ''));
}

$sql = 'SELECT * FROM course_plans';
$params = [];
if ($q !== '') { $sql .= ' WHERE title LIKE ?'; $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; }
$st = $pdo->prepare($sql);
$st->execute($params);
$plans = $st->fetchAll();
usort($plans, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
$counts = plan_counts($pdo);
$totalQuizzes = array_sum(array_column($counts, 'quizzes'));

$pageTitle = 'Cours';
$active = 'plans';
require __DIR__ . '/../../includes/header.php';
?>
  <header class="reveal page-head">
    <div>
      <h1>Cours</h1>
      <p class="muted">Structure des cours Moodle (sections, sous-sections et QCM) et fiche Word « Programmation des évaluations » à transmettre aux professeurs.</p>
    </div>
  </header>

  <section class="stats">
    <div class="stat reveal"><span class="stat-n"><?= count($plans) ?></span><span class="muted">Cours</span></div>
    <div class="stat reveal" style="--d:.06s"><span class="stat-n"><?= (int)$totalQuizzes ?></span><span class="muted">QCM programmés</span></div>
  </section>

  <section class="panel reveal" style="--d:.12s">
    <h2>Nouveau cours</h2>
    <form method="post" action="./programmes.php" class="filters" data-key="create-plan">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <input type="text" name="title" required maxlength="255" autocomplete="off" placeholder="Titre du cours (ex. BTS 2 FC: MATHÉMATIQUES GÉNÉRALES)">
      <button class="btn btn-primary" type="submit">Créer le cours</button>
    </form>
  </section>

  <section class="panel reveal" style="--d:.16s">
    <form class="filters" method="get" data-live-form role="search">
      <input type="search" name="q" data-live autocomplete="off" placeholder="Rechercher un cours" value="<?= e($q) ?>">
      <button class="btn" type="submit">Rechercher</button>
      <?php if ($q !== ''): ?><a class="btn" href="./programmes.php">Tout afficher</a><?php endif; ?>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cours</th><th>Sections</th><th>Sous-sections</th><th>QCM</th><th>Modifié le</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p): $c = $counts[(int)$p['id']] ?? ['sections' => 0, 'subsections' => 0, 'quizzes' => 0]; ?>
          <tr>
            <td><a class="link" href="./programme.php?id=<?= (int)$p['id'] ?>"><strong><?= e($p['title']) ?></strong></a></td>
            <td><?= $c['sections'] ?></td>
            <td><?= $c['subsections'] ?></td>
            <td><strong><?= $c['quizzes'] ?></strong></td>
            <td class="small muted"><?= e(date('d/m/Y H:i', strtotime($p['updated_at']))) ?></td>
            <td class="row-actions">
              <a class="btn" href="./programme.php?id=<?= (int)$p['id'] ?>">Ouvrir</a>
              <a class="btn btn-primary" href="./programme-word.php?id=<?= (int)$p['id'] ?>" data-no-async>Fiche Word</a>
              <form method="post" action="./programmes.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="icon-btn" type="submit" title="Supprimer" aria-label="Supprimer"
                        onclick="return confirm('Supprimer le cours « <?= e(addslashes($p['title'])) ?> » et toute sa structure ?');">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$plans): ?>
          <tr><td colspan="6" class="empty"><?= $q !== '' ? 'Aucun cours ne correspond à cette recherche.' : 'Aucun cours pour l\'instant : créez-en un ci-dessus.' ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
