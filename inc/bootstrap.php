<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$cfgFile = APP_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        $rel = trim(str_replace('\\', '/', substr(dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)) ?: '', strlen(APP_ROOT))), '/');
        header('Location: ' . str_repeat('../', $rel === '' ? 0 : substr_count($rel, '/') + 1) . 'install/install.php');
        exit;
    }
    $cfgFile = APP_ROOT . '/config.sample.php';
}
$GLOBALS['CFG'] = require $cfgFile;

/**
 * Zbulon automatikisht nën-dosjen e aplikacionit (p.sh. "/klasa-digjitale" në XAMPP),
 * që ridrejtimet të funksionojnë edhe kur "base_url" në config.php mungon ose është gabim.
 */
function kd_detect_base_url(): ?string {
    if (PHP_SAPI === 'cli' || empty($_SERVER['SCRIPT_NAME']) || empty($_SERVER['SCRIPT_FILENAME'])) return null;
    $script = str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']);
    $file = str_replace('\\', '/', (string)(realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']));
    $root = rtrim(str_replace('\\', '/', (string)(realpath(APP_ROOT) ?: APP_ROOT)), '/');
    // 1) nga rruga e skriptit: /klasa-digjitale/admin/index.php − admin/index.php
    if (stripos($file, $root . '/') === 0) {
        $rel = substr($file, strlen($root));                   // p.sh. "/admin/index.php"
        if (strcasecmp(substr($script, -strlen($rel)), $rel) === 0) return rtrim(substr($script, 0, strlen($script) - strlen($rel)), '/');
    }
    // 2) nga DOCUMENT_ROOT: C:/xampp/htdocs + /klasa-digjitale
    $doc = rtrim(str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? ''))), '/');
    if ($doc !== '' && stripos($root, $doc) === 0) return rtrim(substr($root, strlen($doc)), '/');
    // 3) nga dosja e skriptit (aplikacioni ka vetëm një nivel nën-dosjesh: admin/, teacher/, student/, api/…)
    $dir = rtrim(dirname($script), '/');
    if (preg_match('~/(admin|teacher|student|api|install|tools)$~', $dir)) $dir = dirname($dir);
    return rtrim($dir, '/\\.');
}
// Nëse config.php ka base_url, përdoret ai; përndryshe zbulohet automatikisht
if (trim((string)($GLOBALS['CFG']['base_url'] ?? ''), '/ ') === '') {
    $kdBase = kd_detect_base_url();
    if ($kdBase !== null) $GLOBALS['CFG']['base_url'] = $kdBase;
}
$GLOBALS['CFG']['base_url'] = rtrim((string)($GLOBALS['CFG']['base_url'] ?? ''), '/');

date_default_timezone_set($GLOBALS['CFG']['timezone'] ?? 'Europe/Belgrade');

if (session_status() === PHP_SESSION_NONE) {
    session_name('KDSESS2');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => ($GLOBALS['CFG']['base_url'] ?: '') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/secret.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/books.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/anonymize.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/assignments.php';
require_once __DIR__ . '/badges.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/parent_report.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/docx.php';
require_once __DIR__ . '/training_report.php';
require_once __DIR__ . '/journey.php';
require_once __DIR__ . '/teacher_cert.php';
require_once __DIR__ . '/i18n.php';

// Gjuha e ndërfaqes: ?lang=xx ndërron gjuhën; faqja përkthehet në dalje
i18n_handle_switch();
if (i18n_should_filter() && (current_lang() !== I18N_SOURCE || !empty($_SESSION['i18n_collect']))) ob_start('i18n_filter');

// Nëse baza ka nevojë për përditësim, administratori dërgohet automatikisht te faqja e përditësimit
if (PHP_SAPI !== 'cli' && !empty($_SESSION['uid']) && ($_SESSION['role'] ?? '') === 'admin'
    && !str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/perditeso_db.php') && !str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/logout.php')
    && db_pending_migrations()) {
    header('Location: ' . url('admin/perditeso_db.php'));
    exit;
}

// Kodimi i fjalëkalimeve fillestare të nxënësve (një herë, pas përditësimit)
if (PHP_SAPI !== 'cli' && in_array($_SESSION['role'] ?? '', ['admin', 'teacher'], true) && !db_pending_migrations() && setting('hints_encrypted') !== '1') {
    try { encrypt_existing_hints(); set_setting('hints_encrypted', '1'); } catch (Throwable) {}
}

// Caktimi një herë i librave globalë te klasat e nivelit përkatës (pas përditësimit)
if (PHP_SAPI !== 'cli' && in_array($_SESSION['role'] ?? '', ['admin', 'teacher'], true) && !db_pending_migrations() && setting('grade_autoassign_v1') !== '1') {
    try { auto_assign_all_by_grade(); set_setting('grade_autoassign_v1', '1'); } catch (Throwable) {}
}

// Anonimizimi: nëse dikush ka rishkruar emrin e një nxënësi të koduar, rikthe kodin
if (PHP_SAPI !== 'cli' && in_array($_SESSION['role'] ?? '', ['admin', 'teacher'], true) && !db_pending_migrations()) {
    try { anon_restore_codes(); } catch (Throwable) {}
}

// Shumëgjuhësia: tekstet e platformës dhe paketat e përkthimeve (një herë pas çdo përditësimi)
if (PHP_SAPI !== 'cli' && !db_pending_migrations()) { try { i18n_auto_setup(); } catch (Throwable) {} }

// Detyrat e përditshme (paralajmërimet, fshirja automatike, kopja rezervë) — një herë në ditë, nga stafi
if (PHP_SAPI !== 'cli' && in_array($_SESSION['role'] ?? '', ['admin', 'teacher'], true) && !db_pending_migrations()) {
    try { run_daily_tasks(); } catch (Throwable) { /* mos e ndal faqen */ }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
