<?php
declare(strict_types=1);

function ticket_get(int $id): ?array {
    return q1('SELECT t.*, u.full_name AS creator_name, u.role AS creator_role, u.avatar AS creator_avatar,
                      a.full_name AS assignee_name, s.name AS school_name, b.title AS book_title, bp.title AS page_title, bp.page_number
               FROM tickets t
               JOIN users u ON u.id = t.created_by
               LEFT JOIN users a ON a.id = t.assigned_to
               LEFT JOIN schools s ON s.id = t.school_id
               LEFT JOIN books b ON b.id = t.book_id
               LEFT JOIN book_pages bp ON bp.book_id = t.book_id AND bp.section_id = t.section_id
               WHERE t.id = ?', [$id]);
}

function ticket_can_view(array $u, array $t): bool {
    return match ($u['role']) {
        'admin' => true,
        'teacher' => (int)$t['created_by'] === (int)$u['id'] || ($t['recipient_role'] === 'teacher' && (int)$t['assigned_to'] === (int)$u['id']),
        default => (int)$t['created_by'] === (int)$u['id'],
    };
}
function ticket_is_recipient(array $u, array $t): bool {
    return ($u['role'] === 'admin') || ($u['role'] === 'teacher' && $t['recipient_role'] === 'teacher' && (int)$t['assigned_to'] === (int)$u['id']);
}

function ticket_create(array $u, string $recipientRole, ?int $assignedTo, string $kind, string $category, string $subject, string $message, ?int $bookId = null, ?string $sectionId = null, ?int $escalatedFrom = null): int {
    $subject = mb_substr(trim($subject), 0, 200);
    $message = trim($message);
    if ($message === '') throw new RuntimeException('Shkruaj mesazhin.');
    if ($subject === '') $subject = mb_substr(preg_replace('/\s+/u', ' ', $message), 0, 80);
    if (!isset(ticket_categories()[$category])) $category = 'other';
    if (!isset(ticket_kinds()[$kind])) $kind = 'problem';
    q('INSERT INTO tickets (created_by, recipient_role, assigned_to, school_id, kind, category, subject, book_id, section_id, escalated_from)
       VALUES (?,?,?,?,?,?,?,?,?,?)', [$u['id'], $recipientRole, $assignedTo, $u['school_id'], $kind, $category, $subject, $bookId ?: null, $sectionId ?: null, $escalatedFrom]);
    $id = (int)db()->lastInsertId();
    q('INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?,?,?)', [$id, $u['id'], $message]);
    return $id;
}

function ticket_reply(array $u, array $t, string $message, ?string $status = null): void {
    $message = trim($message);
    if ($message !== '') {
        q('INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?,?,?)', [$t['id'], $u['id'], $message]);
    }
    $isCreator = (int)$t['created_by'] === (int)$u['id'];
    $sets = ['updated_at = NOW()'];
    $params = [];
    if ($message !== '') $sets[] = $isCreator ? 'unread_recipient = 1' : 'unread_creator = 1';
    if ($status && in_array($status, ['open', 'in_progress', 'closed'], true) && ($status !== $t['status'])) {
        if (ticket_is_recipient($u, $t) || ($isCreator && $status === 'closed') || ($isCreator && $status === 'open')) {
            $sets[] = 'status = ?';
            $params[] = $status;
            if (!$isCreator) $sets[] = 'unread_creator = 1';
        }
    } elseif ($isCreator && $message !== '' && $t['status'] === 'closed') {
        $sets[] = "status = 'open'";
    }
    $params[] = $t['id'];
    q('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
}

function ticket_mark_read(array $u, array $t): void {
    if ((int)$t['created_by'] === (int)$u['id']) q('UPDATE tickets SET unread_creator = 0 WHERE id = ?', [$t['id']]);
    if (ticket_is_recipient($u, $t)) q('UPDATE tickets SET unread_recipient = 0 WHERE id = ?', [$t['id']]);
}

function ticket_messages(int $id): array {
    return qa('SELECT m.*, u.full_name, u.role, u.avatar FROM ticket_messages m JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.id', [$id]);
}

function ticket_list_html(array $rows, string $viewBase, array $u, bool $showRecipient = false): string {
    if (!$rows) return '<div class="empty">📭 Nuk ka tiketa.</div>';
    $cats = ticket_categories();
    ob_start(); ?>
    <div class="table-wrap"><table class="t" id="tickets-table">
      <thead><tr><th>#</th><th>Tema</th><th>Nga</th><?php if ($showRecipient): ?><th>Për</th><?php endif; ?><th>Kategoria</th><th>Libri / faqja</th><th>Statusi</th><th>Përditësuar</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $t):
          $unread = ((int)$t['created_by'] === (int)$u['id'] && $t['unread_creator']) || (ticket_is_recipient($u, $t) && $t['unread_recipient']); ?>
        <tr>
          <td><?= (int)$t['id'] ?></td>
          <td><a href="<?= e($viewBase . (str_contains($viewBase, '?') ? '&' : '?') . 'id=' . $t['id']) ?>"><?= $unread ? '<strong>🔵 ' : '' ?><?= e($t['subject']) ?><?= $unread ? '</strong>' : '' ?></a>
            <div class="small muted"><?= e(ticket_kinds()[$t['kind']] ?? '') ?></div></td>
          <td><?= e($t['creator_name']) ?><div class="small muted"><?= $t['creator_role'] === 'student' ? 'Nxënës' : ($t['creator_role'] === 'teacher' ? 'Mësues' : 'Admin') ?><?= $t['school_name'] ? ' · ' . e($t['school_name']) : '' ?></div></td>
          <?php if ($showRecipient): ?><td><?= $t['recipient_role'] === 'admin' ? 'Administratori' : e($t['assignee_name'] ?? 'Mësuesi') ?></td><?php endif; ?>
          <td><?= $cats[$t['category']][0] ?? '💬' ?> <?= e($cats[$t['category']][1] ?? $t['category']) ?></td>
          <td class="small"><?= $t['book_title'] ? e($t['book_title']) . ($t['page_number'] ? ' · f. ' . (int)$t['page_number'] : '') : '—' ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td class="small"><?= e(ago($t['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php return (string)ob_get_clean();
}

const TICKET_LIST_SQL = 'SELECT t.*, u.full_name AS creator_name, u.role AS creator_role, a.full_name AS assignee_name, s.name AS school_name,
       b.title AS book_title, bp.page_number
  FROM tickets t JOIN users u ON u.id=t.created_by LEFT JOIN users a ON a.id=t.assigned_to
  LEFT JOIN schools s ON s.id=t.school_id LEFT JOIN books b ON b.id=t.book_id
  LEFT JOIN book_pages bp ON bp.book_id=t.book_id AND bp.section_id=t.section_id';

function ticket_view_html(array $u, array $t, string $backUrl, bool $allowEscalate = false): string {
    $msgs = ticket_messages((int)$t['id']);
    $cats = ticket_categories();
    $isRecipient = ticket_is_recipient($u, $t);
    $isCreator = (int)$t['created_by'] === (int)$u['id'];
    ob_start(); ?>
    <p><a href="<?= e($backUrl) ?>">← Të gjitha tiketat</a></p>
    <div class="grid-2" style="grid-template-columns:2fr 1fr">
      <section class="card">
        <div class="row-between"><h2><?= $cats[$t['category']][0] ?? '💬' ?> <?= e($t['subject']) ?></h2><?= status_badge($t['status']) ?></div>
        <div class="thread">
          <?php foreach ($msgs as $m): ?>
            <div class="msg <?= (int)$m['user_id'] === (int)$u['id'] ? 'mine' : '' ?>">
              <div class="meta"><?= e($m['avatar'] ?? '') ?> <strong><?= e($m['full_name']) ?></strong> · <?= e(fmt_dt($m['created_at'])) ?></div>
              <p><?= e($m['message']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
        <form method="post" class="form mt">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <label>Përgjigju<textarea name="message" placeholder="Shkruaj përgjigjen…"></textarea></label>
          <div class="row">
            <?php if ($isRecipient): ?>
              <select name="status" style="width:auto" aria-label="Statusi">
                <?php foreach (['open', 'in_progress', 'closed'] as $s): ?><option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
              </select>
            <?php elseif ($isCreator && $t['status'] !== 'closed'): ?>
              <label class="checkline"><input type="checkbox" name="status" value="closed"> Problemi u zgjidh — mbylle</label>
            <?php endif; ?>
            <button class="btn btn-primary">Dërgo</button>
          </div>
        </form>
      </section>
      <aside class="card">
        <h3>Detajet</h3>
        <dl class="kv">
          <dt>Nr.</dt><dd>#<?= (int)$t['id'] ?></dd>
          <dt>Lloji</dt><dd><?= e(ticket_kinds()[$t['kind']] ?? '') ?></dd>
          <dt>Kategoria</dt><dd><?= e($cats[$t['category']][1] ?? '') ?></dd>
          <dt>Nga</dt><dd><?= e($t['creator_name']) ?></dd>
          <dt>Për</dt><dd><?= $t['recipient_role'] === 'admin' ? 'Administratori' : e($t['assignee_name'] ?? '') ?></dd>
          <dt>Shkolla</dt><dd><?= e($t['school_name'] ?? '—') ?></dd>
          <?php if ($t['book_title']): ?><dt>Libri</dt><dd><?= e($t['book_title']) ?></dd>
            <dt>Faqja</dt><dd><?= $t['page_number'] ? 'f. ' . (int)$t['page_number'] . ' · ' : '' ?><?= e($t['page_title'] ?? $t['section_id']) ?></dd><?php endif; ?>
          <dt>Krijuar</dt><dd><?= e(fmt_dt($t['created_at'])) ?></dd>
          <?php if ($t['escalated_from']): ?><dt>Përcjellë nga</dt><dd>#<?= (int)$t['escalated_from'] ?></dd><?php endif; ?>
        </dl>
        <?php if ($allowEscalate && $isRecipient && $u['role'] === 'teacher'): ?>
          <form method="post" class="mt" data-confirm="Ta përcjell këtë tiketë te administratori?">
            <?= csrf_field() ?><input type="hidden" name="action" value="escalate"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-block">⤴️ Përcille te administratori</button>
          </form>
        <?php endif; ?>
      </aside>
    </div>
    <?php return (string)ob_get_clean();
}

/** Trajton POST-in e përbashkët (reply/escalate). Kthen true nëse u trajtua. */
function ticket_handle_post(array $u, string $redirectBase): void {
    $t = ticket_get(posti('id'));
    if (!$t || !ticket_can_view($u, $t)) { flash('err', 'Tiketa nuk u gjet.'); redirect($redirectBase); }
    if (post('action') === 'reply') {
        $status = post('status') ?: null;
        if (trim((string)post('message')) === '' && !$status) { flash('err', 'Shkruani një mesazh.'); }
        else { ticket_reply($u, $t, (string)post('message'), $status); flash('ok', 'U dërgua.'); }
    } elseif (post('action') === 'escalate' && $u['role'] === 'teacher' && ticket_is_recipient($u, $t)) {
        $msgs = ticket_messages((int)$t['id']);
        $orig = $msgs ? $msgs[0]['message'] : '';
        $newId = ticket_create($u, 'admin', null, $t['kind'], $t['category'], '[Nxënësi ' . $t['creator_name'] . '] ' . $t['subject'],
            "Përcjellë nga tiketa #{$t['id']} e nxënësit {$t['creator_name']}:\n\n" . $orig, $t['book_id'] ? (int)$t['book_id'] : null, $t['section_id'], (int)$t['id']);
        ticket_reply($u, $t, 'Mësuesi e përcolli problemin te administratori (tiketa #' . $newId . ').', 'in_progress');
        flash('ok', 'Tiketa u përcoll te administratori (#' . $newId . ').');
    }
    redirect($redirectBase . (str_contains($redirectBase, '?') ? '&' : '?') . 'id=' . $t['id']);
}
