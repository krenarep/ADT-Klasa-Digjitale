<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$classes = qa('SELECT id, name FROM classes WHERE teacher_id=? ORDER BY grade, name', [$u['id']]);

if (is_post()) {
    csrf_check();
    $a = post('action');
    if ($a === 'upload') {
        $id = handle_book_upload($u);
        if ($id) audit('book.upload', (string)qv('SELECT title FROM books WHERE id=?', [$id]));
        // cakto automatikisht në klasat e zgjedhura
        if ($id) foreach ((array)($_POST['classes'] ?? []) as $cid) {
            if (teacher_owns_class((int)$u['id'], (int)$cid)) q('INSERT IGNORE INTO class_books (class_id, book_id) VALUES (?,?)', [(int)$cid, $id]);
        }
    } elseif ($a === 'assign') {
        $bid = posti('id');
        if (!teacher_can_use_book($u, $bid)) { flash('err', 'Libri nuk u gjet.'); redirect('teacher/books.php'); }
        $chosen = array_map('intval', (array)($_POST['classes'] ?? []));
        foreach ($classes as $c) {
            if (in_array((int)$c['id'], $chosen, true)) q('INSERT IGNORE INTO class_books (class_id, book_id) VALUES (?,?)', [$c['id'], $bid]);
            else q('DELETE FROM class_books WHERE class_id=? AND book_id=?', [$c['id'], $bid]);
        }
        flash('ok', 'Caktimi i librit në klasa u ruajt.');
    } elseif ($a === 'delete') {
        $bid = posti('id');
        if (qv('SELECT 1 FROM books WHERE id=? AND uploaded_by=?', [$bid, $u['id']])) { delete_book($bid); audit('book.delete', '#' . $bid); flash('ok', 'Libri u fshi.'); }
        else flash('err', 'Mund të fshini vetëm librat që keni ngarkuar vetë.');
    }
    redirect('teacher/books.php');
}

$myGrades = array_values(array_unique(array_map('intval', array_column(qa('SELECT grade FROM classes WHERE teacher_id=?', [$u['id']]), 'grade'))));
$showAll = get('all') === '1' || !$myGrades;
$gradeSql = $showAll ? '' : ' AND (b.grade IS NULL OR b.grade IN (' . implode(',', $myGrades) . '))';
$books = qa('SELECT b.*, u.full_name uploader FROM books b LEFT JOIN users u ON u.id=b.uploaded_by
             WHERE (b.is_global=1' . $gradeSql . ') OR b.uploaded_by=? OR b.school_id=? ORDER BY b.grade, b.title', [$u['id'], $u['school_id']]);
$assigned = [];
foreach (qa('SELECT cb.* FROM class_books cb JOIN classes c ON c.id=cb.class_id WHERE c.teacher_id=?', [$u['id']]) as $r) $assigned[(int)$r['book_id']][] = (int)$r['class_id'];
$classNames = array_column($classes, 'name', 'id');

layout_start('Librat digjitalë', ['actions' => '<button class="btn btn-primary" data-open="dlgUp">⬆️ Ngarko libër (SCORM / HTML)</button>']);
?>
<p class="muted">Librat e administratorit për nivelin e klasave tuaja (<?= $myGrades ? 'klasa ' . e(implode(', ', $myGrades)) : '—' ?>) shfaqen këtu dhe u caktohen automatikisht klasave tuaja. Mund të ngarkoni edhe librat tuaj nga <strong>ADT Studio</strong> (SCORM ose HTML, .zip). Nxënësit shohin vetëm librat e caktuar në klasën e tyre.
  <?php if ($myGrades): ?><a href="?all=<?= $showAll ? '0' : '1' ?>"><?= $showAll ? 'Shfaq vetëm librat e nivelit tim' : 'Shfaq librat e të gjitha niveleve' ?></a><?php endif; ?></p>
<div class="books-grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
<?php foreach ($books as $b): $cover = book_cover_url($b); $mine = (int)$b['uploaded_by'] === (int)$u['id']; ?>
  <section class="card" style="margin:0;display:flex;flex-direction:column;gap:10px">
    <div class="row" style="align-items:flex-start;flex-wrap:nowrap">
      <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" style="width:84px;height:110px;object-fit:cover;border-radius:8px;border:1px solid var(--line)"><?php else: ?><span style="font-size:3rem">📗</span><?php endif; ?>
      <div><h2 style="margin:0 0 4px"><?= e($b['title']) ?></h2>
        <div class="small muted"><?= strtoupper(e($b['package_type'])) ?> · <?= (int)$b['page_count'] ?> faqe · <?= (int)$b['activity_count'] ?> ushtrime<?= $b['grade'] ? ' · Klasa ' . (int)$b['grade'] : '' ?></div>
        <div class="small muted"><?= $b['is_global'] ? '🌍 Libër global' : ($mine ? '👤 Libri juaj' : '🏫 Libër i shkollës') ?></div>
        <?= book_provenance_html($b) ?></div>
    </div>
    <?php if ($b['description']): ?><p class="small" style="margin:0"><?= e($b['description']) ?></p><?php endif; ?>
    <div class="small"><strong>Klasat:</strong> <?= e(implode(', ', array_map(fn($id) => $classNames[$id] ?? '', $assigned[(int)$b['id']] ?? [])) ?: '—') ?></div>
    <div class="row" style="margin-top:auto">
      <button class="btn btn-sm btn-primary" data-open="dlgAssign<?= (int)$b['id'] ?>">🏷️ Cakto në klasa</button>
      <a class="btn btn-sm" href="<?= e(url('preview.php?id=' . $b['id'])) ?>" target="_blank" rel="noopener">👁️ Parashiko</a>
      <a class="btn btn-sm" href="progress.php?book=<?= (int)$b['id'] ?>">📈</a>
      <?php if ($mine): ?><form method="post" class="inline" data-confirm="Ta fshij librin dhe progresin e nxënësve?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm btn-danger">🗑️</button></form><?php endif; ?>
    </div>
    <dialog id="dlgAssign<?= (int)$b['id'] ?>"><div class="dlg-body">
      <div class="dlg-head"><h2>Cakto "<?= e($b['title']) ?>"</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <?php foreach ($classes as $c): ?>
          <label class="checkline"><input type="checkbox" name="classes[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $assigned[(int)$b['id']] ?? [], true) ? 'checked' : '' ?>> <?= e($c['name']) ?></label>
        <?php endforeach; if (!$classes): ?><p class="muted">Krijoni së pari një <a href="classes.php">klasë</a>.</p><?php endif; ?>
        <button class="btn btn-primary">Ruaj</button>
      </form>
    </div></dialog>
  </section>
<?php endforeach; ?>
</div>
<?php if (!$books): ?><div class="card empty">📚 Nuk ka libra ende. Ngarkoni librin e parë nga ADT Studio (SCORM ose HTML).</div><?php endif; ?>

<?php
// dialogu i ngarkimit + zgjedhja e klasave
ob_start(); include APP_ROOT . '/inc/partials/upload_dialog.php'; $dlg = ob_get_clean();
$cl = '';
foreach ($classes as $c) $cl .= '<label class="checkline"><input type="checkbox" name="classes[]" value="' . (int)$c['id'] . '" checked> ' . e($c['name']) . '</label>';
if ($cl) $dlg = str_replace('<button class="btn btn-primary" id="upBtn"', '<fieldset style="border:1px solid var(--line);border-radius:10px;padding:10px"><legend class="small">Cakto menjëherë në klasat</legend>' . $cl . '</fieldset><button class="btn btn-primary" id="upBtn"', $dlg);
echo $dlg;
layout_end();
