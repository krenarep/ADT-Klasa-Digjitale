<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$tid = (int)$u['id'];
$classes = qa('SELECT * FROM classes WHERE teacher_id=? ORDER BY grade, name', [$tid]);
$classIds = array_map('intval', array_column($classes, 'id'));
$books = qa('SELECT b.id, b.title FROM books b WHERE b.is_global=1 OR b.uploaded_by=? OR b.school_id=? ORDER BY b.title', [$tid, $u['school_id']]);

$own = function (int $aid) use ($tid): ?array {
    return q1('SELECT a.*, c.name class_name, b.title book_title FROM assignments a JOIN classes c ON c.id=a.class_id JOIN books b ON b.id=a.book_id WHERE a.id=? AND c.teacher_id=?', [$aid, $tid]);
};

if (is_post()) {
    csrf_check();
    $a = post('action');
    try {
        if ($a === 'save') {
            $cid = posti('class_id'); $bid = posti('book_id');
            if (!in_array($cid, $classIds, true)) throw new RuntimeException('Zgjidhni klasën.');
            if (!teacher_can_use_book($u, $bid)) throw new RuntimeException('Zgjidhni librin.');
            $from = posti('pos_from'); $to = posti('pos_to');
            if ($from > $to) [$from, $to] = [$to, $from];
            if (!qv('SELECT 1 FROM book_pages WHERE book_id=? AND position=?', [$bid, $from]) || !qv('SELECT 1 FROM book_pages WHERE book_id=? AND position=?', [$bid, $to])) throw new RuntimeException('Zgjidhni faqet e detyrës.');
            $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('start_date')) ? post('start_date') : date('Y-m-d');
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('due_date')) ? post('due_date') : date('Y-m-d', strtotime('+7 day'));
            if ($due < $start) throw new RuntimeException('Afati nuk mund të jetë para datës së fillimit.');
            $title = trim((string)post('title')) ?: 'Detyrë';
            // sigurohu që libri është i caktuar në klasë
            q('INSERT IGNORE INTO class_books (class_id, book_id) VALUES (?,?)', [$cid, $bid]);
            if ($id = posti('id')) {
                if (!$own($id)) throw new RuntimeException('Detyra nuk u gjet.');
                q('UPDATE assignments SET class_id=?, book_id=?, title=?, instructions=?, pos_from=?, pos_to=?, start_date=?, due_date=? WHERE id=?',
                    [$cid, $bid, mb_substr($title, 0, 160), post('instructions') ?: null, $from, $to, $start, $due, $id]);
                q("DELETE FROM alerts WHERE dedupe_key = ?", ['overdue:' . $id]);
            } else {
                q('INSERT INTO assignments (class_id, book_id, title, instructions, pos_from, pos_to, start_date, due_date, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$cid, $bid, mb_substr($title, 0, 160), post('instructions') ?: null, $from, $to, $start, $due, $tid]);
                $id = (int)db()->lastInsertId();
            }
            audit('assignment', $title, 'klasa #' . $cid . ' · afati ' . $due);
            flash('ok', 'Detyra u ruajt. Nxënësit e shohin te "📌 Detyrat".');
            redirect('teacher/assignments.php?id=' . $id);
        } elseif ($a === 'delete') {
            if ($as = $own(posti('id'))) { q('DELETE FROM assignments WHERE id=?', [$as['id']]); audit('assignment', $as['title'], 'u fshi'); flash('ok', 'Detyra u fshi.'); }
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('teacher/assignments.php');
}

// të dhënat e faqeve për formularin (libër → faqet)
$pagesJson = [];
foreach ($books as $b) {
    $pagesJson[$b['id']] = array_map(fn($p) => [(int)$p['position'], ($p['page_number'] ? 'f. ' . $p['page_number'] . ' · ' : '') . mb_strimwidth((string)$p['title'], 0, 50, '…') . ((int)$p['is_activity'] ? ' ✍️' : '')],
        qa('SELECT position, page_number, title, is_activity FROM book_pages WHERE book_id=? ORDER BY position', [$b['id']]));
}

/* ---------- Detajet e një detyre ---------- */
if ($id = geti('id')) {
    $as = $own($id);
    if (!$as) { flash('err', 'Detyra nuk u gjet.'); redirect('teacher/assignments.php'); }
    $students = qa('SELECT s.id, s.full_name, s.avatar FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? ORDER BY s.full_name', [$as['class_id']]);
    $prog = assignment_progress($as, array_column($students, 'id'));
    $secs = assignment_sections($as);
    $done = count(array_filter($prog, fn($p) => $p['status'] === 'done'));
    layout_start('📌 ' . $as['title'], ['nav' => 'teacher/assignments.php', 'actions' => '<a class="btn" href="assignments.php">← Të gjitha</a><button class="btn btn-primary" data-open="dlgForm" data-fill-id="' . $as['id'] . '" data-fill-class-id="' . $as['class_id'] . '" data-fill-book-id="' . $as['book_id'] . '" data-fill-pos-from="' . $as['pos_from'] . '" data-fill-pos-to="' . $as['pos_to'] . '" data-fill-title="' . e($as['title']) . '" data-fill-instructions="' . e($as['instructions']) . '" data-fill-start-date="' . $as['start_date'] . '" data-fill-due-date="' . $as['due_date'] . '">✏️ Ndrysho</button>']);
    ?>
    <div class="stats">
      <div class="stat"><div class="lbl">🏷️ Klasa</div><div class="val" style="font-size:1.2rem"><?= e($as['class_name']) ?></div></div>
      <div class="stat"><div class="lbl">📚 Libri</div><div class="val" style="font-size:1rem"><?= e($as['book_title']) ?></div><div class="sub"><?= count($secs) ?> faqe · <?= count(array_filter($secs, fn($s) => (int)$s['is_activity'])) ?> ushtrime</div></div>
      <div class="stat"><div class="lbl">🗓️ Afati</div><div class="val" style="font-size:1.2rem"><?= e(date('d.m.Y', strtotime($as['due_date']))) ?></div><div class="sub"><?= e(due_label($as['due_date'])) ?></div></div>
      <div class="stat"><div class="lbl">✅ E kanë kryer</div><div class="val"><?= $done ?>/<?= count($students) ?></div><?= progress_bar(pct($done, count($students))) ?></div>
    </div>
    <?php if ($as['instructions']): ?><div class="card"><strong>Udhëzimet:</strong> <?= nl2br(e($as['instructions'])) ?></div><?php endif; ?>
    <div class="table-wrap"><table class="t">
      <thead><tr><th>Nxënësi</th><th>Statusi</th><th style="min-width:180px">Progresi</th><th class="num">Faqe</th><th class="num">Ushtrime</th><th>Aktiviteti i fundit</th><th></th></tr></thead>
      <tbody><?php foreach ($students as $s): $p = $prog[(int)$s['id']]; ?>
        <tr><td><?= e($s['avatar']) ?> <?= e($s['full_name']) ?></td><td><?= assignment_status_badge($p['status']) ?></td>
          <td><div class="pcell"><?= progress_bar($p['pct']) ?><span class="small"><?= $p['pct'] ?>%</span></div></td>
          <td class="num"><?= $p['pages'] ?>/<?= $p['page_total'] ?></td><td class="num"><?= $p['acts'] ?>/<?= $p['act_total'] ?></td>
          <td class="small"><?= e(ago($p['last'])) ?></td><td class="actions"><a class="btn btn-sm" href="student.php?id=<?= (int)$s['id'] ?>&book=<?= (int)$as['book_id'] ?>">📈</a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <details class="card mt"><summary><strong>📄 Faqet e përfshira (<?= count($secs) ?>)</strong></summary>
      <ul><?php foreach ($secs as $sx): ?><li><?= $sx['page_number'] ? 'f. ' . (int)$sx['page_number'] . ' · ' : '' ?><?= e($sx['title']) ?><?= (int)$sx['is_activity'] ? ' ✍️' : '' ?></li><?php endforeach; ?></ul></details>
    <form method="post" class="mt" data-confirm="Ta fshij këtë detyrë?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$as['id'] ?>"><button class="btn btn-danger btn-sm">🗑️ Fshi detyrën</button></form>
    <?php
} else {
    /* ---------- Lista ---------- */
    $rows = qa('SELECT a.*, c.name class_name, b.title book_title FROM assignments a JOIN classes c ON c.id=a.class_id JOIN books b ON b.id=a.book_id WHERE c.teacher_id=? ORDER BY a.due_date DESC', [$tid]);
    layout_start('Detyrat me afat', ['actions' => $classes ? '<button class="btn btn-primary" data-open="dlgForm">＋ Detyrë e re</button>' : '']);
    ?>
    <p class="muted">Caktoni faqe dhe ushtrime me afat. Nxënësit i shohin te <strong>📌 Detyrat</strong>; ju shihni kush i ka kryer, dhe merrni njoftim automatik për ata që vonohen.</p>
    <div class="table-wrap"><table class="t">
      <thead><tr><th>Detyra</th><th>Klasa</th><th>Libri · faqet</th><th>Afati</th><th style="min-width:200px">Të kryera</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $as):
          $ids = array_map('intval', array_column(qa('SELECT student_id FROM class_students WHERE class_id=?', [$as['class_id']]), 'student_id'));
          $prog = assignment_progress($as, $ids);
          $done = count(array_filter($prog, fn($p) => $p['status'] === 'done'));
          $range = qa('SELECT page_number FROM book_pages WHERE book_id=? AND position IN (?,?) ORDER BY position', [$as['book_id'], $as['pos_from'], $as['pos_to']]);
          $late = $as['due_date'] < date('Y-m-d'); ?>
        <tr><td><a href="?id=<?= (int)$as['id'] ?>"><strong><?= e($as['title']) ?></strong></a></td><td><?= e($as['class_name']) ?></td>
          <td class="small"><?= e($as['book_title']) ?> · f. <?= e(($range[0]['page_number'] ?? '?') . '–' . ($range[1]['page_number'] ?? $range[0]['page_number'] ?? '?')) ?></td>
          <td class="small"><span class="due <?= $late ? 'late' : '' ?>"><?= e(date('d.m.Y', strtotime($as['due_date']))) ?></span><br><span class="muted"><?= e(due_label($as['due_date'])) ?></span></td>
          <td><div class="pcell"><?= progress_bar(pct($done, count($ids))) ?><span class="small"><?= $done ?>/<?= count($ids) ?></span></div></td>
          <td class="actions"><a class="btn btn-sm" href="?id=<?= (int)$as['id'] ?>">Detaje →</a></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">📌 Nuk keni detyra ende. <?= $classes ? 'Krijoni të parën me "＋ Detyrë e re".' : 'Krijoni së pari një klasë.' ?></td></tr><?php endif; ?></tbody>
    </table></div>
    <?php
}
?>
<dialog id="dlgForm"><div class="dlg-body">
  <div class="dlg-head"><h2>📌 Detyrë</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form" id="asForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="grid-2">
      <label>Klasa *<select name="class_id" required><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <label>Libri *<select name="book_id" required><?php foreach ($books as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['title']) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="grid-2">
      <label>Nga faqja *<select name="pos_from" required></select></label>
      <label>Deri te faqja *<select name="pos_to" required></select></label>
    </div>
    <label>Titulli<input name="title" maxlength="160" placeholder="p.sh. Lexo dhe plotëso faqet 8–10"></label>
    <label>Udhëzimet për nxënësit<textarea name="instructions" rows="3" placeholder="p.sh. Lexoni me kujdes dhe plotësoni tabelën."></textarea></label>
    <div class="grid-2">
      <label>Fillon më<input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></label>
      <label>Afati *<input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+7 day')) ?>" required></label>
    </div>
    <button class="btn btn-primary">Ruaj detyrën</button>
  </form>
</div></dialog>
<script>
(function () {
  var PAGES = <?= json_encode($pagesJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var f = document.getElementById('asForm');
  function fill(keep) {
    var list = PAGES[f.book_id.value] || [];
    ['pos_from', 'pos_to'].forEach(function (n, i) {
      var sel = f[n], cur = keep ? sel.dataset.want || sel.value : '';
      sel.innerHTML = list.map(function (p) { return '<option value="' + p[0] + '">' + p[1].replace(/</g, '&lt;') + '</option>'; }).join('');
      if (cur) sel.value = cur; else if (list.length) sel.value = i ? list[Math.min(list.length - 1, 2)][0] : list[0][0];
    });
    autoTitle();
  }
  function autoTitle() {
    if (f.title.dataset.touched) return;
    var a = f.pos_from.selectedOptions[0], b = f.pos_to.selectedOptions[0];
    if (!a || !b) return;
    var pa = (a.textContent.match(/f\. (\d+)/) || [])[1], pb = (b.textContent.match(/f\. (\d+)/) || [])[1];
    f.title.placeholder = pa ? 'Faqet ' + pa + (pb && pb !== pa ? '–' + pb : '') : 'Detyrë';
  }
  f.book_id.addEventListener('change', function () { fill(false); });
  f.pos_from.addEventListener('change', autoTitle); f.pos_to.addEventListener('change', autoTitle);
  f.title.addEventListener('input', function () { f.title.dataset.touched = 1; });
  f.addEventListener('submit', function () { if (!f.title.value) f.title.value = f.title.placeholder; });
  // kur hapet për ndryshim, data-fill vendos vlerat; rindërto listat e faqeve
  document.querySelectorAll('[data-open="dlgForm"]').forEach(function (b) {
    b.addEventListener('click', function () {
      f.pos_from.dataset.want = b.dataset.fillPosFrom || ''; f.pos_to.dataset.want = b.dataset.fillPosTo || '';
      if (b.dataset.fillBookId) f.book_id.value = b.dataset.fillBookId;
      fill(true);
    });
  });
  fill(false);
})();
</script>
<?php layout_end();
