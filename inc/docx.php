<?php
declare(strict_types=1);

/**
 * Shkrues minimal DOCX (Word) — vetëm PHP + ZipArchive.
 * Mbështet: titull, tituj, paragrafë, lista, tabela, figura PNG, ndërprerje faqeje, numra faqesh.
 */
final class DocxWriter {
    private array $body = [];
    private array $images = [];
    private string $footer = '';
    private int $docPr = 1;

    private static function x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Run teksti; $o: b, i, size (pt), color (hex pa #) */
    private function run(string $text, array $o = []): string {
        $rpr = '';
        if (!empty($o['b'])) $rpr .= '<w:b/>';
        if (!empty($o['i'])) $rpr .= '<w:i/>';
        if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        if (!empty($o['size'])) $rpr .= '<w:sz w:val="' . (int)($o['size'] * 2) . '"/><w:szCs w:val="' . (int)($o['size'] * 2) . '"/>';
        $parts = explode("\n", $text);
        $out = '';
        foreach ($parts as $k => $p) {
            if ($k > 0) $out .= '<w:r>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '<w:br/></w:r>';
            $out .= '<w:r>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '<w:t xml:space="preserve">' . self::x($p) . '</w:t></w:r>';
        }
        return $out;
    }
    /** Tekst me **të theksuara** */
    private function rich(string $text, array $o = []): string {
        $out = '';
        foreach (preg_split('/(\*\*[^*]+\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $seg) {
            $out .= str_starts_with($seg, '**') ? $this->run(trim($seg, '*'), $o + ['b' => true]) : $this->run($seg, $o);
        }
        return $out;
    }
    private function para(string $runs, string $style = '', array $o = []): string {
        $ppr = $style ? '<w:pStyle w:val="' . $style . '"/>' : '';
        if (!empty($o['align'])) $ppr .= '<w:jc w:val="' . $o['align'] . '"/>';
        if (isset($o['after'])) $ppr .= '<w:spacing w:after="' . (int)$o['after'] . '"/>';
        if (!empty($o['keepNext'])) $ppr .= '<w:keepNext/>';
        return '<w:p>' . ($ppr ? "<w:pPr>$ppr</w:pPr>" : '') . $runs . '</w:p>';
    }

    public function title(string $t): self { $this->body[] = $this->para($this->run($t), 'Title'); return $this; }
    public function subtitle(string $t): self { $this->body[] = $this->para($this->run($t), 'Subtitle'); return $this; }
    public function h1(string $t, bool $newPage = false): self {
        $ppr = '<w:pPr><w:pStyle w:val="Heading1"/>' . ($newPage ? '<w:pageBreakBefore/>' : '') . '</w:pPr>';
        $this->body[] = '<w:p>' . $ppr . $this->run($t) . '</w:p>';
        return $this;
    }
    public function h2(string $t): self { $this->body[] = $this->para($this->run($t), 'Heading2'); return $this; }
    public function p(string $t, array $o = []): self { $this->body[] = $this->para($this->rich($t, $o), $o['style'] ?? '', $o); return $this; }
    public function note(string $t): self { $this->body[] = $this->para($this->rich($t, ['i' => true, 'color' => '5D6782', 'size' => 9]), ''); return $this; }
    public function bullets(array $items): self {
        foreach ($items as $it) $this->body[] = '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>' . $this->rich((string)$it) . '</w:p>';
        return $this;
    }
    public function pageBreak(): self { $this->body[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'; return $this; }
    public function footer(string $t): self { $this->footer = $t; return $this; }

    /** Tabelë; $cols = [[label, align('left'|'right'), widthPct], ...] */
    public function table(array $cols, array $rows, array $o = []): self {
        $total = 9638; // gjerësia e faqes A4 me margjina 2cm (twips)
        $grid = '';
        foreach ($cols as $c) $grid .= '<w:gridCol w:w="' . (int)($total * ($c[2] ?? (100 / count($cols))) / 100) . '"/>';
        $keep = count($rows) <= 25;
        $cell = function (string $text, array $c, bool $head, bool $bold, ?string $fill, bool $kn = false) use ($total): string {
            $w = (int)($total * ($c[2] ?? 10) / 100);
            $tcpr = '<w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '<w:vAlign w:val="center"/>';
            $jc = ($c[1] ?? 'left') === 'right' && !$head ? 'right' : 'left';
            $runs = $this->run($text, ['b' => $head || $bold, 'size' => $head ? 8.5 : 9, 'color' => $head ? 'FFFFFF' : '1C2333']);
            return "<w:tc><w:tcPr>$tcpr</w:tcPr><w:p><w:pPr>" . ($kn ? '<w:keepNext/>' : '') . "<w:spacing w:before=\"40\" w:after=\"40\"/><w:jc w:val=\"$jc\"/></w:pPr>$runs</w:p></w:tc>";
        };
        $x = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="CFD6E4"/><w:bottom w:val="single" w:sz="4" w:color="CFD6E4"/><w:insideH w:val="single" w:sz="4" w:color="E2E7F0"/>'
            . '<w:left w:val="nil"/><w:right w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . "<w:tblGrid>$grid</w:tblGrid>";
        $x .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($cols as $c) $x .= $cell((string)$c[0], $c, true, true, '2F5BEA', $keep);
        $x .= '</w:tr>';
        foreach ($rows as $ri => $r) {
            $isTot = !empty($o['total_last']) && $ri === count($rows) - 1;
            $x .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach (array_values($r) as $ci => $v) $x .= $cell((string)$v, $cols[$ci] ?? [], false, $isTot, $isTot ? 'EEF2FB' : ($ri % 2 ? 'F8FAFC' : null), $keep && $ri < count($rows) - 1);
            $x .= '</w:tr>';
        }
        $this->body[] = $x . '</w:tbl>';
        $this->body[] = $this->para('', '', ['after' => 120]);
        return $this;
    }

    /** "Kartela" me tregues kryesorë: $items = [[label, value], ...] në 3 kolona */
    public function kpis(array $items, int $perRow = 3): self {
        $w = (int)(9638 / $perRow);
        $x = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders><w:insideH w:val="single" w:sz="12" w:color="FFFFFF"/><w:insideV w:val="single" w:sz="12" w:color="FFFFFF"/></w:tblBorders></w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $w . '"/>', $perRow) . '</w:tblGrid>';
        foreach (array_chunk($items, $perRow) as $chunk) {
            $x .= '<w:tr>';
            for ($i = 0; $i < $perRow; $i++) {
                $it = $chunk[$i] ?? ['', ''];
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . ($it[0] === '' ? 'FFFFFF' : 'EEF2FB') . '"/></w:tcPr>'
                    . '<w:p><w:pPr><w:spacing w:before="80" w:after="0"/></w:pPr>' . $this->run((string)$it[1], ['b' => true, 'size' => 16, 'color' => '2F5BEA']) . '</w:p>'
                    . '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr>' . $this->run((string)$it[0], ['size' => 8.5, 'color' => '5D6782']) . '</w:p></w:tc>';
            }
            $x .= '</w:tr>';
        }
        $this->body[] = $x . '</w:tbl>';
        $this->body[] = $this->para('', '', ['after' => 120]);
        return $this;
    }

    /** Figurë PNG me gjerësi në cm */
    public function image(?string $png, float $widthCm = 16.5, string $caption = ''): self {
        if (!$png) return $this;
        $info = getimagesizefromstring($png);
        if (!$info) return $this;
        $n = count($this->images) + 1;
        $this->images[$n] = $png;
        $cx = (int)($widthCm * 360000);
        $cy = (int)($cx * $info[1] / $info[0]);
        $id = $this->docPr++;
        $this->body[] = '<w:p><w:pPr><w:jc w:val="center"/><w:keepNext/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="Chart ' . $id . '"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="chart' . $n . '.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rImg' . $n . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        if ($caption) $this->body[] = $this->para($this->run($caption, ['i' => true, 'size' => 8.5, 'color' => '5D6782']), '', ['align' => 'center', 'after' => 200]);
        return $this;
    }

    public function save(string $path): void {
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $z->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Teacher Training and ADT Studio Adoption Report</dc:title><dc:creator>' . self::x((string)cfg('app_name')) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rNum" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rFoot" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
        foreach ($this->images as $n => $png) {
            $z->addFromString("word/media/chart$n.png", $png);
            $rels .= '<Relationship Id="rImg' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/chart' . $n . '.png"/>';
        }
        $z->addFromString('word/_rels/document.xml.rels', $rels . '</Relationships>');
        $z->addFromString('word/styles.xml', self::styles());
        $z->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="360" w:hanging="260"/></w:pPr><w:rPr><w:color w:val="2F5BEA"/></w:rPr></w:lvl></w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>');
        $z->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:color="CFD6E4" w:space="4"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="9638"/></w:tabs></w:pPr>'
            . '<w:r><w:rPr><w:sz w:val="16"/><w:color w:val="5D6782"/></w:rPr><w:t xml:space="preserve">' . self::x($this->footer) . '</w:t></w:r><w:r><w:rPr><w:sz w:val="16"/><w:color w:val="5D6782"/></w:rPr><w:tab/><w:t xml:space="preserve">Page </w:t></w:r>'
            . '<w:r><w:rPr><w:sz w:val="16"/><w:color w:val="5D6782"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:sz w:val="16"/><w:color w:val="5D6782"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:sz w:val="16"/><w:color w:val="5D6782"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>');
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
            . implode('', $this->body)
            . '<w:sectPr><w:footerReference w:type="default" r:id="rFoot"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $z->addFromString('word/document.xml', $doc);
        $z->close();
    }

    public function send(string $filename): never {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        while (ob_get_level()) ob_end_flush();   // pa buffer: skedari dërgohet drejtpërdrejt
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private static function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="21"/><w:szCs w:val="21"/><w:color w:val="1C2333"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="1800" w:after="200"/></w:pPr><w:rPr><w:b/><w:color w:val="1D3A9E"/><w:sz w:val="56"/><w:szCs w:val="56"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="160"/></w:pPr><w:rPr><w:color w:val="5D6782"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:pBdr><w:bottom w:val="single" w:sz="8" w:color="2F5BEA" w:space="2"/></w:pBdr><w:spacing w:before="360" w:after="160"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="1D3A9E"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="100"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="2F5BEA"/><w:sz w:val="25"/><w:szCs w:val="25"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="60"/><w:ind w:left="360"/></w:pPr></w:style>'
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '</w:styles>';
    }
}
