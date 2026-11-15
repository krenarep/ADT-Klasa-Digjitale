<?php
declare(strict_types=1);

/**
 * Lexon listën e nxënësve nga CSV / XLSX / TXT.
 * Kolonat e njohura (titulli i kolonës, pa dallim shkronjash):
 *   Emri i plotë | Emri + Mbiemri | Përdoruesi | Fjalëkalimi | Kodi i klasës
 * Nëse nuk ka rresht titujsh, kolona e parë merret si "Emri i plotë".
 * Kthen listë rreshtash: ['full_name','username','password','class_code']
 */
function parse_student_list(string $path, string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $rows = match ($ext) {
        'xlsx' => read_xlsx_rows($path),
        'csv', 'txt' => read_csv_rows($path),
        default => throw new RuntimeException('Formati nuk mbështetet. Përdorni .xlsx, .csv ose .txt'),
    };
    return normalize_student_rows($rows);
}

function parse_student_paste(string $text): array {
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $rows = [];
    foreach ($lines as $l) {
        if (trim($l) === '') continue;
        $rows[] = str_contains($l, "\t") ? explode("\t", $l) : (substr_count($l, ';') ? explode(';', $l) : [$l]);
    }
    return normalize_student_rows($rows);
}

function to_utf8(string $s): string {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    return $s;
}

function read_csv_rows(string $path): array {
    $raw = to_utf8((string)file_get_contents($path));
    $first = strtok($raw, "\n") ?: '';
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (str_contains($first, "\t") ? "\t" : ',');
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($r === [null]) continue;
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

function read_xlsx_rows(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Skedari Excel nuk mund të hapet.');
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($sx->si as $si) {
            if (isset($si->t)) $shared[] = (string)$si->t;
            else { $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
        }
    }
    // fleta e parë
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $w = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $rid = (string)($w->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        $r = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($r->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) { $sheetPath = 'xl/' . ltrim(str_replace('/xl/', '', (string)$rel['Target']), '/'); }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) throw new RuntimeException('Fleta e parë e Excel-it nuk u gjet.');
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) $col = $col * 26 + (ord($ch) - 64);
            $t = (string)$c['t'];
            $v = $t === 's' ? ($shared[(int)$c->v] ?? '') : ($t === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
            $cells[$col - 1] = $v;
        }
        if (!$cells) continue;
        $line = [];
        for ($i = 0, $m = max(array_keys($cells)); $i <= $m; $i++) $line[] = $cells[$i] ?? '';
        $rows[] = $line;
    }
    return $rows;
}

function normalize_student_rows(array $rows): array {
    if (!$rows) return [];
    $norm = fn($s) => translit(trim((string)$s));
    $map = [];
    $head = array_map($norm, $rows[0]);
    $aliases = [
        'full_name' => ['emriiplote', 'emriplote', 'emriimbiemri', 'emridhembiemri', 'nxenesi', 'fullname', 'name', 'emri i plote'],
        'first' => ['emri', 'firstname'],
        'last' => ['mbiemri', 'lastname', 'surname'],
        'username' => ['perdoruesi', 'username', 'user'],
        'password' => ['fjalekalimi', 'password', 'pass'],
        'class_code' => ['kodiiklases', 'kodiklases', 'kodi', 'classcode', 'klasa', 'class'],
        'gender' => ['gjinia', 'gjiniavd', 'gender', 'sex', 'vd'],
        'special_needs' => ['nevojatevecanta', 'nevojavecanta', 'nevojat', 'nv', 'specialneeds', 'sen'],
        'special_needs_type' => ['llojiinevojes', 'llojinevojes', 'lloji', 'needtype'],
        'mother_tongue' => ['gjuhaamtare', 'gjuha', 'mothertongue', 'language'],
        'consent' => ['pelqimi', 'pelqimiiprindit', 'pelqimiprindit', 'consent'],
        'consent_by' => ['prindi', 'emriiprindit', 'kujdestari', 'parent'],
    ];
    foreach ($head as $i => $h) {
        foreach ($aliases as $field => $names) {
            if (in_array($h, $names, true) && !isset($map[$field])) $map[$field] = $i;
        }
    }
    $hasHeader = isset($map['full_name']) || isset($map['first']);
    if (!$hasHeader) $map = ['full_name' => 0];
    $out = [];
    foreach (array_slice($rows, $hasHeader ? 1 : 0) as $r) {
        $get = fn($f) => isset($map[$f]) ? trim(to_utf8((string)($r[$map[$f]] ?? ''))) : '';
        $name = $get('full_name');
        if ($name === '' && ($get('first') !== '' || $get('last') !== '')) $name = trim($get('first') . ' ' . $get('last'));
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) continue;
        [$sn, $snType] = parse_special_needs($get('special_needs'));
        if ($get('special_needs_type') !== '') { $sn = $sn ?? 1; if ($sn) $snType = parse_special_needs($get('special_needs_type'))[1] ?? $snType; }
        $mt = $get('mother_tongue');
        $mtMatch = null;
        foreach (mother_tongues() as $m) if ($mt !== '' && translit($m) === translit($mt)) $mtMatch = $m;
        $out[] = ['full_name' => $name, 'username' => $get('username'), 'password' => $get('password'), 'class_code' => mb_strtoupper($get('class_code')),
            'demo' => ['gender' => parse_gender($get('gender')), 'special_needs' => $sn, 'special_needs_type' => $sn ? $snType : null, 'mother_tongue' => $mtMatch ?? ($mt !== '' ? 'Tjetër' : null),
                'consent' => in_array(translit($get('consent')), ['po', 'yes', 'x', '1', 'p'], true), 'consent_by' => $get('consent_by') ?: ($get('consent') !== '' ? 'sipas listës së importit' : '')]];
    }
    return $out;
}

/**
 * Importon rreshtat. $allowedClasses = [class_id => class row] ku lejohet regjistrimi.
 * Kthen ['created' => [...], 'errors' => [...]]
 */
function import_students(array $rows, ?array $defaultClass, array $allowedClasses, int $createdBy, bool $skipDup = true): array {
    $byCode = [];
    $nameCount = [];
    foreach ($allowedClasses as $c) $nameCount[mb_strtoupper(trim($c['name']))] = ($nameCount[mb_strtoupper(trim($c['name']))] ?? 0) + 1;
    foreach ($allowedClasses as $c) {
        $byCode[strtoupper($c['class_code'])] = $c;
        $n = mb_strtoupper(trim($c['name']));
        if ($nameCount[$n] === 1 && !isset($byCode[$n])) $byCode[$n] = $c;   // lejohet edhe emri i klasës nëse është unik
    }
    $created = [];
    $errors = [];
    foreach (array_slice($rows, 0, 1000) as $i => $r) {
        $line = $i + 1;
        $class = $r['class_code'] !== '' ? ($byCode[$r['class_code']] ?? null) : $defaultClass;
        if (!$class) {
            $errors[] = "Rreshti $line ({$r['full_name']}): " . ($r['class_code'] !== '' ? "kodi i klasës {$r['class_code']} nuk u gjet ose nuk keni qasje." : 'nuk është zgjedhur klasa.');
            continue;
        }
        if ($skipDup && qv('SELECT 1 FROM users u JOIN class_students cs ON cs.student_id=u.id WHERE cs.class_id=? AND (u.full_name=? OR u.name_hash=?)', [$class['id'], $r['full_name'], anon_name_hash((string)$r['full_name'], (int)$class['id'])])) {
            $errors[] = "Rreshti $line ({$r['full_name']}): ekziston tashmë në klasën {$class['name']} — u anashkalua.";
            continue;
        }
        $demo = $r['demo'] ?? [];
        if ((int)($demo['special_needs'] ?? 0) === 1 && empty($demo['consent'])) {
            $errors[] = "Rreshti $line ({$r['full_name']}): u krijua, por të dhënat e nevojave të veçanta NUK u ruajtën — mungon kolona \"Pëlqimi i prindit\" = Po.";
            $demo['special_needs'] = null; $demo['special_needs_type'] = null;
        }
        try {
            $res = create_student($r['full_name'], (int)$class['school_id'], (int)$class['grade'], (int)$class['id'], $createdBy, $r['username'] ?: null, $r['password'] ?: null, null, $demo);
            $created[] = $res + ['full_name' => $r['full_name'], 'class' => $class['name'], 'class_code' => $class['class_code']];
        } catch (Throwable $ex) {
            $errors[] = "Rreshti $line ({$r['full_name']}): " . $ex->getMessage();
        }
    }
    return ['created' => $created, 'errors' => $errors];
}

/** Trajton formularin e importit (skedar ose tekst i ngjitur) dhe ruan rezultatin në sesion */
function handle_student_import(?array $defaultClass, array $allowedClasses, int $createdBy): void {
    try {
        $f = $_FILES['list'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('Skedari është shumë i madh (maks. 5 MB).');
            $rows = parse_student_list($f['tmp_name'], $f['name']);
        } elseif (trim((string)post('names')) !== '') {
            $rows = parse_student_paste((string)post('names'));
        } else {
            throw new RuntimeException('Zgjidhni një skedar (.xlsx/.csv) ose ngjitni emrat.');
        }
        if (!$rows) throw new RuntimeException('Nuk u gjet asnjë emër në listë.');
        $res = import_students($rows, $defaultClass, $allowedClasses, $createdBy, (bool)post('skip_dup'));
        $_SESSION['import_result'] = $res;
        audit('student.import', count($res['created']) . ' nxënës', count($res['errors']) . ' gabime');
        flash($res['created'] ? 'ok' : 'err', count($res['created']) . ' nxënës u krijuan' . ($res['errors'] ? ', ' . count($res['errors']) . ' rreshta me gabime.' : '.'));
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
}

/** Shfaq rezultatin e importit të fundit (një herë) */
function import_result_html(string $exportUrl): string {
    $res = $_SESSION['import_result'] ?? null;
    if (!$res) return '';
    $_SESSION['import_export'] = $res['created'];
    unset($_SESSION['import_result']);
    ob_start(); ?>
    <section class="card" style="border-color:#bfe5cf">
      <div class="row-between"><h2>📥 Rezultati i importit</h2>
        <?php if ($res['created']): ?><a class="btn btn-primary btn-sm" href="<?= e($exportUrl) ?>">⬇️ Shkarko kredencialet (CSV)</a><?php endif; ?></div>
      <?php if ($res['errors']): ?><div class="flash flash-err"><?= implode('<br>', array_map('e', array_slice($res['errors'], 0, 30))) ?></div><?php endif; ?>
      <?php if ($res['created']): ?>
      <div class="table-wrap" style="max-height:360px;overflow:auto"><table class="t">
        <thead><tr><th>Nxënësi</th><th>Kodi në sistem</th><th>Klasa</th><th>Përdoruesi</th><th>Fjalëkalimi</th><?php if (picture_login_enabled()): ?><th>Fotot</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($res['created'] as $c): ?>
          <tr><td><?= e($c['full_name']) ?></td><td><code><?= e($c['code'] ?? '') ?></code></td><td><?= e($c['class']) ?> <code><?= e($c['class_code']) ?></code></td><td><code><?= e($c['username']) ?></code></td><td><code><?= e($c['password']) ?></code></td><td><?= picture_seq_html($c['seq']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <p class="small muted">Ruajeni ose printojeni këtë listë tani. Kartelat e hyrjes mund t'i printoni edhe më vonë nga faqja e klasës.</p>
      <?php if (anon_enabled()): ?><div class="flash flash-info">🔒 <strong>Nxënësit u ruajtën vetëm me kod.</strong> Emrat e vërtetë NUK ruhen në sistem — shkarkoni tani listën-çelës (CSV) dhe ruajeni vetë. Pas largimit nga kjo faqe, emrat nuk mund të shihen më.</div><?php endif; ?>
      <?php endif; ?>
    </section>
    <?php return (string)ob_get_clean();
}

function export_last_import_csv(): never {
    $rows = $_SESSION['import_export'] ?? [];
    audit('export', 'Kredencialet e importit (CSV)', count($rows) . ' nxënës');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kredencialet_nxenesit_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Emri i plotë', 'Kodi i nxënësit (në sistem)', 'Klasa', 'Kodi i klasës', 'Përdoruesi', 'Fjalëkalimi', 'Fotot (hyrja me foto)'], ';', '"', '\\');
    $set = picture_set();
    foreach ($rows as $r) {
        $pics = implode(' ', array_map(fn($i) => $set[(int)$i][1] ?? '', explode('-', (string)$r['seq'])));
        fputcsv($out, [$r['full_name'], $r['code'] ?? '', $r['class'], $r['class_code'], $r['username'], $r['password'], $pics], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

function import_dialog_html(string $classSelectHtml, bool $showCodeHint): string {
    ob_start(); ?>
    <dialog id="dlgImport"><div class="dlg-body">
      <div class="dlg-head"><h2>📥 Ngarko listë nxënësish</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
      <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="import">
        <?= $classSelectHtml ?>
        <label>Skedari (.xlsx, .csv, .txt)<input type="file" name="list" accept=".xlsx,.csv,.txt,text/csv"></label>
        <p class="help" style="margin:0">Kolonat: <strong>Emri i plotë</strong> (ose Emri + Mbiemri), opsionale: Gjinia (V/D), Nevoja të veçanta (Po/Jo ose lloji) + Pëlqimi i prindit (Po) + Prindi, Gjuha amtare, Përdoruesi, Fjalëkalimi<?= $showCodeHint ? ', <strong>Kodi i klasës</strong> (për të shpërndarë nxënësit në klasa të ndryshme)' : '' ?>.
          <a href="<?= e(url('template.php')) ?>">⬇️ Shkarko shabllonin</a></p>
        <label>…ose ngjitni emrat (një për rresht, edhe nga Excel)<textarea name="names" rows="6" placeholder="Emri Mbiemri&#10;Emri Mbiemri"></textarea></label>
        <label class="checkline"><input type="checkbox" name="skip_dup" value="1" checked> Anashkalo nxënësit që ekzistojnë tashmë në klasë me të njëjtin emër</label>
        <p class="help" style="margin:0">Përdoruesit, fjalëkalimet dhe fotot e hyrjes gjenerohen automatikisht kur mungojnë. Maks. 1000 rreshta.</p>
        <button class="btn btn-primary" onclick="this.textContent='⏳ Duke importuar…'">Importo</button>
      </form>
    </div></dialog>
    <?php return (string)ob_get_clean();
}
