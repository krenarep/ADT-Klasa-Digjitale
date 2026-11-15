<?php
// Stakeholder report (English): teacher training → classroom use → student inclusion → progress with ADT Studio books
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

$defaultBg = "Klasa Digjitale is a web platform that brings accessible digital textbooks produced with ADT Studio into primary classrooms (grades 3–5). Teachers enrol their classes, assign the digitised books and follow each pupil's reading time, exercises and progress; pupils read the books with built-in accessibility features such as read-aloud, image descriptions, adjustable text and, where available, sign language and easy-read versions.\n\nThe programme trains teachers to use the platform and the ADT textbooks in their daily teaching, and supports them to include all of their pupils — girls and boys, and children with special educational needs — in learning with the digital books. This report summarises the training delivered, how trained teachers have adopted the platform, how their pupils have been included, and the progress pupils have made.";

$first = (string)(qv('SELECT MIN(start_date) FROM trainings') ?: date('Y-m-01', strtotime('-2 month')));
$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null;
$o = [
    'from' => $valid(get('from')) ?? $first,
    'to' => $valid(get('to')) ?? date('Y-m-d'),
    'training_id' => geti('training'),
    'komuna' => valid_municipality(get('komuna')) ? get('komuna') : '',
    'org' => get('org', null) ?? setting('report_org') ?: cfg('app_name'),
    'author' => get('author', null) ?? setting('report_author') ?: $u['full_name'],
    'background' => get('background', null) ?? setting('report_background') ?: $defaultBg,
    'recs_extra' => (string)get('recs', ''),
];
if ($o['from'] > $o['to']) [$o['from'], $o['to']] = [$o['to'], $o['from']];
if (isset($_GET['org'])) { set_setting('report_org', mb_substr($o['org'], 0, 200)); set_setting('report_author', mb_substr($o['author'], 0, 200)); set_setting('report_background', mb_substr($o['background'], 0, 5000)); }

$fmt = get('format');
if ($fmt === 'docx' || $fmt === 'xlsx') {
    $R = training_report_build($o);
    audit('export', 'Training & ADT adoption report (' . strtoupper($fmt) . ')', $o['from'] . ' – ' . $o['to'] . ($o['komuna'] ? ' · ' . $o['komuna'] : ''));
    $name = 'Teacher_Training_ADT_Report_' . ($o['komuna'] ? translit($o['komuna']) . '_' : '') . $o['from'] . '_' . $o['to'] . '.' . $fmt;
    $fmt === 'docx' ? training_report_docx($R, $name) : training_report_xlsx($R, $name);
}
$R = training_report_build($o);
$sessions = qa('SELECT id, title, start_date FROM trainings ORDER BY start_date DESC');
$q = http_build_query(['from' => $o['from'], 'to' => $o['to'], 'training' => $o['training_id'], 'komuna' => $o['komuna'], 'org' => $o['org'], 'author' => $o['author'], 'background' => $o['background'], 'recs' => $o['recs_extra']]);

layout_start('Stakeholder report — Teacher training & ADT', ['actions' => '<button class="btn" onclick="window.print()">🖨️ Print / PDF</button><a class="btn" href="?' . e($q) . '&format=xlsx">⬇️ Excel</a><a class="btn btn-primary" href="?' . e($q) . '&format=docx">⬇️ Word (.docx)</a>']);
?>
<?php if (!$sessions): ?><div class="flash flash-info no-print">ℹ️ No training sessions recorded yet. Add them under <a href="trainings.php">🎓 Teacher trainings</a> so the report can link training to classroom results.</div><?php endif; ?>
<details class="card no-print" <?= isset($_GET['org']) ? '' : 'open' ?>><summary><strong>⚙️ Report settings</strong> <span class="muted small">— period, scope and the text that appears in the document</span></summary>
  <form method="get" class="form mt">
    <div class="form-inline">
      <label>From<input type="date" name="from" value="<?= e($o['from']) ?>"></label>
      <label>To<input type="date" name="to" value="<?= e($o['to']) ?>"></label>
      <label>Training session<select name="training"><option value="0">All sessions</option><?php foreach ($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $o['training_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['title']) ?> (<?= e(date('d.m.Y', strtotime($s['start_date']))) ?>)</option><?php endforeach; ?></select></label>
      <label>Municipality<select name="komuna"><?= municipality_options($o['komuna'] ?: null, true, 'All municipalities') ?></select></label>
    </div>
    <div class="grid-2"><label>Organisation / programme name<input name="org" value="<?= e($o['org']) ?>"></label><label>Prepared by<input name="author" value="<?= e($o['author']) ?>"></label></div>
    <label>Background (section 2)<textarea name="background" rows="6"><?= e($o['background']) ?></textarea></label>
    <label>Additional recommendations <span class="help">(one per line — added after the automatic ones)</span><textarea name="recs" rows="3"><?= e($o['recs_extra']) ?></textarea></label>
    <div><button class="btn btn-primary">🔄 Update preview</button></div>
  </form>
</details>
<?= training_report_html($R) ?>
<?php layout_end();
