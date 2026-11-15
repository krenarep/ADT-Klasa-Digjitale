<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
if (!$u['school_id']) { layout_start('Klasat'); echo '<div class="flash flash-err">Llogaria juaj nuk është e lidhur me asnjë shkollë. Kontaktoni administratorin.</div>'; layout_end(); exit; }

if (is_post()) {
    csrf_check();
    $a = post('action');
    $id = posti('id');
    $grade = max(1, min(5, posti('grade', 3)));
    if ($a === 'add') {
        if (post('name') === '') { flash('err', 'Shkruani emrin e klasës.'); redirect('teacher/classes.php'); }
        q('INSERT INTO classes (school_id, teacher_id, name, grade, class_code, picture_login) VALUES (?,?,?,?,?,?)',
            [$u['school_id'], $u['id'], post('name'), $grade, make_class_code(), post('picture_login') ? 1 : 0]);
        $newId = (int)db()->lastInsertId();
        $n = auto_assign_class_books($newId);
        flash('ok', 'Klasa "' . post('name') . '" u krijua.' . ($n ? " $n libra të klasës $grade u shtuan automatikisht." : '') . ' Tani shtoni nxënësit.');
        redirect('teacher/students.php?class=' . $newId);
    }
    if (!teacher_owns_class((int)$u['id'], $id)) { flash('err', 'Klasa nuk u gjet.'); redirect('teacher/classes.php'); }
    if ($a === 'edit') {
        q('UPDATE classes SET name=?, grade=?, picture_login=? WHERE id=?', [post('name'), $grade, post('picture_login') ? 1 : 0, $id]);
        $n = auto_assign_class_books($id);
        flash('ok', 'Klasa u përditësua.' . ($n ? " $n libra të nivelit u shtuan." : ''));
    } elseif ($a === 'newcode') {
        q('UPDATE classes SET class_code=? WHERE id=?', [make_class_code(), $id]);
        flash('ok', 'U gjenerua kod i ri i klasës.');
    } elseif ($a === 'anonymize') {
        $key = anonymize_classes([$id]);
        if ($key) anon_send_key_csv($key, 'lista_celes_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string)qv('SELECT name FROM classes WHERE id=?', [$id])) . '_' . date('Ymd_His') . '.csv');
        flash('ok', 'Të gjithë nxënësit e kësaj klase janë tashmë të koduar.');
    } elseif ($a === 'delete') {
        q('DELETE FROM classes WHERE id=?', [$id]);
        flash('ok', 'Klasa u fshi. Llogaritë e nxënësve mbeten (mund t\'i regjistroni në një klasë tjetër).');
    }
    redirect('teacher/classes.php');
}

$rows = qa('SELECT c.*,
  (SELECT COUNT(*) FROM class_students WHERE class_id=c.id) students,
  (SELECT COUNT(*) FROM class_students x JOIN users y ON y.id=x.student_id WHERE x.class_id=c.id AND y.full_name NOT REGEXP \'^[A-Z]{3}-[A-Z0-9]{1,4}-[0-9]{2,3}-[0-9]{2,3}$\') named,
  (SELECT GROUP_CONCAT(b.title SEPARATOR \', \') FROM class_books cb JOIN books b ON b.id=cb.book_id WHERE cb.class_id=c.id) books
  FROM classes c WHERE c.teacher_id=? ORDER BY c.grade, c.name', [$u['id']]);

layout_start('Klasat e mia', ['actions' => '<button class="btn btn-primary" data-open="dlgAdd">＋ Krijo klasë</button>']);
?>
<div class="grid-3">
<?php foreach ($rows as $c): ?>
  <section class="card">
    <div class="row-between"><h2>🏷️ <?= e($c['name']) ?></h2><span class="badge">Klasa <?= (int)$c['grade'] ?></span></div>
    <p>Kodi i klasës: <code style="font-size:1.3rem;letter-spacing:.15em"><?= e($c['class_code']) ?></code>
      <?= !picture_login_enabled() ? '' : ($c['picture_login'] ? '<br><span class="small muted">🖼️ Hyrja me fotografi aktive</span>' : '<br><span class="small muted">Hyrja me fotografi joaktive</span>') ?></p>
    <p>🧒 <strong><?= (int)$c['students'] ?></strong> nxënës<br><span class="small muted">📚 <?= e($c['books'] ?: 'Asnjë libër i caktuar') ?></span></p>
    <div class="row">
      <a class="btn btn-sm btn-primary" href="students.php?class=<?= (int)$c['id'] ?>">🧒 Nxënësit</a>
      <a class="btn btn-sm" href="books.php">📚 Cakto libra</a>
      <a class="btn btn-sm" href="progress.php?class=<?= (int)$c['id'] ?>">📈 Progresi</a>
      <a class="btn btn-sm" href="cards.php?class=<?= (int)$c['id'] ?>" target="_blank">🖨️ Kartelat</a>
    </div>
    <div class="row mt">
      <button class="btn btn-sm btn-ghost" data-open="dlgEdit" data-fill-id="<?= (int)$c['id'] ?>" data-fill-name="<?= e($c['name']) ?>" data-fill-grade="<?= (int)$c['grade'] ?>" data-fill-picture-login="<?= (int)$c['picture_login'] ?>">✏️ Ndrysho</button>
      <?php if ((int)$c['named'] > 0): ?><form method="post" class="inline" data-confirm="Të zëvendësohen emrat e <?= (int)$c['named'] ?> nxënësve me kode (p.sh. PRI-IQ-01-05)? Do të shkarkohet lista-çelës me emrat e vërtetë — RUAJENI, sepse emrat nuk ruhen më në sistem."><?= csrf_field() ?><input type="hidden" name="action" value="anonymize"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm">🔒 Anonimizo (<?= (int)$c['named'] ?>)</button></form><?php endif; ?>
      <form method="post" class="inline" data-confirm="Të gjenerohet kod i ri? Kodi i vjetër nuk do të funksionojë më."><?= csrf_field() ?><input type="hidden" name="action" value="newcode"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-ghost">🔄 Kod i ri</button></form>
      <form method="post" class="inline" data-confirm="Ta fshij klasën <?= e($c['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger">🗑️</button></form>
    </div>
  </section>
<?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="card empty">🏷️ Nuk keni klasa ende. <button class="btn btn-primary" data-open="dlgAdd">＋ Krijo klasën e parë</button></div><?php endif; ?>

<?php foreach (['dlgAdd' => ['add', 'Krijo klasë'], 'dlgEdit' => ['edit', 'Ndrysho klasën']] as $dlg => [$act, $ttl]): ?>
<dialog id="<?= $dlg ?>"><div class="dlg-body">
  <div class="dlg-head"><h2><?= $ttl ?></h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id">
    <label>Emri i klasës *<input name="name" required placeholder="p.sh. III-a"></label>
    <label>Klasa (viti)<select name="grade"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></label>
    <?php if (picture_login_enabled()): ?><label class="checkline"><input type="checkbox" name="picture_login" value="1" checked> Lejo hyrjen me fotografi (kod klase + 3 foto)</label><?php else: ?><input type="hidden" name="picture_login" value="1"><?php endif; ?>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>
<?php endforeach; ?>
<?php layout_end();
