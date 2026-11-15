<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    $a = post('action');
    if ($a === 'upload') {
        if ($bid = handle_book_upload($u)) {
            audit('book.upload', (string)qv('SELECT title FROM books WHERE id=?', [$bid]));
            if ($n = auto_assign_book_by_grade($bid)) flash('ok', "📚 Libri u shfaq automatikisht te $n klasa të këtij niveli.");
        }
    } elseif ($a === 'global') {
        q('UPDATE books SET is_global = 1 - is_global, updated_by_name=?, updated_at=NOW() WHERE id=?', [uploader_label($u), posti('id')]);
        $n = auto_assign_book_by_grade(posti('id'));
        flash('ok', 'Qasja e librit u ndryshua.' . ($n ? " U shfaq te $n klasa të nivelit." : ''));
    } elseif ($a === 'edit') {
        q('UPDATE books SET title=?, description=?, grade=?, updated_by_name=?, updated_at=NOW() WHERE id=?', [post('title'), post('description') ?: null, posti('grade') ?: null, uploader_label($u), posti('id')]);
        audit('book.upload', post('title'), 'Të dhënat e librit u ndryshuan');
        $n = auto_assign_book_by_grade(posti('id'));
        flash('ok', 'Libri u përditësua.' . ($n ? " U shfaq te $n klasa të reja të nivelit." : ''));
    } elseif ($a === 'autoassign') {
        set_setting('auto_assign_grade', post('on') ? '1' : '0');
        $n = post('on') ? auto_assign_all_by_grade() : 0;
        flash('ok', post('on') ? "Caktimi automatik sipas nivelit u aktivizua ($n caktime të reja)." : 'Caktimi automatik sipas nivelit u çaktivizua.');
    } elseif ($a === 'delete') {
        delete_book(posti('id'));
        audit('book.delete', '#' . posti('id'));
        flash('ok', 'Libri dhe të dhënat e tij u fshinë.');
    }
    redirect('admin/books.php');
}

$rows = qa("SELECT b.*, u.full_name uploader, s.name school_name,
  (SELECT COUNT(*) FROM class_books WHERE book_id=b.id) classes,
  (SELECT COUNT(DISTINCT student_id) FROM page_views WHERE book_id=b.id) readers,
  (SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE book_id=b.id) secs,
  (SELECT COUNT(*) FROM quiz_attempts WHERE book_id=b.id) attempts
  FROM books b LEFT JOIN users u ON u.id=b.uploaded_by LEFT JOIN schools s ON s.id=b.school_id ORDER BY b.created_at DESC");

layout_start('Librat digjitalë', ['actions' => '<button class="btn btn-primary" data-open="dlgUp">⬆️ Ngarko libër (SCORM / HTML)</button>']);
?>
<p class="muted">Librat e ngarkuar nga administratori janë <strong>globalë</strong>. Kur libri ka <strong>klasën (nivelin)</strong> e caktuar, ai u shfaqet automatikisht të gjitha klasave të atij niveli te të gjithë mësuesit — edhe klasave që krijohen më vonë. Mësuesit mund të ngarkojnë edhe librat e tyre për shkollën e tyre.</p>
<form method="post" class="row mb"><?= csrf_field() ?><input type="hidden" name="action" value="autoassign">
  <label class="checkline" style="margin:0"><input type="checkbox" name="on" value="1" <?= setting('auto_assign_grade') === '1' ? 'checked' : '' ?> onchange="this.form.submit()"> Shfaqi librat globalë automatikisht te klasat e nivelit përkatës</label>
  <?php if (setting('auto_assign_grade') === '1'): ?><button class="btn btn-sm" name="on" value="1">🔄 Cakto tani të gjithë librat sipas nivelit</button><?php endif; ?>
</form>
<div class="table-wrap"><table class="t">
  <thead><tr><th></th><th>Libri</th><th>Ngarkuar nga</th><th>Data e ngarkimit</th><th>Lloji</th><th class="num">Faqe</th><th class="num">Ushtrime</th><th>Qasja</th><th class="num">Klasa</th><th class="num">Lexues</th><th class="num">Koha</th><th class="num">Tentativa</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): $cover = book_cover_url($b); ?>
    <tr>
      <td style="width:56px"><?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px"><?php else: ?><span style="font-size:2rem">📗</span><?php endif; ?></td>
      <td><strong><?= e($b['title']) ?></strong><div class="small muted"><?= $b['grade'] ? 'Klasa ' . (int)$b['grade'] : '' ?></div>
        <?php if (!empty($b['updated_at'])): ?><div class="small muted">✏️ <?= e($b['updated_by_name'] ?: '—') ?> · <?= e(fmt_dt($b['updated_at'])) ?></div><?php endif; ?></td>
      <td class="small"><?= e(($b['uploaded_by_name'] ?? null) ?: ($b['uploader'] ?? '—')) ?></td>
      <td class="small" style="white-space:nowrap"><?= e(fmt_dt($b['created_at'])) ?></td>
      <td><span class="badge"><?= e(strtoupper($b['package_type'])) ?></span></td>
      <td class="num"><?= (int)$b['page_count'] ?></td>
      <td class="num"><?= (int)$b['activity_count'] ?></td>
      <td><?= $b['is_global'] ? '🌍 Global' : '🏫 ' . e($b['school_name'] ?? 'Privat') ?></td>
      <td class="num"><?= (int)$b['classes'] ?></td>
      <td class="num"><?= (int)$b['readers'] ?></td>
      <td class="num"><?= e(fmt_duration((int)$b['secs'])) ?></td>
      <td class="num"><?= (int)$b['attempts'] ?></td>
      <td class="actions">
        <a class="btn btn-sm" href="<?= e(url('preview.php?id=' . $b['id'])) ?>" target="_blank" rel="noopener" title="Parashiko">👁️</a>
        <button class="btn btn-sm" data-open="dlgEdit" data-fill-id="<?= (int)$b['id'] ?>" data-fill-title="<?= e($b['title']) ?>" data-fill-description="<?= e($b['description']) ?>" data-fill-grade="<?= (int)$b['grade'] ?>">✏️</button>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="global"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm" title="<?= $b['is_global'] ? 'Bëje privat' : 'Bëje global' ?>"><?= $b['is_global'] ? '🔒' : '🌍' ?></button></form>
        <form method="post" class="inline" data-confirm="Ta fshij librin dhe të gjithë progresin e nxënësve në të?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm btn-danger">🗑️</button></form>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="13" class="empty">📚 Nuk ka libra ende. Ngarkoni një libër SCORM ose paketë HTML të ADT Studio (.zip).</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php include APP_ROOT . '/inc/partials/upload_dialog.php'; ?>
<dialog id="dlgEdit"><div class="dlg-body">
  <div class="dlg-head"><h2>Ndrysho librin</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id">
    <label>Titulli<input name="title" required></label>
    <label>Klasa<select name="grade"><option value="0">—</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></label>
    <label>Përshkrimi<textarea name="description"></textarea></label>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>
<?php layout_end();
