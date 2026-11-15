<?php
declare(strict_types=1);

/* ================= Cilësimet ================= */
function setting_defaults(): array {
    return [
        'alert_inactive_days' => '7',      // paralajmërim kur nxënësi s'ka hyrë N ditë
        'alert_low_score' => '50',         // paralajmërim kur saktësia bie nën këtë %
        'alert_min_graded' => '3',         // sa ushtrime të vlerësuara duhen për paralajmërimin e saktësisë
        'cert_threshold' => '90',          // progresi (%) për certifikatë
        'retention_events_days' => '365',  // klikimet fshihen pas N ditësh
        'retention_audit_days' => '730',   // regjistri i auditimit fshihet pas N ditësh
        'backups_keep' => '14',            // sa kopje rezervë mbahen
        'backup_auto' => '1',              // kopje rezervë automatike çdo ditë
        'parent_links' => '1',             // lejo linkun për prindër
        'small_cell_min' => '5',           // raportet: grupet me më pak se N nxënës shfaqen si "<N" (0 = çaktivizuar)
        'picture_login' => '1',            // hyrja me fotografi (kod klase + 3 foto); mund të çaktivizohet te Cilësimet
        'auto_assign_grade' => '1',        // librat globalë caktohen automatikisht te klasat e të njëjtit nivel
        'anonymize_students' => '1',       // nxënësit ruhen vetëm me kod (PRI-IQ-01-05); emrat i mban mësuesi
    ];
}
function setting(string $k): string {
    if (!isset($GLOBALS['__kd_settings'])) {
        $GLOBALS['__kd_settings'] = [];
        try { foreach (qa('SELECT k, v FROM settings') as $r) $GLOBALS['__kd_settings'][$r['k']] = (string)$r['v']; } catch (Throwable) {}
    }
    return $GLOBALS['__kd_settings'][$k] ?? (setting_defaults()[$k] ?? '');
}
function setting_int(string $k): int { return (int)setting($k); }
function picture_login_enabled(): bool { return setting('picture_login') === '1'; }

function set_setting(string $k, string $v): void {
    q('INSERT INTO settings (k, v, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()', [$k, $v]);
    if (isset($GLOBALS['__kd_settings'])) $GLOBALS['__kd_settings'][$k] = $v;
}
function app_secret(): string {
    $s = '';
    try { $s = (string)qv("SELECT v FROM settings WHERE k='app_secret'"); } catch (Throwable) {}
    if ($s === '') { $s = bin2hex(random_bytes(24)); try { set_setting('app_secret', $s); } catch (Throwable) {} }
    return $s;
}

/* ================= Auditimi ================= */
function audit(string $action, string $target = '', string $details = ''): void {
    try {
        $u = current_user();
        q('INSERT INTO audit_log (user_id, user_name, role, action, target, details, ip) VALUES (?,?,?,?,?,?,?)', [
            $u['id'] ?? null, $u['full_name'] ?? null, $u['role'] ?? null, mb_substr($action, 0, 40), mb_substr($target, 0, 160) ?: null,
            mb_substr($details, 0, 500) ?: null, client_ip(),
        ]);
    } catch (Throwable) { /* auditimi nuk duhet ta ndalojë punën */ }
}
function audit_actions(): array {
    return [
        'login' => '🔑 Hyrje', 'school.add' => '🏫 Shtoi shkollë', 'school.edit' => '🏫 Ndryshoi shkollë', 'school.delete' => '🏫 Fshiu shkollë',
        'teacher.add' => '👩‍🏫 Shtoi mësues', 'teacher.edit' => '👩‍🏫 Ndryshoi mësues', 'teacher.delete' => '👩‍🏫 Fshiu mësues',
        'student.add' => '🧒 Shtoi nxënës', 'student.edit' => '🧒 Ndryshoi nxënës', 'student.delete' => '🧒 Fshiu nxënës', 'student.import' => '📥 Importoi listë',
        'student.reset' => '🔑 Rivendosi kredencialet', 'student.enroll' => '🏷️ Regjistroi në klasë', 'student.unenroll' => '🏷️ Hoqi nga klasa',
        'student.sen' => '♿ Ndryshoi të dhëna NV', 'student.anonymize' => '🔒 Anonimizoi nxënës', 'journey.upload' => '🧭 Ngarkoi dokument (rrugëtimi)', 'journey.review' => '🧭 Shqyrtoi hapin e rrugëtimit', 'consent.give' => '✅ Pëlqim prindi', 'consent.withdraw' => '↩️ Tërhoqi pëlqimin',
        'book.upload' => '📚 Ngarkoi libër', 'book.delete' => '📚 Fshiu libër', 'grade' => '✍️ Vlerësoi / verifikoi përgjigje',
        'export' => '⬇️ Eksportoi të dhëna', 'report' => '📊 Gjeneroi raport', 'parent.link' => '👪 Linku i prindit', 'parent.view' => '👪 Prindi pa raportin',
        'backup' => '💾 Kopje rezervë', 'backup.download' => '💾 Shkarkoi kopje', 'settings' => '⚙️ Ndryshoi cilësimet', 'cleanup' => '🧹 Fshirje automatike',
        'assignment' => '📌 Detyrë', 'db.upgrade' => '🗄️ Përditësoi bazën',
    ];
}

/* ================= Detyrat e përditshme ================= */
/** Ekzekutohet një herë në ditë (nga cron ose automatikisht gjatë përdorimit) */
function run_daily_tasks(bool $force = false): array {
    $done = [];
    $today = date('Y-m-d');
    if (!$force) {
        if (setting('daily_last') === $today) return $done;
        // "bllokim" i thjeshtë që të mos ekzekutohet dy herë njëkohësisht
        $st = db()->prepare("UPDATE settings SET v=?, updated_at=NOW() WHERE k='daily_last' AND (v IS NULL OR v<>?)");
        $st->execute([$today, $today]);
        if ($st->rowCount() === 0) {
            if (qv("SELECT 1 FROM settings WHERE k='daily_last'")) return $done;
            set_setting('daily_last', $today);
        }
    } else {
        set_setting('daily_last', $today);
    }
    @set_time_limit(300);
    try { $done['cleanup'] = run_retention_cleanup(); } catch (Throwable $ex) { $done['cleanup'] = 'gabim: ' . $ex->getMessage(); }
    try { foreach (qa("SELECT id FROM users WHERE role='teacher' AND active=1") as $t) generate_alerts((int)$t['id']); $done['alerts'] = 'ok'; } catch (Throwable $ex) { $done['alerts'] = 'gabim: ' . $ex->getMessage(); }
    try { $done['journey'] = journey_weekly_reminders(); } catch (Throwable $ex) { $done['journey'] = 'gabim: ' . $ex->getMessage(); }
    if (setting('backup_auto') === '1') {
        try { $done['backup'] = backup_create('auto'); } catch (Throwable $ex) { $done['backup'] = 'gabim: ' . $ex->getMessage(); }
    }
    return $done;
}

function run_retention_cleanup(): string {
    $ev = max(30, setting_int('retention_events_days'));
    $au = max(90, setting_int('retention_audit_days'));
    $n1 = q('DELETE FROM interaction_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $ev . ' DAY)')->rowCount();
    $n2 = q('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $au . ' DAY)')->rowCount();
    $n3 = q('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)')->rowCount();
    $n4 = q('DELETE FROM alerts WHERE read_at IS NOT NULL AND read_at < DATE_SUB(NOW(), INTERVAL 180 DAY)')->rowCount();
    if ($n1 + $n2) audit('cleanup', '', "U fshinë $n1 klikime (> $ev ditë) dhe $n2 rreshta auditimi (> $au ditë)");
    return "$n1 klikime, $n2 auditim, $n3 tentativa hyrjeje, $n4 njoftime";
}
