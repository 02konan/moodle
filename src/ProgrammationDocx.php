<?php
declare(strict_types=1);

/**
 * Fiche Word « Programmation des évaluations » d'un cours (.docx, sans dépendance externe).
 *
 *   En-tête : logo + « Programmation des évaluations » + titre du cours
 *   À remplir : Professeur, Classe, Section (lignes pointillées)
 *   Corps : sections (titre 1), sous-sections (titre 2) et, pour chaque QCM, « Date et heure : …… »
 *   Pied de page : titre du cours, Page X / Y
 */
final class ProgrammationDocx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const TEXT_WIDTH = 9638;   // largeur utile A4 (marges 2 cm), en twips

    public function __construct(private array $plan, private array $tree, private ?string $logoPath = null) {}

    /** Contenu binaire du fichier .docx. */
    public function build(): string
    {
        $logo = ($this->logoPath && is_file($this->logoPath)) ? (string)file_get_contents($this->logoPath) : null;
        $zip = new SimpleZip();
        $zip->add('[Content_Types].xml', $this->contentTypes());
        $zip->add('_rels/.rels', $this->rels([
            ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'word/document.xml'],
            ['rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
        ]));
        $zip->add('docProps/core.xml', $this->core());
        $zip->add('word/_rels/document.xml.rels', $this->rels([
            ['rId1', self::R . '/styles', 'styles.xml'],
            ['rId2', self::R . '/header', 'header1.xml'],
            ['rId3', self::R . '/footer', 'footer1.xml'],
        ]));
        $zip->add('word/styles.xml', $this->styles());
        $zip->add('word/document.xml', $this->document());
        $zip->add('word/header1.xml', $this->header($logo !== null));
        $zip->add('word/footer1.xml', $this->footer());
        if ($logo !== null) {
            $zip->add('word/_rels/header1.xml.rels', $this->rels([['rIdLogo', self::R . '/image', 'media/logo.png']]));
            $zip->add('word/media/logo.png', $logo);
        }
        return $zip->finish();
    }

    /* ---------- Contenu ---------- */

    private function document(): string
    {
        $b = $this->p($this->run('Programmation des évaluations'), 'Title');
        $b .= $this->p($this->run($this->plan['title'], ['b' => true, 'sz' => 20]), null, ['after' => 200]);
        $b .= $this->fillRow(['Professeur :', 'Classe :', 'Section :'], [4400, 7200, self::TEXT_WIDTH]);

        foreach ($this->tree as $section) {
            $b .= $this->p($this->run($section['title']), 'Heading1');
            $hasContent = false;
            foreach ($section['children'] as $child) {
                $hasContent = true;
                if ($child['type'] === 'quiz') {
                    $b .= $this->quiz($child, 0);
                } else {
                    $b .= $this->p($this->run($child['title']), 'Heading2');
                    foreach ($child['children'] as $q) $b .= $this->quiz($q, 360);
                    if (!$child['children']) $b .= $this->p($this->run('Aucun QCM.', ['i' => true, 'color' => '888888']), null, ['ind' => 360]);
                }
            }
            if (!$hasContent) $b .= $this->p($this->run('Aucun QCM.', ['i' => true, 'color' => '888888']));
        }
        if (!$this->tree) $b .= $this->p($this->run('Ce cours ne contient encore aucune section.', ['i' => true, 'color' => '888888']));

        $sect = '<w:sectPr><w:headerReference w:type="default" r:id="rId2"/><w:footerReference w:type="default" r:id="rId3"/>'
              . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1418" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr>';
        return $this->xml('<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>' . $b . $sect . '</w:body></w:document>');
    }

    private function quiz(array $q, int $indent): string
    {
        $title = $this->p($this->run($q['title'], ['b' => true]), null,
            ['ind' => $indent, 'before' => 120, 'after' => 0, 'keepNext' => true]);
        return $title . $this->fillLine('Date et heure :', $indent + 360, 120);
    }

    /** Plusieurs champs sur une ligne : « Professeur : ……  Classe : ……  Section : …… » (points jusqu'aux positions données, en twips). */
    private function fillRow(array $labels, array $stops): string
    {
        $tabs = '<w:tabs>' . implode('', array_map(fn($pos) => '<w:tab w:val="right" w:leader="dot" w:pos="' . $pos . '"/>', $stops)) . '</w:tabs>';
        $runs = '';
        foreach ($labels as $i => $label) $runs .= $this->run(($i ? '  ' : '') . $label . ' ') . '<w:r><w:tab/></w:r>';
        return $this->p($runs, null, ['after' => 160, 'tabs' => $tabs, 'line' => 360]);
    }

    /** « Libellé : ........ » jusqu'à la marge droite (tabulation avec points de suite). */
    private function fillLine(string $label, int $indent, int $after): string
    {
        $tabs = '<w:tabs><w:tab w:val="right" w:leader="dot" w:pos="' . self::TEXT_WIDTH . '"/></w:tabs>';
        return $this->p($this->run($label . ' ') . '<w:r><w:tab/></w:r>', null, ['ind' => $indent, 'after' => $after, 'tabs' => $tabs, 'line' => 360]);
    }

    private function header(bool $withLogo): string
    {
        $logo = $withLogo ? '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="648000" cy="648000"/>'
            . '<wp:docPr id="1" name="Logo"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="1" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="648000" cy="648000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>' : '';
        $none = '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/>';
        $text = $this->p($this->run('PROGRAMMATION DES ÉVALUATIONS', ['b' => true, 'sz' => 16]), null, ['jc' => 'right', 'after' => 0])
              . $this->p($this->run($this->plan['title'], ['sz' => 14, 'color' => '666666']), null, ['jc' => 'right', 'after' => 0]);
        $table = '<w:tbl><w:tblPr><w:tblW w:w="' . self::TEXT_WIDTH . '" w:type="dxa"/><w:tblBorders>' . $none . '</w:tblBorders>'
               . '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="1300"/><w:gridCol w:w="' . (self::TEXT_WIDTH - 1300) . '"/></w:tblGrid><w:tr>'
               . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $this->p($logo, null, ['after' => 0]) . '</w:tc>'
               . '<w:tc><w:tcPr><w:tcW w:w="' . (self::TEXT_WIDTH - 1300) . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $text . '</w:tc>'
               . '</w:tr></w:tbl>';
        $rule = '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="1" w:color="F98012"/></w:pBdr><w:spacing w:after="0"/></w:pPr></w:p>';
        return $this->xml('<w:hdr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . $table . $rule . '</w:hdr>');
    }

    private function footer(): string
    {
        $rpr = '<w:rPr><w:color w:val="888888"/><w:sz w:val="14"/></w:rPr>';
        $field = fn(string $code) => '<w:r>' . $rpr . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $rpr . '<w:instrText xml:space="preserve"> ' . $code . ' </w:instrText></w:r>'
            . '<w:r>' . $rpr . '<w:fldChar w:fldCharType="separate"/></w:r><w:r>' . $rpr . '<w:t>1</w:t></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="end"/></w:r>';
        $runs = $this->run($this->plan['title'] . ' — Page ', ['sz' => 14, 'color' => '888888']) . $field('PAGE')
              . $this->run(' / ', ['sz' => 14, 'color' => '888888']) . $field('NUMPAGES');
        return $this->xml('<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '">' . $this->p($runs, null, ['jc' => 'right', 'after' => 0]) . '</w:ftr>');
    }

    private function styles(): string
    {
        $font = '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>';
        $style = fn(string $id, string $name, string $ppr, string $rpr) =>
            '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr>' . $ppr . '</w:pPr><w:rPr>' . $rpr . '</w:rPr></w:style>';
        return $this->xml('<w:styles xmlns:w="' . self::W . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>' . $font . '<w:sz w:val="18"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $style('Title', 'Title', '<w:spacing w:before="0" w:after="60"/>', '<w:b/><w:caps/><w:color w:val="F98012"/><w:sz w:val="26"/>')
            . $style('Heading1', 'heading 1', '<w:keepNext/><w:spacing w:before="240" w:after="80"/><w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="DDDDDD"/></w:pBdr><w:outlineLvl w:val="0"/>', '<w:b/><w:color w:val="111111"/><w:sz w:val="22"/>')
            . $style('Heading2', 'heading 2', '<w:keepNext/><w:spacing w:before="140" w:after="60"/><w:ind w:left="360"/><w:outlineLvl w:val="1"/>', '<w:b/><w:color w:val="444444"/><w:sz w:val="20"/>')
            . '</w:styles>');
    }

    /* ---------- Briques XML ---------- */

    /** Paragraphe : $o = ind, before, after, line, jc, tabs, keepNext. */
    private function p(string $runs, ?string $style = null, array $o = []): string
    {
        $ppr = ($style ? '<w:pStyle w:val="' . $style . '"/>' : '') . (!empty($o['keepNext']) ? '<w:keepNext/>' : '') . ($o['tabs'] ?? '');
        if (isset($o['before']) || isset($o['after']) || isset($o['line'])) {
            $ppr .= '<w:spacing' . (isset($o['before']) ? ' w:before="' . $o['before'] . '"' : '') . (isset($o['after']) ? ' w:after="' . $o['after'] . '"' : '')
                  . (isset($o['line']) ? ' w:line="' . $o['line'] . '" w:lineRule="auto"' : '') . '/>';
        }
        if (!empty($o['ind'])) $ppr .= '<w:ind w:left="' . (int)$o['ind'] . '"/>';
        if (isset($o['jc'])) $ppr .= '<w:jc w:val="' . $o['jc'] . '"/>';
        return '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $runs . '</w:p>';
    }

    /** Texte : $o = b, i, sz (demi-points), color. */
    private function run(string $text, array $o = []): string
    {
        $rpr = (!empty($o['b']) ? '<w:b/>' : '') . (!empty($o['i']) ? '<w:i/>' : '')
             . (isset($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '') . (isset($o['sz']) ? '<w:sz w:val="' . (int)$o['sz'] . '"/>' : '');
        return '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r>';
    }

    private function contentTypes(): string
    {
        $o = fn(string $part, string $type) => '<Override PartName="' . $part . '" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.' . $type . '+xml"/>';
        return $this->xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
            . $o('/word/document.xml', 'document.main') . $o('/word/styles.xml', 'styles') . $o('/word/header1.xml', 'header') . $o('/word/footer1.xml', 'footer')
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
    }

    private function rels(array $list): string
    {
        $x = '';
        foreach ($list as [$id, $type, $target]) $x .= '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . $target . '"/>';
        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $x . '</Relationships>');
    }

    private function core(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->xml('<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::esc('Programmation des évaluations — ' . $this->plan['title']) . '</dc:title><dc:creator>Backoffice</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>');
    }

    private function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $body;
    }

    private static function esc(string $s): string
    {
        // Retire les caractères de contrôle interdits en XML
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

/** Archive ZIP minimale (entrées stockées sans compression), suffisante pour un .docx. */
final class SimpleZip
{
    private string $data = '';
    private string $dir = '';
    private int $count = 0;

    public function add(string $name, string $content): void
    {
        $t = getdate();
        $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $date = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $crc = crc32($content);
        $len = strlen($content);
        $common = pack('vvvvvVVVvv', 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0);   // 0x0800 : noms en UTF-8
        $offset = strlen($this->data);
        $this->data .= "PK\x03\x04" . $common . $name . $content;
        $this->dir .= "PK\x01\x02" . pack('v', 20) . $common . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        $this->count++;
    }

    public function finish(): string
    {
        return $this->data . $this->dir
            . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $this->count, $this->count, strlen($this->dir), strlen($this->data), 0);
    }
}
