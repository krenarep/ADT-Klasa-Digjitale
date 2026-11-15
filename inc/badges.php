<?php
declare(strict_types=1);

function badge_catalog(): array {
    return [
        'first_page' => ['🚀', 'Hapi i parë', 'Hape librin për herë të parë'],
        'pages_10'   => ['📖', 'Lexues i zellshëm', 'Lexo 10 faqe'],
        'pages_50'   => ['📚', 'Mik i librave', 'Lexo 50 faqe'],
        'acts_1'     => ['✍️', 'Ushtrimi i parë', 'Dërgo ushtrimin e parë'],
        'acts_10'    => ['🧠', 'Mendje e mprehtë', 'Dërgo 10 ushtrime'],
        'acts_25'    => ['🏋️', 'Kampion ushtrimesh', 'Dërgo 25 ushtrime'],
        'perfect'    => ['💯', 'Perfekt!', 'Merr 100% në një ushtrim'],
        'perfect_5'  => ['🌟', 'Yll i saktësisë', 'Merr 100% në 5 ushtrime'],
        'streak_3'   => ['🔥', '3 ditë rresht', 'Mëso 3 ditë njëra pas tjetrës'],
        'streak_7'   => ['☄️', 'Një javë rresht', 'Mëso 7 ditë njëra pas tjetrës'],
        'time_60'    => ['⏱️', 'Një orë mësim', 'Mëso gjithsej 1 orë'],
        'time_300'   => ['⌛', 'Pesë orë mësim', 'Mëso gjithsej 5 orë'],
        'on_time'    => ['📌', 'Gjithmonë në kohë', 'Përfundo një detyrë para afatit'],
        'helper'     => ['🙋', 'Guximtar', 'Kërko ndihmë kur s\'e kupton diçka'],
        'book_done'  => ['🏆', 'Libri i përfunduar', 'Përfundo një libër të tërë'],
    ];
}

function longest_streak(array $days): int {
    sort($days);
    $best = $cur = 0; $prev = null;
    foreach ($days as $d) {
        $t = strtotime($d);
        $cur = ($prev !== null && $t - $prev === 86400) ? $cur + 1 : 1;
        $best = max($best, $cur);
        $prev = $t;
    }
    return $best;
}

/** Llogarit distinktivët; ruan të rinjtë dhe kthen [earned => [badge => earned_at], new => [badge,...]] */
function compute_badges(int $sid): array {
    $earned = [];
    foreach (qa('SELECT badge, earned_at, seen FROM badges_earned WHERE student_id=?', [$sid]) as $r) $earned[$r['badge']] = $r;
    $pages = (int)qv('SELECT COUNT(*) FROM page_views WHERE student_id=?', [$sid]);
    $acts = (int)qv('SELECT COUNT(DISTINCT book_id, section_id) FROM quiz_attempts WHERE student_id=?', [$sid]);
    $perfect = (int)qv('SELECT COUNT(DISTINCT book_id, section_id) FROM quiz_attempts WHERE student_id=? AND score_pct=100', [$sid]);
    $secs = (int)qv('SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=?', [$sid]);
    $streak = longest_streak(array_column(qa('SELECT DISTINCT day FROM usage_daily WHERE student_id=?', [$sid]), 'day'));
    $tickets = (int)qv('SELECT COUNT(*) FROM tickets WHERE created_by=?', [$sid]);
    $bookDone = false;
    $th = setting_int('cert_threshold') ?: 90;
    foreach (qa('SELECT DISTINCT book_id FROM page_views WHERE student_id=?', [$sid]) as $b) {
        if (progress_for([$sid], (int)$b['book_id'])[$sid]['progress'] >= $th) { $bookDone = true; break; }
    }
    $onTime = false;
    try {
        foreach (student_assignments($sid) as $a) {
            if ($a['progress']['pct'] >= 100 && $a['progress']['last'] && substr((string)$a['progress']['last'], 0, 10) <= $a['due_date']) { $onTime = true; break; }
        }
    } catch (Throwable) {}
    $ok = [
        'first_page' => $pages >= 1, 'pages_10' => $pages >= 10, 'pages_50' => $pages >= 50,
        'acts_1' => $acts >= 1, 'acts_10' => $acts >= 10, 'acts_25' => $acts >= 25,
        'perfect' => $perfect >= 1, 'perfect_5' => $perfect >= 5, 'streak_3' => $streak >= 3, 'streak_7' => $streak >= 7,
        'time_60' => $secs >= 3600, 'time_300' => $secs >= 18000, 'on_time' => $onTime, 'helper' => $tickets >= 1, 'book_done' => $bookDone,
    ];
    $new = [];
    foreach ($ok as $k => $yes) {
        if ($yes && !isset($earned[$k])) {
            q('INSERT IGNORE INTO badges_earned (student_id, badge) VALUES (?,?)', [$sid, $k]);
            $earned[$k] = ['badge' => $k, 'earned_at' => date('Y-m-d H:i:s'), 'seen' => 0];
            $new[] = $k;
        }
    }
    return ['earned' => $earned, 'new' => $new, 'stats' => compact('pages', 'acts', 'perfect', 'secs', 'streak')];
}

/* ================= Certifikatat ================= */
function certificate_code(int $sid, int $bookId): string {
    return strtoupper(substr(hash_hmac('sha256', "cert:$sid:$bookId", app_secret()), 0, 10));
}
/** Nxënësi ka të drejtë për certifikatë kur progresi ≥ pragu */
function certificate_eligible(int $sid, int $bookId): bool {
    $th = setting_int('cert_threshold') ?: 90;
    return progress_for([$sid], $bookId)[$sid]['progress'] >= $th;
}
function certificate_date(int $sid, int $bookId): string {
    return (string)(qv('SELECT MAX(last_seen) FROM page_views WHERE student_id=? AND book_id=?', [$sid, $bookId]) ?: date('Y-m-d'));
}

function certificate_html(array $student, array $book, string $teacher, string $school): string {
    $code = certificate_code((int)$student['id'], (int)$book['id']);
    $date = date('d.m.Y', strtotime(certificate_date((int)$student['id'], (int)$book['id'])));
    $verify = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : '') . url('verify.php?c=' . $code);
    ob_start(); ?>
    <div class="cert">
      <div class="cert-inner">
        <div class="cert-top">📘 <?= e(cfg('app_name')) ?></div>
        <div class="cert-title">CERTIFIKATË</div>
        <div class="cert-sub">i jepet me krenari nxënësit / nxënëses</div>
        <div class="cert-name"><?= e($student['full_name']) ?></div>
        <div class="cert-text">për përfundimin me sukses të librit digjital</div>
        <div class="cert-book">“<?= e($book['title']) ?>”</div>
        <div class="cert-stars">⭐ ⭐ ⭐</div>
        <div class="cert-foot">
          <div><span class="line"></span><?= e($teacher) ?><br><small>Mësuesi/ja</small></div>
          <div class="cert-meta"><?= e($school) ?><br><?= e($date) ?><br><small>Kodi: <?= e($code) ?></small></div>
          <div><span class="line"></span>&nbsp;<br><small>Drejtori/ja</small></div>
        </div>
        <div class="cert-verify">Verifikimi: <?= e($verify) ?></div>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}
