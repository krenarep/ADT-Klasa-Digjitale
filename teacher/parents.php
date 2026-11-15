<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$classes = qa('SELECT * FROM classes WHERE teacher_id=? ORDER BY grade, name', [$u['id']]);
$classId = geti('class', $classes ? (int)$classes[0]['id'] : 0);
if ($classId && !teacher_owns_class((int)$u['id'], $classId)) $classId = 0;
$enabled = setting('parent_links') === '1';

if (is_post() && $classId) {
    csrf_check();
    $a = post('action');
    $sid = posti('id');
    if ($a === 'all' && $enabled) {
        $n = 0;
        foreach (qa('SELECT s.id FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? AND s.parent_token IS NULL', [$classId]) as $r) {
            q('UPDATE users SET parent_token=? WHERE id=?', [bin2hex(random_bytes(16)), $r['id']]); $n++;
        }
        audit('parent.link', 'klasa #' . $classId, "$n linqe të reja");
        flash('ok', "U krijuan $n linqe për prindër.");
    } elseif (in_array($a, ['new', 'revoke'], true) && teacher_has_student((int)$u['id'], $sid)) {
        q('UPDATE users SET parent_token=? WHERE id=?', [$a === 'new' && $enabled ? bin2hex(random_bytes(16)) : null, $sid]);
        audit('parent.link', '#' . $sid, $a === 'new' ? 'Link i ri' : 'Linku u çaktivizua');
        flash('ok', $a === 'new' ? 'Linku u krijua.' : 'Linku u çaktivizua.');
    }
    redirect('teacher/parents.php?class=' . $classId);
}

$rows = $classId ? qa('SELECT s.* FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? AND s.active=1 ORDER BY s.full_name', [$classId]) : [];
$class = null; foreach ($classes as $c) if ((int)$c['id'] === $classId) $class = $c;

if (get('cards') && $class) {
    layout_start('Kartelat për prindër · ' . $class['name'], ['actions' => '<button class="btn btn-primary" onclick="window.print()">🖨️ Printo</button>']);
    echo '<div class="cards-print">';
    foreach ($rows as $s) {
        if (!$s['parent_token']) continue;
        echo '<div class="login-cardp"><h3>👪 Për prindin e ' . e($s['full_name']) . '</h3>'
            . '<p class="small" style="margin:4px 0">Shikoni çdo muaj sa lexon fëmija juaj, ushtrimet, detyrat dhe komentet e mësuesit/es.</p>'
            . '<p style="margin:6px 0;word-break:break-all"><strong>' . e(parent_link($s)) . '</strong></p>'
            . '<p class="small muted" style="margin:0">Mësuesi/ja: ' . e($u['full_name']) . ($u['email'] ? ' · ' . e($u['email']) : '') . '. Linku është privat — mos e ndani.</p></div>';
    }
    echo '</div>';
    layout_end();
    exit;
}

$sel = '<form method="get"><select name="class" onchange="this.form.submit()" aria-label="Klasa">';
foreach ($classes as $c) $sel .= '<option value="' . $c['id'] . '"' . ($classId === (int)$c['id'] ? ' selected' : '') . '>🏷️ ' . e($c['name']) . '</option>';
$sel .= '</select></form>';
if ($class) $sel .= '<a class="btn" target="_blank" href="parent_report.php?class=' . $classId . '&month=' . date('Y-m') . '">📄 Raportet e muajit (të gjithë)</a>';
layout_start('Prindërit', ['actions' => $sel]);
?>
<p class="muted">Çdo muaj mund t'u jepni prindërve një <strong>raport të thjeshtë</strong> (koha e leximit, ushtrimet, detyrat, arritjet, komentet tuaja dhe këshilla si të ndihmojnë). Mund ta printoni, ose t'u jepni një <strong>link privat</strong> ku e shohin vetë, pa fjalëkalim.</p>
<?php if (!$enabled): ?><div class="flash flash-info">ℹ️ Linqet për prindër janë çaktivizuar nga administratori. Raportet mund t'i printoni.</div><?php endif; ?>
<?php if ($class): ?>
<div class="row mb">
  <?php if ($enabled): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="all"><button class="btn">🔗 Krijo linqe për të gjithë</button></form>
  <a class="btn" href="?class=<?= $classId ?>&cards=1" target="_blank">🖨️ Kartelat me linqe për prindër</a><?php endif; ?>
</div>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Nxënësi</th><th>Linku i prindit</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $s): ?>
    <tr><td><?= e($s['avatar']) ?> <?= e($s['full_name']) ?></td>
      <td class="small"><?= $s['parent_token'] && $enabled ? '<input readonly value="' . e(parent_link($s)) . '" onclick="this.select()" style="font-size:.8rem;min-height:30px;padding:3px 8px">' : '<span class="muted">—</span>' ?></td>
      <td class="actions">
        <a class="btn btn-sm" target="_blank" href="parent_report.php?student=<?= (int)$s['id'] ?>&month=<?= date('Y-m') ?>">📄 Raporti</a>
        <?php if ($enabled): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="new"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm" title="<?= $s['parent_token'] ? 'Link i ri (i vjetri anulohet)' : 'Krijo link' ?>"><?= $s['parent_token'] ? '🔄' : '🔗' ?></button></form><?php endif; ?>
        <?php if ($s['parent_token']): ?><form method="post" class="inline" data-confirm="Ta çaktivizoj linkun?"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger" title="Çaktivizo">⛔</button></form><?php endif; ?>
      </td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php else: ?><div class="card empty">Krijoni së pari një klasë.</div><?php endif; ?>
<?php layout_end();
