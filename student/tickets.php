<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student');
$sid = (int)$u['id'];
$teachers = qa('SELECT DISTINCT t.id, t.full_name FROM users t JOIN classes c ON c.teacher_id=t.id JOIN class_students cs ON cs.class_id=c.id WHERE cs.student_id=? ORDER BY t.full_name', [$sid]);
$books = qa('SELECT DISTINCT b.id, b.title FROM books b JOIN class_books cb ON cb.book_id=b.id JOIN class_students cs ON cs.class_id=cb.class_id WHERE cs.student_id=? ORDER BY b.title', [$sid]);

if (is_post()) {
    csrf_check();
    if (post('action') === 'new') {
        $tid = posti('teacher_id');
        if (!in_array($tid, array_map('intval', array_column($teachers, 'id')), true)) { flash('err', 'Zgjidh mësuesin.'); redirect('student/tickets.php?new=1'); }
        $bid = posti('book_id');
        if ($bid && !student_has_book($sid, $bid)) $bid = 0;
        try {
            $cat = post('category');
            $id = ticket_create($u, 'teacher', $tid, post('kind') ?: 'problem', $cat, ticket_categories()[$cat][1] ?? 'Problem', (string)post('message'), $bid ?: null);
            flash('ok', 'Mesazhi u dërgua te mësuesi! 📨');
            redirect('student/tickets.php?id=' . $id);
        } catch (Throwable $ex) {
            flash('err', $ex->getMessage());
            redirect('student/tickets.php?new=1');
        }
    }
    ticket_handle_post($u, 'student/tickets.php');
}

if ($id = geti('id')) {
    $t = ticket_get($id);
    if (!$t || !ticket_can_view($u, $t)) { flash('err', 'Nuk u gjet.'); redirect('student/tickets.php'); }
    ticket_mark_read($u, $t);
    $msgs = ticket_messages($id);
    layout_start('Mesazhi im');
    ?>
    <p><a class="btn" href="tickets.php">← Mesazhet e mia</a></p>
    <section class="kid-card">
      <div class="row-between"><h2><?= ticket_categories()[$t['category']][0] ?? '💬' ?> <?= e($t['subject']) ?></h2><?= status_badge($t['status']) ?></div>
      <p class="small muted">Për: <?= e($t['assignee_name'] ?? 'mësuesin') ?><?= $t['book_title'] ? ' · 📚 ' . e($t['book_title']) : '' ?><?= $t['page_number'] ? ' · faqja ' . (int)$t['page_number'] : '' ?></p>
      <div class="thread">
        <?php foreach ($msgs as $m): ?>
          <div class="msg <?= (int)$m['user_id'] === $sid ? 'mine' : '' ?>">
            <div class="meta"><?= e($m['role'] === 'student' ? ($m['avatar'] ?? '🙂') : '👩‍🏫') ?> <strong><?= (int)$m['user_id'] === $sid ? 'Unë' : e($m['full_name']) ?></strong> · <?= e(ago($m['created_at'])) ?></div>
            <p><?= e($m['message']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" class="form mt"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <label>Shkruaj përsëri<textarea name="message" rows="3"></textarea></label>
        <div class="row">
          <?php if ($t['status'] !== 'closed'): ?><label class="checkline"><input type="checkbox" name="status" value="closed"> 👍 U zgjidh, faleminderit!</label><?php endif; ?>
          <button class="btn btn-primary btn-lg">📨 Dërgo</button>
        </div>
      </form>
    </section>
    <?php
    layout_end();
    exit;
}

$rows = qa(TICKET_LIST_SQL . ' WHERE t.created_by=? ORDER BY t.updated_at DESC', [$sid]);
layout_start('Më ndihmo');
?>
<section class="kid-hello" style="background:linear-gradient(120deg,#d6f5f1,#e8e1ff)">
  <span class="big" aria-hidden="true">🙋</span>
  <div><h1>Më ndihmo</h1><p>Ke një problem ose pyetje? Shkruaji mësuesit. Nuk ka pyetje të gabuar! 💛</p></div>
</section>

<?php if (!$teachers): ?>
  <div class="kid-card">Nuk je ende në asnjë klasë. Fol me mësuesin.</div>
<?php else: ?>
<section class="kid-card" id="new">
  <h2>✏️ Mesazh i ri</h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="new">
    <p style="margin:0;font-weight:700">1. Çfarë ndodhi?</p>
    <div class="cat-grid">
      <?php $i = 0; foreach (['not_working', 'dont_understand', 'login', 'content', 'other'] as $k): [$ico, $lbl] = ticket_categories()[$k]; ?>
        <label class="cat-opt"><input type="radio" name="category" value="<?= $k ?>" <?= $i++ === 0 ? 'checked' : '' ?>><span><b aria-hidden="true"><?= $ico ?></b><?= e($lbl) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="grid-2">
      <label>2. Për cilin mësues?<select name="teacher_id"><?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?></select></label>
      <label>Për cilin libër? <span class="help">(nëse ka)</span><select name="book_id"><option value="0">—</option><?php foreach ($books as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['title']) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>3. Shkruaj mesazhin<textarea name="message" required rows="4" placeholder="Shkruaj këtu…"></textarea></label>
    <div class="row"><button class="btn btn-primary btn-lg">📨 Dërgoja mësuesit</button>
      <button type="button" class="btn btn-lg" data-speak="Zgjidh çfarë ndodhi, zgjidh mësuesin, shkruaj mesazhin dhe shtyp dërgo.">🔊 Dëgjo udhëzimin</button></div>
  </form>
</section>
<?php endif; ?>

<section class="kid-card">
  <h2>📬 Mesazhet e mia</h2>
  <?php foreach ($rows as $t): ?>
    <a href="?id=<?= (int)$t['id'] ?>" class="row-between" style="text-decoration:none;color:inherit;padding:12px;border-radius:14px;border:2px solid #ffe2b8;margin-bottom:10px">
      <span><?= $t['unread_creator'] ? '🔵 ' : '' ?><?= ticket_categories()[$t['category']][0] ?? '💬' ?> <strong><?= e($t['subject']) ?></strong>
        <span class="small muted"> · <?= e($t['assignee_name'] ?? '') ?> · <?= e(ago($t['updated_at'])) ?></span></span>
      <?= $t['unread_creator'] ? '<span class="badge badge-in_progress">Përgjigje e re!</span>' : status_badge($t['status']) ?>
    </a>
  <?php endforeach; if (!$rows): ?><p class="muted">Nuk ke dërguar asnjë mesazh ende.</p><?php endif; ?>
</section>
<?php layout_end();
