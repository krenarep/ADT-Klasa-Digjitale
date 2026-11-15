<?php
// Faqja publike për prindërit (link privat me token, pa fjalëkalim)
require __DIR__ . '/inc/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');
$t = preg_replace('/[^a-f0-9]/', '', strtolower(get('t')));
$s = (strlen($t) === 32 && setting('parent_links') === '1') ? q1("SELECT id, full_name FROM users WHERE parent_token=? AND role='student' AND active=1", [$t]) : null;
if (!$s) {
    usleep(300000);
    http_response_code(404);
    layout_start('Raporti për prindërit', ['body_class' => 'login-page']);
    echo '<div class="card" style="max-width:520px;margin:10vh auto;text-align:center"><div style="font-size:3rem">🔒</div><h2>Linku nuk është i vlefshëm</h2><p>Kërkoni mësuesit një link të ri.</p></div>';
    layout_end();
    exit;
}
$ym = valid_month(get('month'));
if (!isset($_SESSION['pv'][$t . $ym])) { $_SESSION['pv'][$t . $ym] = 1; q('INSERT INTO audit_log (action, target, details, ip) VALUES (?,?,?,?)', ['parent.view', $s['full_name'], month_label($ym), client_ip()]); }
?><!DOCTYPE html><html lang="sq"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Raporti i <?= e($s['full_name']) ?> · <?= e(month_label($ym)) ?></title><link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=4">
<style>@media print{.no-print{display:none}@page{size:A4 portrait;margin:10mm}}</style></head>
<body class="parent-page"><div class="wrap">
  <form class="no-print row mb" method="get"><input type="hidden" name="t" value="<?= e($t) ?>">
    <label class="small">Muaji: <select name="month" onchange="this.form.submit()" style="width:auto"><?= month_options($ym, 10) ?></select></label>
    <button type="button" class="btn" onclick="window.print()">🖨️ Printo</button>
    <a class="small" href="<?= e(url('privacy.php')) ?>" style="margin-left:auto">🔒 Privatësia</a>
  </form>
  <?= parent_report_html(parent_report_data((int)$s['id'], $ym)) ?>
  <p class="small muted no-print">Ky link është privat për prindin/kujdestarin. Mos e ndani me të tjerë. Nëse e keni humbur, kërkoni mësuesit një link të ri.</p>
</div></body></html>
