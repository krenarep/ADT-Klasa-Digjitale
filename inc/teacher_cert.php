<?php
declare(strict_types=1);

/**
 * Certifikatat e pjesëmarrjes për mësuesit (trajnime / punëtori).
 * Titulli, tema, datat, vendi dhe orët merren nga sesioni i trajnimit (admin/trainings.php).
 * Logot (p.sh. e partnerit, e ministrisë, e organizatës) i ngarkon administratori — nuk përfshihen në kod.
 */

const TCERT_STATUSES = ['attended', 'completed'];

function tcert_defaults(): array {
    return [
        'tcert_heading' => 'CERTIFIKATË',
        'tcert_subheading' => 'PJESËMARRJEJE',
        'tcert_heading_en' => 'Certificate of Participation',
        'tcert_awarded' => 'Kjo certifikatë i jepet',
        'tcert_for' => 'për pjesëmarrjen me sukses në',
        'tcert_program' => 'në kuadër të projektit pilot «Libra Digjitalë të Qasshëm (ADT)»',
        'tcert_program_en' => 'Accessible Digital Textbooks (ADT) pilot — ADT Studio',
        'tcert_sign1_name' => '',
        'tcert_sign1_role' => 'Trajnuesi / Trainer',
        'tcert_sign2_name' => '',
        'tcert_sign2_role' => 'Koordinatori i projektit / Project coordinator',
        'tcert_color' => '#00AEEF',
        'tcert_bilingual' => '1',
        'tcert_merge_all' => '1',
        'tcert_combined_title' => 'Trajnimi për Libra Digjitalë të Qasshëm (ADT)',
    ];
}
function tcert_setting(string $k): string {
    $v = (string)setting($k);
    return $v !== '' ? $v : (tcert_defaults()[$k] ?? '');
}

function tcert_logo_dir(): string {
    $d = APP_ROOT . '/backups/cert_logos';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
/** @return string[] data: URI e logove (1–3), sipas renditjes */
function tcert_logos(): array {
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        foreach (['png', 'jpg', 'jpeg', 'svg', 'webp'] as $ext) {
            $f = tcert_logo_dir() . "/logo$i.$ext";
            if (is_file($f)) {
                $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'][$ext];
                $out[$i] = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f));
                break;
            }
        }
    }
    return $out;
}
function tcert_save_logo(int $i, array $f): void {
    if ($i < 1 || $i > 3) return;
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Logo nuk u ngarkua.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'], true)) throw new RuntimeException('Logo: lejohen PNG, JPG, SVG, WEBP.');
    if ($f['size'] > 1048576) throw new RuntimeException('Logo duhet të jetë më e vogël se 1 MB.');
    tcert_delete_logo($i);
    move_uploaded_file($f['tmp_name'], tcert_logo_dir() . "/logo$i.$ext");
}
function tcert_delete_logo(int $i): void {
    foreach (glob(tcert_logo_dir() . "/logo$i.*") ?: [] as $old) @unlink($old);
}

/** 12–13 Nëntor 2026 · 28 Tetor – 2 Nëntor 2026 · 12 Nëntor 2026 */
function tcert_dates(?string $start, ?string $end, bool $en = false): string {
    $m = $en ? ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
             : ['', 'Janar', 'Shkurt', 'Mars', 'Prill', 'Maj', 'Qershor', 'Korrik', 'Gusht', 'Shtator', 'Tetor', 'Nëntor', 'Dhjetor'];
    $a = strtotime((string)$start); if (!$a) return '';
    $b = $end ? strtotime($end) : $a; if (!$b || $b < $a) $b = $a;
    [$da, $ma, $ya] = [(int)date('j', $a), (int)date('n', $a), date('Y', $a)];
    [$db, $mb, $yb] = [(int)date('j', $b), (int)date('n', $b), date('Y', $b)];
    if ($a === $b) return "{$da} {$m[$ma]} {$ya}";
    if ($ma === $mb && $ya === $yb) return "{$da}–{$db} {$m[$ma]} {$ya}";
    if ($ya === $yb) return "{$da} {$m[$ma]} – {$db} {$m[$mb]} {$ya}";
    return "{$da} {$m[$ma]} {$ya} – {$db} {$m[$mb]} {$yb}";
}

function tcert_code(int $trainingId, int $teacherId, string $start): string {
    $h = strtoupper(substr(hash_hmac('sha256', "tcert|$trainingId|$teacherId", app_secret()), 0, 6));
    return 'ADT-' . date('Y', strtotime($start) ?: time()) . '-' . sprintf('%03d', $trainingId) . '-' . sprintf('%03d', $teacherId) . '-' . $h;
}

/**
 * Një certifikatë për një trajnim, edhe kur ditët janë regjistruar si sesione të veçanta me të njëjtin titull
 * (p.sh. «Dita 1» dhe «Dita 2»): datat bashkohen (12–13 Nëntor) dhe orët mblidhen.
 */
function tcert_merge_all(): bool { return tcert_setting('tcert_merge_all') === '1'; }

function tcert_merged_training(array $t, int $teacherId): array {
    $st = "('" . implode("','", TCERT_STATUSES) . "')";
    if (tcert_merge_all()) {
        // NJË certifikatë për mësuesin: të gjitha sesionet ku ka marrë pjesë (p.sh. Dita 1 + Dita 2)
        $all = qa("SELECT t.* FROM trainings t JOIN training_attendance a ON a.training_id=t.id AND a.teacher_id=? AND a.status IN $st ORDER BY t.start_date, t.id", [$teacherId]);
        if (count($all) > 1) {
            $titles = array_values(array_unique(array_map(fn($x) => trim((string)$x['title']), $all)));
            $topics = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x['topic']), $all))));
            $locs = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x['location']), $all))));
            $munis = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x['municipality']), $all))));
            $t = $all[0];
            $t['title'] = count($titles) === 1 ? $titles[0] : tcert_setting('tcert_combined_title');
            $t['topic'] = implode('; ', $topics);
            $t['location'] = implode(' / ', $locs);
            $t['municipality'] = implode(' / ', $munis);
            $t['start_date'] = min(array_column($all, 'start_date'));
            $t['end_date'] = max(array_map(fn($x) => $x['end_date'] ?: $x['start_date'], $all));
            $t['hours'] = array_sum(array_map(fn($x) => (float)$x['hours'], $all));
            foreach ($all as $x) if (!empty($x['trainer'])) { $t['trainer'] = $x['trainer']; break; }
        }
        return $t;
    }
    $r = q1("SELECT MIN(t.start_date) s, MAX(COALESCE(t.end_date, t.start_date)) e, SUM(t.hours) h, COUNT(*) n FROM trainings t
             JOIN training_attendance a ON a.training_id=t.id AND a.teacher_id=? AND a.status IN $st
             WHERE LOWER(TRIM(t.title)) = LOWER(TRIM(?))", [$teacherId, $t['title']]);
    if ($r && (int)$r['n'] > 1) { $t['start_date'] = $r['s']; $t['end_date'] = $r['e']; $t['hours'] = $r['h']; }
    return $t;
}

/** Lista e certifikatave të një mësuesi: një e vetme (bashkimi i të gjitha sesioneve) ose një për çdo titull trajnimi */
function tcert_teacher_list(int $teacherId): array {
    $st = "('" . implode("','", TCERT_STATUSES) . "')";
    if (tcert_merge_all()) {
        $r = q1("SELECT MIN(t.id) id, MIN(t.start_date) start_date, COUNT(*) n FROM training_attendance a JOIN trainings t ON t.id=a.training_id WHERE a.teacher_id=? AND a.status IN $st", [$teacherId]);
        if (!$r || !(int)$r['n']) return [];
        $t = tcert_merged_training((array)q1('SELECT * FROM trainings WHERE id=?', [$r['id']]), $teacherId);
        return [['id' => $r['id'], 'title' => tcert_dates($t['start_date'], $t['end_date']), 'start_date' => $r['start_date']]];
    }
    return qa("SELECT MIN(t.id) id, MIN(t.title) title, MIN(t.start_date) start_date FROM training_attendance a JOIN trainings t ON t.id=a.training_id
               WHERE a.teacher_id=? AND a.status IN $st GROUP BY LOWER(TRIM(t.title)) ORDER BY start_date", [$teacherId]);
}

/** Çelësi ku ruhen ndryshimet e tekstit: i përbashkët ('all') kur certifikata bashkon sesionet */
function tcert_override_key(int $trainingId): string { return tcert_merge_all() ? 'tcert_tall_json' : 'tcert_t' . $trainingId . '_json'; }

/** Nënshkruesit për një trajnim: vlera e këtij trajnimi → vlera e përgjithshme → trajnuesi i sesionit */
function tcert_signers(array $t): array {
    $o = [];
    foreach (['sign1_name', 'sign1_role', 'sign2_name', 'sign2_role'] as $k) {
        $v = (string)setting('tcert_t' . (int)$t['id'] . '_' . $k);
        $o[$k] = $v !== '' ? $v : tcert_setting('tcert_' . $k);
    }
    if ($o['sign1_name'] === '') $o['sign1_name'] = (string)($t['trainer'] ?? '');
    return $o;
}

/** Lloji i aktivitetit nga titulli: punëtori / trajnim */
function tcert_kind(array $t): string {
    $s = mb_strtolower($t['title'] . ' ' . ($t['topic'] ?? ''));
    if (str_contains($s, 'workshop') || str_contains($s, 'punëtori') || str_contains($s, 'punetori')) return 'punëtorinë';
    if (str_contains($s, 'webinar')) return 'webinarin';
    return 'trajnimin';
}

/** Ndryshimet e adminit për një trajnim: ['f' => [fusha => tekst], 'p' => [mësuesi => [fusha => tekst]]] */
function tcert_overrides(int $trainingId): array {
    $raw = (string)setting(tcert_override_key($trainingId));
    if ($raw === '' && tcert_merge_all()) $raw = (string)setting('tcert_t' . $trainingId . '_json');   // ndryshimet e ruajtura më parë për këtë trajnim
    $j = json_decode($raw, true);
    return is_array($j) ? ['f' => (array)($j['f'] ?? []), 'p' => (array)($j['p'] ?? [])] : ['f' => [], 'p' => []];
}
const TCERT_FIELDS = ['heading', 'subheading', 'heading_en', 'awarded', 'awarded_en', 'for', 'title', 'topic', 'meta', 'meta_en', 'program', 'program_en', 'sign1_name', 'sign1_role', 'sign2_name', 'sign2_role', 'issued'];
const TCERT_PERSON_FIELDS = ['name', 'school'];

function tcert_field_labels(): array {
    return ['heading' => 'Titulli', 'subheading' => 'Nëntitulli', 'heading_en' => 'Titulli EN', 'awarded' => 'I jepet', 'awarded_en' => 'EN', 'name' => 'Emri',
        'school' => 'Shkolla', 'for' => 'Për pjesëmarrjen', 'title' => 'Titulli i trajnimit', 'topic' => 'Tema', 'meta' => 'Vendi', 'meta_en' => 'Datat e trajnimit',
        'program' => 'Projekti', 'program_en' => 'Projekti EN', 'sign1_name' => 'Nënshkruesi 1', 'sign1_role' => 'Roli 1', 'sign2_name' => 'Nënshkruesi 2', 'sign2_role' => 'Roli 2', 'issued' => 'Data e lëshimit'];
}

/** Tekstet fillestare të certifikatës (nga trajnimi dhe cilësimet) */
function tcert_default_texts(array $t, array $teacher): array {
    $bi = tcert_setting('tcert_bilingual') === '1';
    $hours = rtrim(rtrim(number_format((float)$t['hours'], 1, '.', ''), '0'), '.');
    $place = implode(', ', array_filter([$t['location'] ?? '', $t['municipality'] ?? '']));
    $sg = tcert_signers($t);
    return [
        'heading' => tcert_setting('tcert_heading'),
        'subheading' => tcert_setting('tcert_subheading'),
        'heading_en' => $bi ? tcert_setting('tcert_heading_en') : '',
        'awarded' => tcert_setting('tcert_awarded'),
        'awarded_en' => $bi ? '/ This certificate is awarded to' : '',
        'name' => (string)$teacher['full_name'],
        'school' => (string)($teacher['school'] ?? ''),
        'for' => tcert_setting('tcert_for') . (preg_match('/^(punëtori|punetori|trajnim|workshop|webinar)/iu', trim((string)$t['title'])) ? '' : ' ' . tcert_kind($t)),
        'title' => (string)$t['title'],
        'topic' => (string)($t['topic'] ?? ''),
        // një certifikatë për gjithë trajnimin: vendi, pastaj datat e të gjitha ditëve (p.sh. 12–13 Nëntor 2026)
        'meta' => $place !== '' ? '📍 ' . $place : '',
        'meta_en' => tcert_dates($t['start_date'], $t['end_date']),
        'program' => tcert_setting('tcert_program'),
        'program_en' => $bi ? tcert_setting('tcert_program_en') : '',
        'sign1_name' => $sg['sign1_name'], 'sign1_role' => $sg['sign1_role'],
        'sign2_name' => $sg['sign2_name'], 'sign2_role' => $sg['sign2_role'],
        'issued' => 'Lëshuar më ' . date('d.m.Y'),
    ];
}

/** HTML-ja e një certifikate (A4 horizontale). Me $editable admini klikon mbi çdo tekst dhe e ndryshon. */
function tcert_html(array $t, array $teacher, ?array $logos = null, bool $editable = false, ?array $ov = null): string {
    $logos ??= tcert_logos();
    $ov ??= tcert_overrides((int)$t['id']);
    $c = preg_match('/^#[0-9a-fA-F]{6}$/', tcert_setting('tcert_color')) ? tcert_setting('tcert_color') : '#00AEEF';
    $tx = tcert_default_texts($t, $teacher);
    foreach (TCERT_FIELDS as $k) if (isset($ov['f'][$k])) $tx[$k] = (string)$ov['f'][$k];
    $pid = (string)(int)$teacher['id'];
    foreach (TCERT_PERSON_FIELDS as $k) if (isset($ov['p'][$pid][$k])) $tx[$k] = (string)$ov['p'][$pid][$k];
    $code = tcert_code((int)$t['id'], (int)$teacher['id'], (string)$t['start_date']);
    // një element i ndryshueshëm; bosh = fshihet në printim
    $f = function (string $k, string $tag, string $cls) use ($tx, $editable, $pid): string {
        $v = $tx[$k] ?? '';
        $person = in_array($k, TCERT_PERSON_FIELDS, true);
        $attr = $editable ? ' contenteditable="true" spellcheck="false" data-label="' . e(tcert_field_labels()[$k] ?? $k) . '" data-f="' . $k . '"' . ($person ? ' data-p="' . $pid . '"' : '') : '';
        return '<' . $tag . ' class="' . $cls . ($v === '' ? ' tc-empty' : '') . '"' . $attr . '>' . e($v) . '</' . $tag . '>';
    };
    ob_start(); ?>
<div class="tc" style="--c:<?= e($c) ?>">
  <div class="tc-in">
    <?php if ($logos): ?><div class="tc-logos"><?php foreach ($logos as $l): ?><img src="<?= e($l) ?>" alt=""><?php endforeach; ?></div><?php endif; ?>
    <?= $f('heading', 'div', 'tc-h1') ?>
    <?= $f('subheading', 'div', 'tc-h2') ?>
    <?= $f('heading_en', 'div', 'tc-en') ?>
    <div class="tc-aw"><?= $f('awarded', 'span', '') ?> <?= $f('awarded_en', 'span', 'tc-en-i') ?></div>
    <?= $f('name', 'div', 'tc-name') ?>
    <?= $f('school', 'div', 'tc-school') ?>
    <?= $f('for', 'div', 'tc-for') ?>
    <?= $f('title', 'div', 'tc-title') ?>
    <?= $f('topic', 'div', 'tc-topic') ?>
    <div class="tc-meta"><?= $f('meta', 'div', '') ?><?= $f('meta_en', 'div', 'tc-en-i') ?></div>
    <div class="tc-prog"><?= $f('program', 'div', '') ?><?= $f('program_en', 'div', 'tc-en-i') ?></div>
    <div class="tc-sign">
      <div><div class="tc-line"></div><?= $f('sign1_name', 'strong', '') ?><?= $f('sign1_role', 'div', 'small') ?></div>
      <div class="tc-seal">🎓</div>
      <div><div class="tc-line"></div><?= $f('sign2_name', 'strong', '') ?><?= $f('sign2_role', 'div', 'small') ?></div>
    </div>
  </div>
  <div class="tc-code">Nr. <?= e($code) ?> · <?= $f('issued', 'span', '') ?></div>
</div>
<?php return (string)ob_get_clean();
}

function tcert_page_css(): string {
    return '<style>
body{background:#e9eef3;margin:0;padding:16px;font-family:"Segoe UI",Arial,sans-serif;color:#1d2433}
.tc{width:277mm;height:186mm;margin:0 auto 16px;background:#fff;box-sizing:border-box;padding:5mm 5mm 7mm;border:3mm solid var(--c);position:relative;page-break-after:always;break-after:page;overflow:hidden}
.tc:last-child{page-break-after:auto;break-after:auto}
.tc-in{height:100%;box-sizing:border-box;border:.6mm solid var(--c);padding:5mm 14mm 4mm;display:flex;flex-direction:column;align-items:center;text-align:center;background:linear-gradient(180deg,#fff 0,#fff 75%,color-mix(in srgb,var(--c) 7%,#fff) 100%)}
.tc-logos{display:flex;gap:10mm;align-items:center;justify-content:center;height:17mm}.tc-logos img{max-height:17mm;max-width:55mm;object-fit:contain}
.tc-adt{font-weight:900;font-size:9mm;color:var(--c);letter-spacing:1.5mm}
.tc-h1{font-size:15mm;font-weight:800;letter-spacing:2.6mm;color:var(--c);margin-top:4mm;line-height:1}
.tc-h2{font-size:5.6mm;font-weight:700;letter-spacing:2mm;color:#2b3446;margin-top:2mm}
.tc-en{font-size:4mm;color:#6b7487;font-style:italic;margin-top:.5mm}
.tc-aw{font-size:4.6mm;margin-top:7mm;color:#3a4356}
.tc-name{font-size:12mm;font-weight:700;font-family:Georgia,"Times New Roman",serif;margin-top:1mm;border-bottom:.5mm solid var(--c);padding:0 10mm 1mm;min-width:150mm}
.tc-school{font-size:4.2mm;color:#5a6377;margin-top:1.5mm}
.tc-for{font-size:4.8mm;margin-top:5mm;color:#3a4356}
.tc-title{font-size:7.5mm;font-weight:700;color:#1d2433;margin-top:1mm;max-width:235mm;line-height:1.25}
.tc-topic{font-size:4.6mm;color:#3a4356;margin-top:1.5mm;max-width:235mm}
.tc-meta{font-size:4.6mm;margin-top:4mm;color:#2b3446}
.tc-prog{font-size:4.1mm;margin-top:4mm;color:#3a4356;max-width:235mm}
.tc-en-i{color:#6b7487;font-style:italic;font-size:.9em}
.tc-sign{margin-top:auto;display:grid;grid-template-columns:1fr 22mm 1fr;gap:12mm;width:100%;align-items:end;font-size:3.8mm}
.tc-sign strong{display:block;min-height:4.6mm}.tc-sign .small{font-size:3.1mm;color:#5a6377}.tc-line{border-top:.4mm solid #2b3446;margin:0 12mm 1.2mm}
.tc-seal{font-size:10mm;line-height:1;width:18mm;height:18mm;border-radius:50%;display:grid;place-items:center;border:.7mm solid var(--c);color:var(--c);margin:0 auto}
.tc-code{position:absolute;bottom:1.6mm;right:6mm;font-size:2.6mm;color:#8a93a6}
.no-print{text-align:center;margin-bottom:12px}
[data-f]{white-space:pre-line;outline:none;border-radius:1mm;min-width:12mm;cursor:text}
[data-f]:hover{background:rgba(255,214,0,.18);box-shadow:0 0 0 .4mm rgba(255,170,0,.6)}
[data-f]:focus{background:rgba(255,214,0,.28);box-shadow:0 0 0 .5mm #f0a020}
[data-f]:empty::before{content:"＋ " attr(data-label);color:#c3c9d4;font-size:3mm;font-style:normal;font-weight:400;letter-spacing:0}
.tc-empty:not([data-f]){display:none}
[data-f].tc-dirty{box-shadow:0 0 0 .5mm #14804a}
@media print{[data-f]:empty{display:none}[data-f]{background:none!important;box-shadow:none!important}[data-f]:empty::before{content:none}body{background:#fff;padding:0}.no-print{display:none}.tc{margin:0}@page{size:A4 landscape;margin:8mm 10mm}}
</style>';
}

/* ---------------- Materialet e trajnimeve ---------------- */
const TMAT_EXT = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'pps', 'ppsx', 'xls', 'xlsx', 'odt', 'odp', 'ods', 'txt', 'csv', 'zip', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'];
const TMAT_MAX_MB = 200;

function tmat_dir(): string {
    $d = APP_ROOT . '/backups/training_materials';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function tmat_store(int $trainingId, array $f, string $title, bool $all, int $uid): void {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException($f['name'] . ': skedari është shumë i madh për serverin.');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException($f['name'] . ': ngarkimi dështoi (kodi ' . (int)$f['error'] . ').');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, TMAT_EXT, true)) throw new RuntimeException($f['name'] . ': lejohen ' . implode(', ', TMAT_EXT) . '.');
    if ($f['size'] > TMAT_MAX_MB * 1048576) throw new RuntimeException($f['name'] . ': kalon ' . TMAT_MAX_MB . ' MB.');
    $stored = 't' . $trainingId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], tmat_dir() . '/' . $stored)) throw new RuntimeException('Skedari nuk u ruajt (lejet e dosjes backups).');
    $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\x{2013}\-]/u', '_', basename((string)$f['name'])), 0, 180);
    q('INSERT INTO training_materials (training_id, title, original_name, stored_name, size, all_teachers, uploaded_by) VALUES (?,?,?,?,?,?,?)',
        [$trainingId, $title !== '' ? mb_substr($title, 0, 200) : null, $name, $stored, (int)$f['size'], $all ? 1 : 0, $uid]);
}
function tmat_icon(string $name): string {
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match (true) { $e === 'pdf' => '📕', in_array($e, ['doc', 'docx', 'odt', 'txt'], true) => '📘', in_array($e, ['ppt', 'pptx', 'pps', 'ppsx', 'odp'], true) => '📙',
        in_array($e, ['xls', 'xlsx', 'ods', 'csv'], true) => '📗', in_array($e, ['mp4', 'mp3'], true) => '🎬', $e === 'zip' => '🗜️', default => '📄' };
}
function tmat_size(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; }

/** Materialet që sheh një mësues: të trajnimeve ku është regjistruar + ato «për të gjithë mësuesit» */
function tmat_for_teacher(int $teacherId): array {
    try {
        return qa('SELECT m.*, t.title training_title, t.start_date FROM training_materials m JOIN trainings t ON t.id=m.training_id
                   WHERE m.all_teachers=1 OR m.training_id IN (SELECT training_id FROM training_attendance WHERE teacher_id=?) ORDER BY t.start_date DESC, m.id', [$teacherId]);
    } catch (Throwable) { return []; }
}
function tmat_can_download(array $u, array $m): bool {
    if ($u['role'] === 'admin') return true;
    if ($u['role'] !== 'teacher') return false;
    return (bool)$m['all_teachers'] || (bool)qv('SELECT 1 FROM training_attendance WHERE training_id=? AND teacher_id=?', [$m['training_id'], $u['id']]);
}
