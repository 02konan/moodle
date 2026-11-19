<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/* -------- Auth (à adapter) -------- */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ./login.php');
    exit;
}

$pdo = db();

/* =========================================================
   Données : on part des étudiants (source de vérité),
   pour inclure les cohortes SANS matière (tableau vide).
   ========================================================= */
$sql = "
    SELECT
        sec.section_label,
        sec.section_code,
        sec.cohort,
        sub.label   AS subject,
        t.fullname  AS teacher,
        t.phone     AS phone
    FROM (
        SELECT DISTINCT section_label, section_code, cohort
        FROM students
        WHERE cohort <> ''
    ) sec
    LEFT JOIN cohort_subjects cs ON cs.cohort     = sec.cohort
    LEFT JOIN subjects        sub ON sub.id        = cs.subject_id
    LEFT JOIN teachers        t   ON t.id          = cs.teacher_id
    ORDER BY sec.section_label ASC, sec.cohort ASC, sub.label ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   Regroupement section → cohorts → matières
   Cohorte vide = entrée présente avec subjects = []
   ========================================================= */
$sections = [];
foreach ($rows as $r) {
    $sectionLabel = str_replace(
        'INSTITUT DE FORMATION SAINTE MARIE',
        'IFSM',
        $r['section_label']
    );

    $key = $sectionLabel . '|' . $r['section_code'];
    if (!isset($sections[$key])) {
        $sections[$key] = [
            'label'   => $sectionLabel,
            'code'    => $r['section_code'],
            'cohorts' => [],
        ];
    }
    $cohort = $r['cohort'];
    if (!isset($sections[$key]['cohorts'][$cohort])) {
        $sections[$key]['cohorts'][$cohort] = [];
    }
    if ($r['subject'] !== null) {   // LEFT JOIN : rien à ajouter si la cohorte est vide
        $sections[$key]['cohorts'][$cohort][] = [
            'subject' => $r['subject'],
            'teacher' => $r['teacher'],
            'phone'   => $r['phone'] ?? '',
        ];
    }
}

/* --- Tri naturel (LICENCE-1 < LICENCE-2 < LICENCE-10) --- */
$sections = array_values($sections);
usort($sections, fn($a, $b) => strcasecmp($a['label'], $b['label']));
foreach ($sections as &$sec) {
    $list = [];
    foreach ($sec['cohorts'] as $name => $subjects) {
        $list[] = ['cohort' => $name, 'subjects' => $subjects];
    }
    usort($list, fn($a, $b) => strnatcasecmp($a['cohort'], $b['cohort']));
    $sec['cohorts'] = $list;
}
unset($sec);

$hasData = $sections !== [];

/* --- Chargement du logo en Base64 --- */
$logoPath = __DIR__ . '/../assets/logo.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

$payload = [
    'sections'  => $sections,
    'generated' => date('d/m/Y à H:i'),
    'logo'      => $logoBase64,
];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= e(csrf_token()) ?>">
  <title>Récapitulatif PDF — Matières par cohorte</title>
  <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body class="admin-body">

<header class="topbar">
  <a class="brand" href="./index.php">
    <span class="brand-dot"></span> Moodle Admin
  </a>
  <nav>
    <a class="link small" href="./index.php">Inscription étudiant</a>
    <a class="link small" href="./cohort-subjects.php" aria-current="page">Matières par cohorte</a>
    <a class="link small" href="./moodle-courses.php">Cours &amp; groupes</a>
    <span class="nav-sep" aria-hidden="true"></span>
    <span class="nav-user small muted"><?= e($_SESSION['admin_email'] ?? 'Admin') ?></span>
    <a class="btn-logout small" href="./logout.php">Déconnexion</a>
  </nav>
</header>

<main class="admin">
  <section class="panel" id="status-panel">
    <h1>Préparation du récapitulatif PDF</h1>
    <p class="muted" id="status-msg">
      <?= $hasData
          ? 'Le fichier va se télécharger dans un instant…'
          : 'Aucune cohorte n\'existe encore : il faut au moins un étudiant inscrit.' ?>
    </p>
    <div class="form">
      <button class="btn btn-primary" id="download" type="button" <?= $hasData ? '' : 'disabled' ?>>
        <span class="btn-label">Télécharger le PDF</span>
      </button>
      <a class="btn" href="./cohort-subjects.php">Retour à la gestion</a>
    </div>
  </section>
</main>

<?php if ($hasData): ?>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script>
(() => {
  'use strict';
  const payload   = <?= json_encode($payload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>;
  const statusMsg = document.getElementById('status-msg');
  const dlBtn     = document.getElementById('download');
  let generated   = false;

  /* Palette : fond blanc, accents gris */
  const G = {
    paper:   [255, 255, 255],   // fond page BLANC
    ink:     [70,  70,  68],    // titres (gris foncé)
    text:    [90,  90,  88],    // texte courant
    soft:    [140, 140, 137],   // texte discret
    line:    [200, 200, 197],   // traits de grille
    band:    [255, 255, 255],   // bandeau section (blanc)
    bandInk: [50,  50,  48],    // texte sur bandeau
    headBg:  [0,   0,   0],     // en-tête tableau NOIR
    headInk: [255, 255, 255],   // texte en-tête BLANC
    zebra:   [245, 245, 243],   // rayure alternée
    emptyBg: [255, 255, 255],   // fond cellule vide
  };

  function generatePdf() {
    if (generated) return;
    if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
      statusMsg.textContent = "Impossible de charger la librairie PDF. Vérifiez votre connexion et rechargez la page.";
      return;
    }
    generated = true;
    dlBtn.disabled = true;
    dlBtn.classList.add('is-loading');

    const { jsPDF } = window.jspdf;

    /* --- Format paysage --- */
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

    const M  = 14;
    const PW = doc.internal.pageSize.getWidth();    // ~297 mm
    const PH = doc.internal.pageSize.getHeight();   // ~210 mm

    const paintBackground = () => {
      doc.setFillColor(...G.paper);
      doc.rect(0, 0, PW, PH, 'F');
    };

    /* --- Fonction pour dessiner l'en-tête (logo + titre + date) --- */
    const drawHeader = () => {
      let textX = M;
      if (payload.logo) {
        doc.addImage(payload.logo, 'PNG', M, 10, 22, 22);
        textX = M + 28; // Décalage du texte à droite du logo
      }

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(15);
      doc.setTextColor(...G.ink);
      doc.text('Récapitulatif — Matières par cohorte', textX, 18);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9);
      doc.setTextColor(...G.soft);
      doc.text(`Généré le ${payload.generated}`, textX, 24);
      
      return 38; // Retourne la coordonnée Y pour commencer le contenu en dessous
    };

    /* --- Initialisation de la première page --- */
    paintBackground();
    let cursorY = drawHeader();

    function ensureSpace(needed) {
      if (cursorY + needed > PH - 14) {
        doc.addPage();
        paintBackground();
        cursorY = 20; // Curseur en haut si un tableau est trop long et continue sur une nouvelle page
      }
    }

    /* --- Parcours sections → cohorts --- */
    payload.sections.forEach((section, index) => {
      /* Si ce n'est pas la première section, on force une nouvelle page avec l'en-tête */
      if (index > 0) {
        doc.addPage();
        paintBackground();
        cursorY = drawHeader();
      }

      ensureSpace(22);

      /* Bandeau section (blanc avec contour gris) */
      doc.setFillColor(...G.band);
      doc.setDrawColor(...G.line);
      doc.setLineWidth(0.2);
      doc.rect(M, cursorY, PW - M * 2, 9, 'FD');
      
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.setTextColor(...G.bandInk);
      const sectionTitle = section.code
        ? `${section.label}   (${section.code})`
        : section.label;
      doc.text(sectionTitle, M + 3, cursorY + 6);
      cursorY += 13;

      /* Chaque cohorte : label + tableau (même vide) */
      section.cohorts.forEach((c) => {
        ensureSpace(28);

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);
        doc.setTextColor(...G.text);
        doc.text(`Cohorte : ${c.cohort}`, M, cursorY);
        cursorY += 4;

        const body = c.subjects.length > 0
          ? c.subjects.map((s) => [s.subject, s.teacher, s.phone || '—'])
          : [[{
              content: 'Aucune matière enregistrée',
              colSpan: 3,
              styles: {
                halign: 'center',
                valign: 'middle',
                fontStyle: 'italic',
                fontSize: 9.5,
                textColor: G.soft,
                fillColor: G.emptyBg,
                minCellHeight: 16,
              },
            }]];

        doc.autoTable({
          startY: cursorY,
          head: [['Matière', 'Enseignant', 'Téléphone']],
          body,
          theme: 'grid',
          margin: { left: M, right: M },
          styles: {
            font: 'helvetica',
            fontSize: 9,
            cellPadding: 2.5,
            textColor: G.text,
            lineColor: G.line,
            lineWidth: 0.15,
            fillColor: G.paper,
          },
          headStyles: {
            fillColor: G.headBg,
            textColor: G.headInk,
            fontStyle: 'bold',
            fontSize: 9,
          },
          alternateRowStyles: { fillColor: G.zebra },
          columnStyles: {
            0: { cellWidth: 120 },
            1: { cellWidth: 90 },
            2: { cellWidth: 'auto' },
          },
          rowPageBreak: 'avoid',
        });

        cursorY = doc.lastAutoTable.finalY + 5;
      });

      cursorY += 3;
    });

    /* --- Pieds de page --- */
    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
      doc.setPage(i);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      doc.setTextColor(...G.soft);
      doc.text(`Page ${i} / ${pageCount}`, PW - M, PH - 6, { align: 'right' });
    }

    /* --- Enregistrement --- */
    const stamp = new Date().toISOString().slice(0, 10);
    doc.save(`recap-matieres-cohortes-${stamp}.pdf`);

    statusMsg.textContent = 'PDF téléchargé. Vous pouvez fermer cet onglet.';
    dlBtn.classList.remove('is-loading');
    dlBtn.disabled = false;
  }

  dlBtn.addEventListener('click', generatePdf);

  if (document.readyState === 'complete') {
    setTimeout(generatePdf, 200);
  } else {
    window.addEventListener('load', () => setTimeout(generatePdf, 200));
  }
})();
</script>
<?php endif; ?>

</body>
</html>