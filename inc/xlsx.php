<?php
declare(strict_types=1);

/**
 * Shkrues minimal XLSX (pa librari të jashtme, vetëm ZipArchive).
 * $sheets = [ ['name' => 'Përmbledhje', 'rows' => [[...], ...], 'bold_rows' => [0, 5], 'widths' => [30, 12, ...]], ... ]
 * Vlerat numerike shkruhen si numra; tekstet si "inline strings".
 */
function xlsx_send(string $filename, array $sheets): never {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $n = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $used = [];
    foreach ($sheets as $i => $sh) {
        $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $sh['name']), 0, 31) ?: 'Fleta' . ($i + 1);
        while (isset($used[mb_strtolower($name)])) $name = mb_substr($name, 0, 28) . '_' . ($i + 1);
        $used[mb_strtolower($name)] = 1;
        $wb .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsx_sheet_xml($sh));
    }
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0"/></numFmts>'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EEFF"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    while (ob_get_level()) ob_end_flush();   // pa buffer: skedari dërgohet drejtpërdrejt
    readfile($tmp);
    unlink($tmp);
    exit;
}

function xlsx_col(int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function xlsx_sheet_xml(array $sh): string {
    $bold = array_flip($sh['bold_rows'] ?? []);
    $title = array_flip($sh['title_rows'] ?? []);
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    if (!empty($sh['widths'])) {
        $x .= '<cols>';
        foreach ($sh['widths'] as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    foreach ($sh['rows'] as $r => $row) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = xlsx_col($c) . ($r + 1);
            $style = isset($title[$r]) ? 2 : (isset($bold[$r]) ? 1 : 0);
            if ($v === null || $v === '') { if ($style) $x .= '<c r="' . $ref . '" s="' . $style . '"/>'; continue; }
            if (is_int($v) || is_float($v)) {
                $s = $style ?: (is_float($v) ? 3 : 0);
                $x .= '<c r="' . $ref . '"' . ($s ? ' s="' . $s . '"' : '') . '><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . htmlspecialchars((string)$v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}
