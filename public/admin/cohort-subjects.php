<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/moodle_courses.php';

require_admin();

$pdo = db();

/* =========================================================
   Helpers : "lier si existe, créer sinon"
   ========================================================= */
function norm_label(string $s): string {
    // trim + collapse whitespace (y compris espaces insécables)
    return preg_replace('/\s+/u', ' ', trim($s));
}

/**
 * Retourne la matière (id, label, created).
 * Matching insensible à la casse ET aux accents (collation utf8mb4_unicode_ci).
 */
function find_or_create_subject(PDO $pdo, string $input): array {
    $label = norm_label($input);
    if ($label === '') throw new RuntimeException('Le libellé de la matière est requis.');

    $stmt = $pdo->prepare("SELECT id, label FROM subjects WHERE label = ?");
    $stmt->execute([$label]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['id' => (int)$row['id'], 'label' => $row['label'], 'created' => false];
    }
    $pdo->prepare("INSERT INTO subjects (label) VALUES (?)")->execute([$label]);
    return ['id' => (int)$pdo->lastInsertId(), 'label' => $label, 'created' => true];
}

/**
 * Retourne l'enseignant (id, fullname, phone, created).
 * - Si l'enseignant existe et qu'un NOUVEAU téléphone non vide est fourni → mise à jour.
 * - Si l'enseignant existe et qu'aucun téléphone n'est fourni → on garde l'ancien.
 */
function find_or_create_teacher(PDO $pdo, string $input, string $phone): array {
    $fullname = norm_label($input);
    $phone    = trim($phone);
    if ($fullname === '') throw new RuntimeException("Le nom de l'enseignant est requis.");

    $stmt = $pdo->prepare("SELECT id, fullname, phone FROM teachers WHERE fullname = ?");
    $stmt->execute([$fullname]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($phone !== '' && $phone !== $row['phone']) {
            $pdo->prepare("UPDATE teachers SET phone = ? WHERE id = ?")->execute([$phone, (int)$row['id']]);
        }
        return [
            'id'       => (int)$row['id'],
            'fullname' => $row['fullname'],
            'phone'    => $phone !== '' ? $phone : $row['phone'],
            'created'  => false,
        ];
    }
    $pdo->prepare("INSERT INTO teachers (fullname, phone) VALUES (?, ?)")->execute([$fullname, $phone]);
    return ['id' => (int)$pdo->lastInsertId(), 'fullname' => $fullname, 'phone' => $phone, 'created' => true];
}

/* =========================================================
   ACTIONS (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('CSRF invalide.');
    }

    $action = $_POST['action'] ?? '';
    $cohort = trim((string)($_POST['cohort'] ?? ''));
    $flash  = [];

    try {
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM cohort_subjects WHERE id = ? AND cohort = ?")
                ->execute([(int)($_POST['id'] ?? 0), $cohort]);
            $flash[] = ['message' => 'Matière retirée de la cohorte.', 'type' => 'success'];

        } elseif ($action === 'sync_filiere') {
            if ($cohort === '') throw new RuntimeException('Cohorte manquante.');
            $fil = filiere_cohorts($pdo, $cohort);
            if (!$fil['others']) throw new RuntimeException("Aucune autre cohorte dans la filière {$fil['prefix']}.");
            $stmt = $pdo->prepare('SELECT subject_id, teacher_id, hours FROM cohort_subjects WHERE cohort = ?');
            $stmt->execute([$cohort]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) throw new RuntimeException('Cette cohorte n\'a aucune matière à copier.');
            $n = copy_subjects_to_cohorts($pdo, $rows, $fil['others']);
            $flash[] = ['message' => $n
                ? "$n matière(s) ajoutée(s) dans " . count($fil['others']) . " cohorte(s) de la filière {$fil['prefix']}."
                : "Toutes les cohortes de la filière {$fil['prefix']} ont déjà ces matières.", 'type' => 'success'];

        } elseif ($action === 'add' || $action === 'update') {
            if ($cohort === '') throw new RuntimeException('Cohorte manquante.');

            $hoursRaw = trim((string)($_POST['hours'] ?? ''));
            $hours    = $hoursRaw !== '' ? max(0, (int)$hoursRaw) : null;

            /* --- Réutilisation / création --- */
            $subject = find_or_create_subject($pdo, (string)($_POST['subject'] ?? ''));
            $teacher = find_or_create_teacher($pdo, (string)($_POST['teacher'] ?? ''), (string)($_POST['phone'] ?? ''));

            /* --- Insertion / mise à jour de l'affectation --- */
            if ($action === 'add') {
                $stmt = $pdo->prepare("SELECT id FROM cohort_subjects WHERE cohort = ? AND subject_id = ?");
                $stmt->execute([$cohort, $subject['id']]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException("« {$subject['label']} » est déjà enseignée dans cette cohorte.");
                }
                $pdo->prepare("INSERT INTO cohort_subjects (cohort, subject_id, teacher_id, hours) VALUES (?, ?, ?, ?)")
                    ->execute([$cohort, $subject['id'], $teacher['id'], $hours]);

                // Même matière pour toutes les cohortes de la filière (celles qui l'ont déjà sont laissées telles quelles)
                if (!empty($_POST['propagate'])) {
                    $fil = filiere_cohorts($pdo, $cohort);
                    $n = copy_subjects_to_cohorts($pdo, [['subject_id' => $subject['id'], 'teacher_id' => $teacher['id'], 'hours' => $hours]], $fil['others']);
                    if ($fil['others']) {
                        $flash[] = ['message' => "« {$subject['label']} » ajoutée aussi à $n autre(s) cohorte(s) de la filière {$fil['prefix']}"
                            . ($n < count($fil['others']) ? ' (les autres l\'avaient déjà).' : '.'), 'type' => 'success'];
                    }
                }
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM cohort_subjects WHERE cohort = ? AND subject_id = ? AND id <> ?");
                $stmt->execute([$cohort, $subject['id'], $id]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException("Une autre ligne de cette cohorte utilise déjà ce libellé.");
                }
                $pdo->prepare("UPDATE cohort_subjects SET subject_id = ?, teacher_id = ?, hours = ? WHERE id = ? AND cohort = ?")
                    ->execute([$subject['id'], $teacher['id'], $hours, $id, $cohort]);
            }

            /* --- Feedback transparent --- */
            $parts = [];
            $parts[] = $subject['created']
                ? "matière créée (« {$subject['label']} »)"
                : "matière existante réutilisée (« {$subject['label']} »)";
            $parts[] = $teacher['created']
                ? "enseignant créé (« {$teacher['fullname']} »)"
                : "enseignant existant réutilisé (« {$teacher['fullname']} »)";

            $msg = ($action === 'add' ? 'Ligne ajoutée : ' : 'Ligne mise à jour : ') . implode(' · ', $parts) . '.';
            $flash[] = ['message' => $msg, 'type' => 'success'];
        }
    } catch (Throwable $ex) {
        $flash[] = ['message' => $ex->getMessage(), 'type' => 'error'];
    }

    foreach ($flash as $f) flash($f['message'], $f['type']);
    redirect('./cohort-subjects.php?cohort=' . urlencode($cohort));
}

/* =========================================================
   LECTURE
   ========================================================= */
$cohorts = $pdo->query("
    SELECT s.cohort,
           COUNT(DISTINCT s.id) AS n_students,
           (SELECT COUNT(*) FROM cohort_subjects cs WHERE cs.cohort = s.cohort) AS n_subjects
    FROM students s
    GROUP BY s.cohort
    ORDER BY s.cohort
")->fetchAll(PDO::FETCH_ASSOC);

// Cohorte saisie dans la liste de suggestions : nom exact, sinon première cohorte qui contient le texte
$selectedCohort = trim((string)($_GET['cohort'] ?? ''));
$names = array_column($cohorts, 'cohort');
if ($selectedCohort !== '' && !in_array($selectedCohort, $names, true)) {
    $needle = ascii($selectedCohort);
    $match = array_values(array_filter($names, fn($n) => str_contains(ascii($n), $needle)));
    if ($match) $selectedCohort = $match[0];
    else { flash("Aucune cohorte ne correspond à « {$selectedCohort} ».", 'error'); $selectedCohort = ''; }
}
if ($selectedCohort === '' && $cohorts) $selectedCohort = $cohorts[0]['cohort'];

$editRow = null;
if (!empty($_GET['edit']) && $selectedCohort !== '') {
    $stmt = $pdo->prepare("
        SELECT cs.*, s.label AS subject, t.fullname AS teacher, t.phone
        FROM cohort_subjects cs
        JOIN subjects s ON s.id = cs.subject_id
        JOIN teachers t ON t.id = cs.teacher_id
        WHERE cs.id = ? AND cs.cohort = ?
    ");
    $stmt->execute([(int)$_GET['edit'], $selectedCohort]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$rows = [];
if ($selectedCohort !== '') {
    $stmt = $pdo->prepare("
        SELECT cs.id, s.label AS subject, t.fullname AS teacher, t.phone, cs.hours
        FROM cohort_subjects cs
        JOIN subjects s ON s.id = cs.subject_id
        JOIN teachers t ON t.id = cs.teacher_id
        WHERE cs.cohort = ?
        ORDER BY s.label
    ");
    $stmt->execute([$selectedCohort]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filiere = $selectedCohort !== '' ? filiere_cohorts($pdo, $selectedCohort) : ['prefix' => '', 'others' => []];

$suggestedSubjects = $pdo->query("SELECT label FROM subjects ORDER BY label")->fetchAll(PDO::FETCH_COLUMN);
$allTeachers       = $pdo->query("SELECT fullname, phone FROM teachers ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
$suggestedTeachers = array_column($allTeachers, 'fullname');
$teacherPhoneMap   = array_column($allTeachers, 'phone', 'fullname'); // fullname => phone
$pageTitle = 'Matières par cohorte';
$active = 'subjects';
require __DIR__ . '/../../includes/header.php';
?>


  <header class="reveal page-head">
  <div>
    <h1>Matières par cohorte</h1>
    <p class="muted">Pour chaque cohorte, renseignez les matières enseignées, l'enseignant qui les dispense et son numéro.</p>
  </div>
  <a class="btn btn-primary" href="./export-pdf.php" rel="noopener" data-no-async>
    Exporter le récapitulatif PDF
  </a>
</header>


  <?php if (!$cohorts): ?>
    <section class="panel">
      <p class="empty">Aucune cohorte n'existe encore. Les cohortes apparaissent dès qu'un étudiant s'inscrit.</p>
    </section>
  <?php else: ?>

    <!-- Sélecteur de cohorte -->
    <section class="panel reveal">
      <form method="get" class="filters">
        <label class="field" style="flex:1;">
          <span>Cohorte</span>
          <input name="cohort" list="cohorts-list" value="<?= e($selectedCohort) ?>" autocomplete="off"
                 placeholder="Tapez ou choisissez une cohorte" onfocus="this.select()" data-submit-on-change>
          <datalist id="cohorts-list">
            <?php foreach ($cohorts as $c): ?>
              <option value="<?= e($c['cohort']) ?>"><?= (int)$c['n_students'] ?> étudiant(s) · <?= (int)$c['n_subjects'] ?> matière(s)</option>
            <?php endforeach; ?>
          </datalist>
        </label>
        <button class="btn" type="submit" style="align-self:end">Afficher</button>
      </form>
    </section>

    <!-- Formulaire ajout / modification -->
    <section class="panel reveal">
      <h2><?= $editRow ? 'Modifier une matière' : 'Ajouter une matière' ?></h2>
      <form method="post" class="form" id="subject-form" action="./cohort-subjects.php"
            data-teacher-phones='<?= json_encode($teacherPhoneMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>'>
        <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'add' ?>">
        <input type="hidden" name="cohort" value="<?= e($selectedCohort) ?>">
        <?php if ($editRow): ?>
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
        <?php endif; ?>

        <div class="grid-2">
          <label class="field">
            <span>Matière</span>
            <input name="subject" list="subjects-list" required maxlength="150"
                   value="<?= e($editRow['subject'] ?? '') ?>"
                   placeholder="Ex. Comptabilité générale"
                   autocomplete="off">
            <datalist id="subjects-list">
              <?php foreach ($suggestedSubjects as $s): ?>
                <option value="<?= e($s) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </label>

          <label class="field">
            <span>Enseignant</span>
            <input name="teacher" list="teachers-list" required maxlength="150"
                   value="<?= e($editRow['teacher'] ?? '') ?>"
                   placeholder="Nom complet"
                   autocomplete="off">
            <datalist id="teachers-list">
              <?php foreach ($suggestedTeachers as $t): ?>
                <option value="<?= e($t) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </label>
        </div>

        <div class="grid-2">
          <label class="field">
            <span>Numéro de téléphone</span>
            <input name="phone" type="tel" maxlength="40"
                   value="<?= e($editRow['phone'] ?? '') ?>"
                   placeholder="Ex. +225 07 00 00 00">
          </label>
          <label class="field">
            <span>Heures (facultatif)</span>
            <input name="hours" type="number" min="1" max="999"
                   value="<?= e((string)($editRow['hours'] ?? '')) ?>">
          </label>
        </div>

        <?php if (!$editRow && $filiere['others']): ?>
          <label class="check">
            <input type="checkbox" name="propagate" value="1" checked>
            Ajouter aussi aux <?= count($filiere['others']) ?> autre(s) cohorte(s) de la filière <strong><?= e($filiere['prefix']) ?></strong>
          </label>
        <?php endif; ?>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit">
            <?= $editRow ? 'Enregistrer les modifications' : 'Ajouter la matière' ?>
          </button>
          <?php if ($editRow): ?>
            <a class="btn" href="?cohort=<?= urlencode($selectedCohort) ?>">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <!-- Liste des matières de la cohorte -->
    <section class="panel reveal">
      <div class="dup-head">
        <h2>Matières enseignées — <?= e($selectedCohort) ?></h2>
        <?php if ($filiere['others'] && $rows): ?>
          <span class="bulk-spacer"></span>
          <form method="post" action="./cohort-subjects.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_filiere">
            <input type="hidden" name="cohort" value="<?= e($selectedCohort) ?>">
            <button class="btn" type="submit"
                    onclick="return confirm('Ajouter les <?= count($rows) ?> matière(s) de <?= e($selectedCohort) ?> aux <?= count($filiere['others']) ?> autre(s) cohorte(s) de la filière <?= e($filiere['prefix']) ?> ? Les matières déjà présentes ne sont pas modifiées.');">
              Copier dans toute la filière <?= e($filiere['prefix']) ?></button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($filiere['others']): ?>
        <p class="muted small">Même filière (<?= e($filiere['prefix']) ?>) :
          <?php foreach ($filiere['others'] as $o): ?><a class="pill-link" href="./cohort-subjects.php?cohort=<?= e(urlencode($o)) ?>"><span class="pill"><?= e($o) ?></span></a> <?php endforeach; ?>
        </p>
      <?php endif; ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Matière</th>
              <th>Enseignant</th>
              <th>Téléphone</th>
              <th>Heures</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" class="empty">Aucune matière enregistrée pour cette cohorte.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= e($r['subject']) ?></strong></td>
                <td><?= e($r['teacher']) ?></td>
                <td>
                  <?php if ($r['phone'] !== ''): ?>
                    <a class="link" href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td><?= $r['hours'] !== null ? (int)$r['hours'] . ' h' : '<span class="muted">—</span>' ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <a class="btn" href="?cohort=<?= urlencode($selectedCohort) ?>&edit=<?= (int)$r['id'] ?>">Modifier</a>
                  <form method="post" action="./cohort-subjects.php" style="display:inline;"
                        onsubmit="return confirm('Retirer cette matière de la cohorte ?');">
                    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="cohort" value="<?= e($selectedCohort) ?>">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <button class="btn" type="submit">Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  <?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
