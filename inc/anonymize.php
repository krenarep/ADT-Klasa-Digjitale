<?php
declare(strict_types=1);

/**
 * Anonimizimi i nxënësve (pseudonimizim).
 * Në sistem ruhet vetëm kodi i nxënësit, p.sh. PRI-IQ-01-05 =
 *   komuna (3 shkronja) - shkolla (maks. 3) - klasa (01, 02…) - nxënësi (01, 02…).
 * Emri i vërtetë NUK ruhet në bazë: mësuesi e merr një herë në listën-çelës (CSV) dhe e mban vetë.
 */

const ANON_RE = '/^[A-Z]{3}-[A-Z0-9]{1,4}-\d{2,3}-\d{2,3}$/';

function anon_enabled(): bool { return setting('anonymize_students') === '1'; }
function anon_is_code(?string $name): bool { return (bool)preg_match(ANON_RE, (string)$name); }

function anon_letters(string $s): string {
    $s = strtr(mb_strtoupper($s, 'UTF-8'), ['Ë' => 'E', 'Ç' => 'C', 'É' => 'E', 'Ö' => 'O', 'Ü' => 'U', 'Š' => 'S', 'Ž' => 'Z', 'Č' => 'C', 'Ć' => 'C', 'Đ' => 'D']);
    return preg_replace('/[^A-Z0-9 ]+/', ' ', $s) ?? '';
}

/** Komuna → 3 shkronja (Prishtinë → PRI, Fushë Kosovë → FUS, Vushtrri → VUS) */
function anon_muni_code(?string $muni): string {
    $l = str_replace(' ', '', anon_letters((string)$muni));
    return str_pad(substr($l, 0, 3), 3, 'X');
}

/** Emri i shkollës → akronim (maks. 3): SHFMU "Ylli i Mëngjesit" → YM, "Dielli" → DIE */
function anon_school_auto(string $name): string {
    if (preg_match('/[“"\'«]+\s*([^”"\'»]+?)\s*[”"\'»]/u', $name, $m)) $core = $m[1];
    else $core = preg_replace('/^\s*(SH\.?\s*F\.?\s*M\.?\s*U\.?|SHFMU|SHFMU|SHF|SHMU|QBMK|Q\.?B\.?|Qendra Burimore[^"“]*|Shkolla( fillore)?)\s*/iu', '', $name);
    $core = preg_replace('/\s+-\s+.*$/u', '', (string)$core);
    $words = array_values(array_filter(explode(' ', anon_letters((string)$core)), fn($w) => strlen($w) > 1 && !in_array($w, ['DHE', 'PER', 'TE', 'E', 'I'], true)));
    if (!$words) return 'SH';
    if (count($words) === 1) return substr($words[0], 0, 3);
    return substr(implode('', array_map(fn($w) => $w[0], $words)), 0, 3);
}

/** Kodi i shkollës (i ruajtur ose automatik, unik brenda komunës) */
function anon_school_code(int $schoolId): string {
    $s = q1('SELECT id, name, city, code FROM schools WHERE id=?', [$schoolId]);
    if (!$s) return 'SH';
    if (!empty($s['code'])) return strtoupper((string)$s['code']);
    $base = anon_school_auto((string)$s['name']); $code = $base; $i = 1;
    while (qv('SELECT 1 FROM schools WHERE code=? AND city <=> ? AND id<>?', [$code, $s['city'], $schoolId])) $code = substr($base, 0, 2) . (++$i);
    q('UPDATE schools SET code=? WHERE id=?', [$code, $schoolId]);
    return $code;
}

/** Numri i klasës brenda shkollës (01, 02…) — caktohet një herë */
function anon_class_no(array $class): int {
    if (!empty($class['anon_no'])) return (int)$class['anon_no'];
    $n = (int)qv('SELECT COALESCE(MAX(anon_no),0)+1 FROM classes WHERE school_id=?', [$class['school_id']]);
    q('UPDATE classes SET anon_no=? WHERE id=? AND anon_no IS NULL', [$n, $class['id']]);
    return (int)qv('SELECT anon_no FROM classes WHERE id=?', [$class['id']]);
}

function anon_class_prefix(array $class): string {
    $muni = qv('SELECT city FROM schools WHERE id=?', [$class['school_id']]);
    return anon_muni_code((string)$muni) . '-' . anon_school_code((int)$class['school_id']) . '-' . sprintf('%02d', anon_class_no($class));
}

/** Kodi i radhës për një nxënës të ri në klasë — merr numrin më të vogël të lirë (plotëson vendet e nxënësve të fshirë) */
function anon_next_code(array $class): string {
    $prefix = anon_class_prefix($class);
    $used = [];
    foreach (qa("SELECT full_name, username FROM users WHERE role='student' AND (full_name LIKE ? OR username LIKE ?)", [$prefix . '-%', strtolower($prefix) . '-%']) as $r) {
        foreach ([$r['full_name'], strtoupper((string)$r['username'])] as $v) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$v, $m)) $used[(int)$m[1]] = true;
        }
    }
    $n = 1;
    while (isset($used[$n])) $n++;
    return $prefix . '-' . sprintf('%02d', $n);
}

/** Gjurmë e emrit (HMAC me çelës sekret jashtë bazës) — vetëm për të shmangur dyfishimet gjatë importit */
function anon_name_hash(string $name, int $classId): string {
    $f = APP_ROOT . '/backups/.anon_key';
    if (!is_file($f)) { @mkdir(dirname($f), 0775, true); file_put_contents($f, bin2hex(random_bytes(32))); }
    $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    return hash_hmac('sha256', $norm . '|' . $classId, trim((string)file_get_contents($f)));
}

/** Heq emrin e vërtetë nga regjistri i veprimeve */
function anon_scrub_audit(string $realName, string $code): void {
    if (mb_strlen($realName) < 3) return;
    try {
        q('UPDATE audit_log SET details = REPLACE(details, ?, ?) WHERE details LIKE ?', [$realName, $code, '%' . $realName . '%']);
        q('UPDATE audit_log SET target = REPLACE(target, ?, ?) WHERE target LIKE ?', [$realName, $code, '%' . $realName . '%']);
        q('UPDATE audit_log SET user_name = ? WHERE user_name = ?', [$code, $realName]);
    } catch (Throwable) {}
}

/**
 * Anonimizon nxënësit ekzistues të klasave të dhëna. Kthen rreshtat e listës-çelës.
 * @param int[] $classIds
 */
function anonymize_classes(array $classIds): array {
    $key = [];
    foreach ($classIds as $cid) {
        $c = q1('SELECT c.*, s.name school_name, s.city, t.full_name teacher_name FROM classes c JOIN schools s ON s.id=c.school_id JOIN users t ON t.id=c.teacher_id WHERE c.id=?', [(int)$cid]);
        if (!$c) continue;
        $students = qa("SELECT u.id, u.full_name, u.username, u.plain_hint, u.picture_hint FROM users u JOIN class_students cs ON cs.student_id=u.id
                        WHERE cs.class_id=? AND u.role='student' ORDER BY u.full_name", [$c['id']]);
        foreach ($students as $s) {
            if (anon_is_code($s['full_name'])) continue;
            // Nëse përdoruesi është tashmë kod (emri u rishkrua më vonë), rikthe të njëjtin kod — hyrja e fëmijës nuk ndryshon
            $prev = strtoupper((string)$s['username']);
            if (anon_is_code($prev) && !qv("SELECT 1 FROM users WHERE role='student' AND full_name=? AND id<>?", [$prev, $s['id']])) {
                $code = $prev; $user = (string)$s['username'];
            } else {
                $code = anon_next_code($c);
                $user = strtolower($code);
                if (qv('SELECT 1 FROM users WHERE username=? AND id<>?', [$user, $s['id']])) $user .= '-' . $s['id'];
            }
            // emri i prindit te pëlqimi NV identifikon fëmijën → ruhet vetëm fakti që pëlqimi ekziston
            q('UPDATE users SET full_name=?, username=?, name_hash=?, sen_consent_by = IF(sen_consent_by IS NULL, NULL, \'Prindi/kujdestari\') WHERE id=?', [$code, $user, anon_name_hash((string)$s['full_name'], (int)$c['id']), $s['id']]);
            anon_scrub_audit((string)$s['full_name'], $code);
            $key[] = ['municipality' => $c['city'], 'school' => $c['school_name'], 'teacher' => $c['teacher_name'], 'class' => $c['name'],
                'code' => $code, 'real_name' => $s['full_name'], 'username' => $user, 'password' => dec_hint($s['plain_hint']), 'seq' => dec_hint($s['picture_hint'])];
        }
    }
    if ($key) audit('student.anonymize', count($key) . ' nxënës', count($classIds) . ' klasa');
    return $key;
}

/** Dërgon listën-çelës si CSV (shkarkohet një herë; nuk ruhet në server) */
function anon_send_key_csv(array $rows, string $filename): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Komuna', 'Shkolla', 'Mësuesi', 'Klasa', 'Kodi i nxënësit', 'Emri i vërtetë', 'Përdoruesi', 'Fjalëkalimi', 'Fotot (hyrja me foto)'], ';', '"', '\\');
    $set = picture_set();
    foreach ($rows as $r) {
        $pics = $r['seq'] ? implode(' ', array_map(fn($i) => $set[(int)$i][1] ?? '', explode('-', (string)$r['seq']))) : '';
        fputcsv($out, [$r['municipality'], $r['school'], $r['teacher'], $r['class'], $r['code'], $r['real_name'], $r['username'], $r['password'], $pics], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

/**
 * Rikthen automatikisht kodin te nxënësit të cilëve dikush ua ka rishkruar emrin
 * (përdoruesi mbetet kod, p.sh. vus-nf-01-01 → emri bëhet përsëri VUS-NF-01-01). Emri i shtypur fshihet edhe nga regjistri.
 */
function anon_restore_codes(): int {
    if (!anon_enabled()) return 0;
    $n = 0;
    foreach (qa("SELECT id, full_name, username FROM users WHERE role='student'
                 AND full_name NOT REGEXP '^[A-Z]{3}-[A-Z0-9]{1,4}-[0-9]{2,3}-[0-9]{2,3}$'
                 AND username REGEXP '^[a-z]{3}-[a-z0-9]{1,4}-[0-9]{2,3}-[0-9]{2,3}$'") as $s) {
        $code = strtoupper($s['username']);
        if (qv("SELECT 1 FROM users WHERE role='student' AND full_name=? AND id<>?", [$code, $s['id']])) continue;
        q('UPDATE users SET full_name=? WHERE id=?', [$code, $s['id']]);
        anon_scrub_audit((string)$s['full_name'], $code);
        $n++;
    }
    return $n;
}

/** Statistikat për faqen e administrimit */
function anon_stats(): array {
    return [
        'total' => (int)qv("SELECT COUNT(*) FROM users WHERE role='student'"),
        'coded' => (int)qv("SELECT COUNT(*) FROM users WHERE role='student' AND full_name REGEXP '^[A-Z]{3}-[A-Z0-9]{1,4}-[0-9]{2,3}-[0-9]{2,3}$'"),
    ];
}
