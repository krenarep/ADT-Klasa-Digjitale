<?php
declare(strict_types=1);

function cfg(string $key, $default = null) {
    return $GLOBALS['CFG'][$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)$c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q1(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r ?: null;
}
function qv(string $sql, array $params = []) {
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}
function qa(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}

function e($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string {
    return rtrim((string)cfg('base_url', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never {
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $k, $d = '') {
    $v = $_POST[$k] ?? $d;
    return is_string($v) ? trim($v) : $v;
}
function get(string $k, $d = '') {
    $v = $_GET[$k] ?? $d;
    return is_string($v) ? trim($v) : $v;
}
function geti(string $k, int $d = 0): int {
    return isset($_GET[$k]) && is_numeric($_GET[$k]) ? (int)$_GET[$k] : $d;
}
function posti(string $k, int $d = 0): int {
    return isset($_POST[$k]) && is_numeric($_POST[$k]) ? (int)$_POST[$k] : $d;
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Sesioni ka skaduar. Kthehuni prapa dhe rifreskoni faqen.');
    }
}

/* ---------- Flash ---------- */
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = [$type, $msg];
}
function flashes(): string {
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $icon = $type === 'ok' ? '✅' : ($type === 'err' ? '⚠️' : 'ℹ️');
        $out .= '<div class="flash flash-' . e($type) . '" role="status"><span aria-hidden="true">' . $icon . '</span> ' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- Formatim ---------- */
function fmt_duration(int $sec): string {
    if ($sec <= 0) return '0 min';
    if ($sec < 60) return $sec . ' sek';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) return $h . ' orë' . ($m ? ' ' . $m . ' min' : '');
    return $m . ' min';
}
function fmt_dt(?string $dt, bool $time = true): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return date($time ? 'd.m.Y H:i' : 'd.m.Y', $ts);
}
function ago(?string $dt): string {
    if (!$dt) return 'asnjëherë';
    $d = time() - strtotime($dt);
    if ($d < 60) return 'tani';
    if ($d < 3600) return 'para ' . intdiv($d, 60) . ' min';
    if ($d < 86400) return 'para ' . intdiv($d, 3600) . ' orësh';
    if ($d < 86400 * 30) return 'para ' . intdiv($d, 86400) . ' ditësh';
    return fmt_dt($dt, false);
}
function pct(int|float $part, int|float $whole): int {
    return $whole > 0 ? (int)round(min(100, $part * 100 / $whole)) : 0;
}

/* ---------- Gjeneruesit ---------- */
function translit(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ë' => 'e', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'ö' => 'o', 'ü' => 'u', 'ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'ž' => 'z', 'š' => 's', 'č' => 'c', 'ć' => 'c', 'đ' => 'dj']);
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
}
function make_username(string $fullName): string {
    $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
    $first = translit($parts[0] ?? 'nxenes');
    $last  = count($parts) > 1 ? translit(end($parts)) : '';
    $base  = substr($first . ($last ? '.' . $last : ''), 0, 40) ?: 'perdorues';
    $u = $base;
    $i = 1;
    while (qv('SELECT 1 FROM users WHERE username = ?', [$u])) {
        $i++;
        $u = $base . $i;
    }
    return $u;
}
function make_kid_password(): string {
    $words = ['diell', 'lule', 'mace', 'peshk', 'yll', 'det', 'mal', 'molla', 'zog', 'ari', 'pema', 'hena', 'topi', 'libri', 'era'];
    return $words[random_int(0, count($words) - 1)] . random_int(10, 99);
}
function make_class_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (qv('SELECT 1 FROM classes WHERE class_code = ?', [$c]));
    return $c;
}

/** Fotot për fjalëkalimin me figura (indeksi => [emoji, emri]) */
function picture_set(): array {
    return [
        ['🐶', 'qeni'], ['🐱', 'macja'], ['🐟', 'peshku'], ['🦋', 'flutura'],
        ['🌞', 'dielli'], ['🌈', 'ylberi'], ['🍎', 'molla'], ['🍌', 'banania'],
        ['🚗', 'makina'], ['⚽', 'topi'], ['🎈', 'tullumbacja'], ['⭐', 'ylli'],
    ];
}
function avatar_set(): array {
    return ['🦊', '🐼', '🐨', '🦁', '🐯', '🐸', '🐵', '🐰', '🐻', '🐧', '🦄', '🐢', '🐙', '🦉', '🐞', '🐳'];
}
function random_picture_seq(): string {
    $n = count(picture_set());
    $seq = [];
    while (count($seq) < 3) {
        $seq[] = random_int(0, $n - 1);
    }
    return implode('-', $seq);
}
function picture_seq_valid(string $seq): bool {
    return (bool)preg_match('/^(\d{1,2})-(\d{1,2})-(\d{1,2})$/', $seq, $m)
        && max((int)$m[1], (int)$m[2], (int)$m[3]) < count(picture_set());
}
function picture_seq_html(?string $seq): string {
    if (!$seq) return '—';
    $set = picture_set();
    $out = [];
    foreach (explode('-', $seq) as $i) {
        if (isset($set[(int)$i])) $out[] = '<span title="' . e($set[(int)$i][1]) . '">' . $set[(int)$i][0] . '</span>';
    }
    return '<span class="picseq">' . implode('', $out) . '</span>';
}

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Etiketat ---------- */
function ticket_categories(): array {
    return [
        'not_working' => ['🛠️', 'Diçka nuk funksionon'],
        'dont_understand' => ['🤔', 'Nuk e kuptoj detyrën'],
        'login' => ['🔑', 'Problem me hyrjen'],
        'content' => ['📖', 'Gabim në libër'],
        'technical' => ['💻', 'Problem teknik'],
        'account' => ['👤', 'Llogari / qasje'],
        'other' => ['💬', 'Tjetër'],
    ];
}
function ticket_kinds(): array {
    return ['problem' => 'Problem', 'question' => 'Pyetje', 'comment' => 'Koment'];
}
function status_label(string $s): string {
    return ['open' => 'E hapur', 'in_progress' => 'Në proces', 'closed' => 'E mbyllur'][$s] ?? $s;
}
function status_badge(string $s): string {
    return '<span class="badge badge-' . e($s) . '">' . e(status_label($s)) . '</span>';
}
function section_type_label(?string $t): string {
    $map = [
        'activity_fill_in_a_table' => 'Plotëso tabelën',
        'activity_fill_in_the_blank' => 'Plotëso vendet bosh',
        'activity_open_ended_answer' => 'Përgjigje e hapur',
        'activity_matching' => 'Lidh çiftet',
        'activity_multiple_choice' => 'Zgjedhje e shumëfishtë',
        'activity_multi_select' => 'Zgjedhje të shumta',
        'activity_true_false' => 'E vërtetë / e gabuar',
        'activity_sorting' => 'Rendit në grupe',
        'activity_ordering' => 'Rendit',
        'activity_underline_text' => 'Nënvizo',
        'activity_quiz' => 'Kuiz',
        'activity_other' => 'Ushtrim',
        'activity_custom' => 'Ushtrim',
        'scorm' => 'SCORM',
    ];
    return $map[$t ?? ''] ?? ($t ? 'Ushtrim' : '—');
}

function progress_bar(int $p, string $label = ''): string {
    $cls = $p >= 80 ? 'good' : ($p >= 40 ? 'mid' : 'low');
    return '<div class="pbar ' . $cls . '" role="progressbar" aria-valuenow="' . $p . '" aria-valuemin="0" aria-valuemax="100"'
        . ($label ? ' aria-label="' . e($label) . '"' : '') . '><span style="width:' . $p . '%"></span></div>';
}

/* ---------- Komunat e Kosovës ---------- */
function municipalities(): array {
    return ['Deçan', 'Dragash', 'Ferizaj', 'Fushë Kosovë', 'Gjakovë', 'Gjilan', 'Graçanicë', 'Hani i Elezit', 'Istog', 'Junik',
        'Kaçanik', 'Kamenicë', 'Klinë', 'Kllokot', 'Leposaviq', 'Lipjan', 'Malishevë', 'Mamushë', 'Mitrovicë', 'Mitrovicë e Veriut',
        'Novobërdë', 'Obiliq', 'Partesh', 'Pejë', 'Podujevë', 'Prishtinë', 'Prizren', 'Rahovec', 'Ranillug', 'Skënderaj',
        'Shtërpcë', 'Shtime', 'Suharekë', 'Viti', 'Vushtrri', 'Zubin Potok', 'Zveçan', 'Drenas'];
}
function municipality_options(?string $selected, bool $withEmpty = true, string $emptyLabel = '— zgjidh komunën —'): string {
    $list = municipalities();
    sort($list, SORT_LOCALE_STRING);
    $h = $withEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
    if ($selected && !in_array($selected, $list, true)) $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . '</option>';
    foreach ($list as $m) $h .= '<option value="' . e($m) . '"' . ($selected === $m ? ' selected' : '') . '>' . e($m) . '</option>';
    return $h;
}
function valid_municipality(string $m): bool {
    return in_array($m, municipalities(), true);
}
/** Normalizon telefonin: lejon +, shifra, hapësira, - ; kthen null nëse i pavlefshëm */
function normalize_phone(string $p): ?string {
    $p = trim(preg_replace('/[^\d+\s\-\/()]/', '', $p));
    $digits = preg_replace('/\D/', '', $p);
    if (strlen($digits) < 8 || strlen($digits) > 15) return null;
    return preg_replace('/\s+/', ' ', $p);
}

/* ---------- Të dhënat demografike të nxënësit ---------- */
function genders(): array { return ['F' => 'Vajzë', 'M' => 'Djalë']; }
function gender_label(?string $g): string { return genders()[$g ?? ''] ?? '—'; }
function special_needs_types(): array {
    return ['Vështirësi në të lexuar (disleksi)', 'Vështirësi në të nxënë', 'Dëmtim i shikimit', 'Dëmtim i dëgjimit',
        'Spektri i autizmit', 'Vështirësi në të folur / gjuhë', 'Vështirësi motorike / fizike', 'ADHD / vëmendje', 'Tjetër'];
}
function mother_tongues(): array { return ['Shqip', 'Serbisht', 'Boshnjakisht', 'Turqisht', 'Romanisht', 'Gorançe', 'Tjetër']; }
function select_options(array $list, ?string $selected, string $empty = '—', bool $useKeys = false): string {
    $h = '<option value="">' . e($empty) . '</option>';
    $isList = array_is_list($list) && !$useKeys;
    foreach ($list as $k => $v) {
        $val = $isList ? $v : (string)$k;
        $h .= '<option value="' . e($val) . '"' . ((string)$selected === (string)$val ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $h;
}
/** Lexon gjininë nga tekst i lirë (V/D, F/M, vajzë/djalë, femër/mashkull) */
function parse_gender(string $v): ?string {
    $v = translit($v);
    if ($v === '') return null;
    if (in_array($v, ['f', 'v', 'vajze', 'vajza', 'femer', 'femre', 'female', 'girl', 'z', 'zenska'], true)) return 'F';
    if (in_array($v, ['m', 'd', 'djale', 'djali', 'mashkull', 'male', 'boy', 'muska'], true)) return 'M';
    return null;
}
/** Lexon nevojat e veçanta: "Po", "Jo", ose emri i llojit → [0|1|null, lloji|null] */
function parse_special_needs(string $v): array {
    $t = translit($v);
    if ($t === '' ) return [null, null];
    if (in_array($t, ['jo', 'no', '0', 'n'], true)) return [0, null];
    if (in_array($t, ['po', 'yes', '1', 'p', 'y', 'x'], true)) return [1, null];
    $types = special_needs_types();
    $kw = ['disleks|lexim|lexuar|dyslex' => 0, 'nxen|learning' => 1, 'shikim|verber|qorr|visual|blind' => 2, 'degjim|shurd|deaf|hearing' => 3,
        'autiz|autism' => 4, 'folur|gjuhe|speech' => 5, 'motor|fizik|physical' => 6, 'adhd|vemendj|attention' => 7];
    foreach ($kw as $re => $i) if (preg_match('/' . $re . '/', $t)) return [1, $types[$i]];
    foreach ($types as $type) if (str_starts_with(translit($type), substr($t, 0, 6))) return [1, $type];
    return [1, mb_substr(trim($v), 0, 60)];
}
/** Pastron fushat demografike nga një formular POST (hedh gabim nëse mungon pëlqimi për NV) */
function demographics_from_post(): array {
    $g = post('gender');
    $sn = post('special_needs');
    $type = post('special_needs_type');
    $mt = post('mother_tongue');
    $consent = (bool)post('sen_consent');
    $by = mb_substr(trim((string)post('sen_consent_by')), 0, 120);
    if (($sn === '1' || $type !== '') && (!$consent || $by === '')) {
        throw new RuntimeException('Për të ruajtur të dhënat e nevojave të veçanta duhet pëlqimi i prindit/kujdestarit (shënoni pëlqimin dhe emrin e prindit).');
    }
    return [
        'gender' => isset(genders()[$g]) ? $g : null,
        'special_needs' => $sn === '1' ? 1 : ($sn === '0' ? 0 : null),
        'special_needs_type' => $sn === '1' && $type !== '' ? mb_substr($type, 0, 60) : null,
        'mother_tongue' => in_array($mt, mother_tongues(), true) ? $mt : null,
        'consent' => $consent && $by !== '',
        'consent_by' => $by,
    ];
}
function save_demographics(int $studentId, array $d): void {
    $d += ['gender' => null, 'special_needs' => null, 'special_needs_type' => null, 'mother_tongue' => null, 'consent' => false, 'consent_by' => ''];
    $hasConsentCols = db_column_exists('users', 'sen_consent_at');
    $old = $hasConsentCols ? q1('SELECT special_needs, special_needs_type, sen_consent_at FROM users WHERE id=?', [$studentId]) : null;
    q("UPDATE users SET gender=?, special_needs=?, special_needs_type=?, mother_tongue=? WHERE id=? AND role='student'",
        [$d['gender'], $d['special_needs'], $d['special_needs_type'], $d['mother_tongue'], $studentId]);
    if (!$hasConsentCols) return;
    if ($d['consent']) {
        q('UPDATE users SET sen_consent_at = COALESCE(sen_consent_at, NOW()), sen_consent_by = ? WHERE id=?', [$d['consent_by'], $studentId]);
        if (!$old || !$old['sen_consent_at']) audit('consent.give', '#' . $studentId, 'Prindi: ' . $d['consent_by']);
    } elseif ($old && $old['sen_consent_at'] && (int)$d['special_needs'] !== 1) {
        // pëlqimi u tërhoq: fshihen të dhënat e ndjeshme
        q('UPDATE users SET sen_consent_at=NULL, sen_consent_by=NULL, special_needs=NULL, special_needs_type=NULL WHERE id=?', [$studentId]);
        audit('consent.withdraw', '#' . $studentId, 'Të dhënat e NV u fshinë');
    }
    if ($old && ((string)$old['special_needs'] !== (string)$d['special_needs'] || (string)$old['special_needs_type'] !== (string)$d['special_needs_type'])) {
        audit('student.sen', '#' . $studentId, ((int)$d['special_needs'] === 1 ? 'Po' : 'Jo/—') . ($d['special_needs_type'] ? ' · ' . $d['special_needs_type'] : ''));
    }
}
function demographics_fields_html(array $v = []): string {
    return '<div class="grid-2"><label>Gjinia<select name="gender">' . select_options(genders(), $v['gender'] ?? null) . '</select></label>
      <label>Gjuha amtare<select name="mother_tongue">' . select_options(mother_tongues(), $v['mother_tongue'] ?? null) . '</select></label></div>
      <fieldset class="consent-box"><legend>♿ Nevojat e veçanta <span class="help">(të dhëna të ndjeshme)</span></legend>
      <div class="grid-2"><label>Nevoja të veçanta<select name="special_needs">' . select_options(['0' => 'Jo', '1' => 'Po'], isset($v['special_needs']) && $v['special_needs'] !== null ? (string)$v['special_needs'] : null, '—', true) . '</select></label>
      <label>Lloji (nëse po)<select name="special_needs_type">' . select_options(special_needs_types(), $v['special_needs_type'] ?? null) . '</select></label></div>
      <label class="checkline"><input type="checkbox" name="sen_consent" value="1"' . (!empty($v['sen_consent']) ? ' checked' : '') . '> Prindi/kujdestari ka dhënë pëlqimin me shkrim për ruajtjen e këtyre të dhënave</label>
      <label>Emri i prindit/kujdestarit që dha pëlqimin<input name="sen_consent_by" value="' . e($v['sen_consent_by'] ?? '') . '" maxlength="120"></label>
      <p class="help" style="margin:0">🔒 I shohin vetëm administratori dhe mësuesi i klasës; në raporte shfaqen vetëm si numra. Nëse hiqni pëlqimin, të dhënat e NV fshihen.</p></fieldset>';
}
function student_badges(array $s): string {
    $h = '';
    if (!empty($s['gender'])) $h .= '<span class="badge" title="' . e(gender_label($s['gender'])) . '">' . ($s['gender'] === 'F' ? '👧' : '👦') . '</span> ';
    if (!empty($s['special_needs'])) $h .= '<span class="badge badge-in_progress" title="' . e($s['special_needs_type'] ?: 'Nevoja të veçanta') . '">♿ NV</span> ';
    return $h;
}
