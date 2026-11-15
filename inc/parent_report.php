<?php
declare(strict_types=1);

function month_options(string $sel, int $n = 12): string {
    $names = ['', 'Janar', 'Shkurt', 'Mars', 'Prill', 'Maj', 'Qershor', 'Korrik', 'Gusht', 'Shtator', 'Tetor', 'Nëntor', 'Dhjetor'];
    $h = '';
    for ($i = 0; $i < $n; $i++) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
        $h .= '<option value="' . $m . '"' . ($m === $sel ? ' selected' : '') . '>' . $names[(int)substr($m, 5)] . ' ' . substr($m, 0, 4) . '</option>';
    }
    return $h;
}
function month_label(string $ym): string {
    $names = ['', 'Janar', 'Shkurt', 'Mars', 'Prill', 'Maj', 'Qershor', 'Korrik', 'Gusht', 'Shtator', 'Tetor', 'Nëntor', 'Dhjetor'];
    return $names[(int)substr($ym, 5)] . ' ' . substr($ym, 0, 4);
}
function valid_month(string $m): string {
    return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : date('Y-m');
}

function parent_report_data(int $sid, string $ym): array {
    $from = $ym . '-01';
    $to = date('Y-m-t', strtotime($from));
    $s = q1("SELECT u.*, sc.name school FROM users u LEFT JOIN schools sc ON sc.id=u.school_id WHERE u.id=? AND u.role='student'", [$sid]);
    $classes = qa('SELECT c.name, t.full_name teacher, t.email teacher_email FROM classes c JOIN class_students cs ON cs.class_id=c.id JOIN users t ON t.id=c.teacher_id WHERE cs.student_id=?', [$sid]);
    $u = q1('SELECT COALESCE(SUM(seconds),0) secs, COUNT(DISTINCT day) days FROM usage_daily WHERE student_id=? AND day BETWEEN ? AND ?', [$sid, $from, $to]);
    $sessions = db_table_exists('learning_sessions') ? (int)qv('SELECT COUNT(*) FROM learning_sessions WHERE student_id=? AND started_at BETWEEN ? AND ?', [$sid, "$from 00:00:00", "$to 23:59:59"]) : 0;
    $acts = (int)qv('SELECT COUNT(DISTINCT book_id, section_id) FROM quiz_attempts WHERE student_id=? AND created_at BETWEEN ? AND ?', [$sid, "$from 00:00:00", "$to 23:59:59"]);
    $acc = qv('SELECT AVG(best) FROM (SELECT MAX(score_pct) best FROM quiz_attempts WHERE student_id=? AND created_at BETWEEN ? AND ? AND score_pct IS NOT NULL GROUP BY book_id, section_id) x', [$sid, "$from 00:00:00", "$to 23:59:59"]);
    $pages = (int)qv('SELECT COUNT(*) FROM page_views WHERE student_id=? AND first_seen BETWEEN ? AND ?', [$sid, "$from 00:00:00", "$to 23:59:59"]);
    $books = [];
    foreach (qa('SELECT DISTINCT b.* FROM books b JOIN class_books cb ON cb.book_id=b.id JOIN class_students cs ON cs.class_id=cb.class_id WHERE cs.student_id=? ORDER BY b.title', [$sid]) as $b) {
        $books[] = ['title' => $b['title'], 'p' => progress_for([$sid], (int)$b['id'])[$sid]];
    }
    $weeks = [];
    foreach (qa('SELECT day, SUM(seconds) s FROM usage_daily WHERE student_id=? AND day BETWEEN ? AND ? GROUP BY day', [$sid, $from, $to]) as $r) {
        $w = 'Java ' . (int)ceil((int)date('j', strtotime($r['day'])) / 7);
        $weeks[$w] = ($weeks[$w] ?? 0) + (int)$r['s'];
    }
    $assign = [];
    if (db_table_exists('assignments')) {
        foreach (student_assignments($sid) as $a) {
            if ($a['due_date'] >= $from && $a['start_date'] <= $to) $assign[] = $a;
        }
    }
    $notes = qa('SELECT q.teacher_note, q.created_at, bp.page_number FROM quiz_attempts q LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
        WHERE q.student_id=? AND q.teacher_note IS NOT NULL AND q.created_at BETWEEN ? AND ? ORDER BY q.id DESC LIMIT 5', [$sid, "$from 00:00:00", "$to 23:59:59"]);
    $badges = db_table_exists('badges_earned') ? qa('SELECT badge, earned_at FROM badges_earned WHERE student_id=? ORDER BY earned_at', [$sid]) : [];

    // rekomandime të thjeshta për prindin
    $rec = [];
    $late = count(array_filter($assign, fn($a) => $a['progress']['status'] === 'late'));
    if ((int)$u['days'] === 0) $rec[] = 'Këtë muaj fëmija nuk ka lexuar në platformë. Ndihmojeni të hyjë (kartela e hyrjes) dhe ulni së bashku 15 minuta.';
    elseif ((int)$u['days'] < 6) $rec[] = 'Inkurajojeni fëmijën të lexojë rregullisht — 15 minuta, 3–4 herë në javë, japin rezultatin më të mirë.';
    if ($acc !== null && (float)$acc < 60) $rec[] = 'Disa ushtrime i duken të vështira. Lexoni bashkë udhëzimet dhe lëreni ta provojë përsëri — ka të drejtë për disa tentativa.';
    if ($late) $rec[] = "Ka $late detyrë/a të papërfunduara pas afatit. Kontrolloni me fëmijën seksionin 📌 Detyrat e mia.";
    if (!$rec) $rec[] = 'Punë shumë e mirë këtë muaj! Lavdërojeni fëmijën për përpjekjen dhe vazhdoni kështu. 🌟';

    return ['s' => $s, 'classes' => $classes, 'ym' => $ym, 'from' => $from, 'to' => $to, 'secs' => (int)$u['secs'], 'days' => (int)$u['days'], 'sessions' => $sessions,
        'acts' => $acts, 'acc' => $acc !== null ? (int)round((float)$acc) : null, 'pages' => $pages, 'books' => $books, 'weeks' => $weeks,
        'assign' => $assign, 'notes' => $notes, 'badges' => $badges, 'rec' => $rec];
}

function parent_report_html(array $d): string {
    $s = $d['s'];
    $cls = $d['classes'][0] ?? ['name' => '—', 'teacher' => '—', 'teacher_email' => ''];
    $cat = badge_catalog();
    ob_start(); ?>
    <article class="preport">
      <header class="preport-head">
        <div><div class="small muted">📘 <?= e(cfg('app_name')) ?> · Raporti mujor për prindërit</div>
          <h1><?= e($s['avatar'] ?: '🙂') ?> <?= e($s['full_name']) ?></h1>
          <div class="muted"><?= e($s['school'] ?? '') ?> · Klasa <?= e($cls['name']) ?> · Mësuesi/ja: <?= e($cls['teacher']) ?></div></div>
        <div class="preport-month"><?= e(month_label($d['ym'])) ?></div>
      </header>
      <div class="preport-kpis">
        <div><b><?= $d['days'] ?></b><span>ditë mësimi</span></div>
        <div><b><?= e(fmt_duration($d['secs'])) ?></b><span>kohë leximi</span></div>
        <div><b><?= $d['pages'] ?></b><span>faqe të reja</span></div>
        <div><b><?= $d['acts'] ?></b><span>ushtrime</span></div>
        <div><b><?= $d['acc'] !== null ? $d['acc'] . '%' : '—' ?></b><span>saktësia</span></div>
      </div>
      <div class="grid-2">
        <section>
          <h3>📚 Progresi në libra</h3>
          <?php foreach ($d['books'] as $b): ?>
            <div style="margin-bottom:10px"><div class="row-between small"><strong><?= e($b['title']) ?></strong><span><?= $b['p']['progress'] ?>%</span></div><?= progress_bar($b['p']['progress']) ?>
              <div class="small muted"><?= $b['p']['pages'] ?>/<?= $b['p']['page_total'] ?> faqe · <?= $b['p']['acts_done'] ?>/<?= $b['p']['act_total'] ?> ushtrime</div></div>
          <?php endforeach; if (!$d['books']): ?><p class="muted">Asnjë libër i caktuar.</p><?php endif; ?>
          <h3>🗓️ Koha sipas javëve</h3>
          <?php $mx = max(1, ...array_values($d['weeks'] ?: [1])); foreach (['Java 1', 'Java 2', 'Java 3', 'Java 4', 'Java 5'] as $w): $v = $d['weeks'][$w] ?? 0; if ($w === 'Java 5' && !$v) continue; ?>
            <div class="hbar" style="grid-template-columns:70px 1fr 70px"><span class="small"><?= $w ?></span><?= progress_bar(pct($v, $mx)) ?><span class="small muted"><?= e(fmt_duration($v)) ?></span></div>
          <?php endforeach; ?>
        </section>
        <section>
          <?php if ($d['assign']): ?><h3>📌 Detyrat e muajit</h3>
            <?php foreach ($d['assign'] as $a): ?><p style="margin:0 0 8px"><?= assignment_status_badge($a['progress']['status']) ?> <strong><?= e($a['title']) ?></strong> <span class="small muted">· afati <?= e(date('d.m', strtotime($a['due_date']))) ?> · <?= $a['progress']['pct'] ?>%</span></p><?php endforeach; ?>
          <?php endif; ?>
          <?php if ($d['badges']): ?><h3>🏅 Arritjet</h3><p style="font-size:1.6rem;margin:0"><?php foreach ($d['badges'] as $b): ?><span title="<?= e(($cat[$b['badge']][1] ?? '') . ' — ' . fmt_dt($b['earned_at'], false)) ?>"><?= $cat[$b['badge']][0] ?? '⭐' ?></span> <?php endforeach; ?></p><?php endif; ?>
          <?php if ($d['notes']): ?><h3>💬 Komentet e mësuesit</h3><?php foreach ($d['notes'] as $n): ?><p class="small" style="margin:0 0 6px">“<?= e($n['teacher_note']) ?>” <span class="muted"><?= $n['page_number'] ? '— faqja ' . (int)$n['page_number'] : '' ?></span></p><?php endforeach; endif; ?>
          <h3>👪 Si mund të ndihmoni</h3>
          <ul class="small" style="margin:0;padding-left:18px"><?php foreach ($d['rec'] as $r): ?><li style="margin-bottom:4px"><?= e($r) ?></li><?php endforeach; ?></ul>
        </section>
      </div>
      <footer class="small muted preport-foot">Pyetje? Kontaktoni mësuesin/en <?= e($cls['teacher']) ?><?= $cls['teacher_email'] ? ' — ' . e($cls['teacher_email']) : '' ?>. Gjeneruar më <?= e(date('d.m.Y')) ?>.</footer>
    </article>
    <?php return (string)ob_get_clean();
}

function parent_link(array $s): ?string {
    if (empty($s['parent_token'])) return null;
    $host = isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : '';
    return $host . url('parent.php?t=' . $s['parent_token']);
}
