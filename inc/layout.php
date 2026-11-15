<?php
declare(strict_types=1);

function nav_items(string $role): array {
    return match ($role) {
        'admin' => [
            ['admin/index.php', '📊', 'Paneli'],
            ['admin/usage.php', '🖱️', 'Klikimet & qasshmëria'],
            ['admin/reports.php', '📄', 'Raportet statistikore'],
            ['admin/grading.php', '✍️', 'Vlerësimet (nxënës–mësues)'],
            ['admin/journey.php', '🧭', 'Rrugëtimi i mësuesve'],
            ['admin/trainings.php', '🎓', 'Trajnimet e mësuesve'],
            ['admin/training_report.php', '📑', 'Raporti për palët (EN)'],
            ['admin/schools.php', '🏫', 'Shkollat'],
            ['admin/teachers.php', '👩‍🏫', 'Mësuesit'],
            ['admin/students.php', '🧒', 'Nxënësit'],
            ['admin/books.php', '📚', 'Librat'],
            ['admin/tickets.php', '🎫', 'Tiketat'],
            ['admin/privacy.php', '🛡️', 'Mbrojtja e të dhënave'],
            ['admin/languages.php', '🌐', 'Gjuhët'],
            ['admin/settings.php', '⚙️', 'Cilësimet'],
            ['profile.php', '🔒', 'Profili'],
        ],
        'teacher' => [
            ['teacher/index.php', '📊', 'Paneli'],
            ['teacher/journey.php', '🧭', 'Rrugëtimi im'],
            ['teacher/alerts.php', '🔔', 'Njoftimet'],
            ['teacher/classes.php', '🏷️', 'Klasat'],
            ['teacher/students.php', '🧒', 'Nxënësit'],
            ['teacher/books.php', '📚', 'Librat digjitalë'],
            ['teacher/assignments.php', '📌', 'Detyrat'],
            ['teacher/progress.php', '📈', 'Progresi'],
            ['teacher/grading.php', '✍️', 'Vlerësimi'],
            ['teacher/report.php', '📄', 'Raporti i klasës'],
            ['teacher/parents.php', '👪', 'Prindërit'],
            ['teacher/certificates.php', '🏅', 'Certifikatat'],
            ['teacher/tickets.php', '🎫', 'Tiketat'],
            ['profile.php', '🔒', 'Profili'],
        ],
        default => [
            ['student/index.php', '🏠', 'Librat e mi'],
            ['student/assignments.php', '📌', 'Detyrat'],
            ['student/grades.php', '⭐', 'Vlerësimet'],
            ['student/achievements.php', '🏅', 'Arritjet'],
            ['student/tickets.php', '🙋', 'Më ndihmo'],
        ],
    };
}

function unread_tickets(array $u): int {
    try {
        return match ($u['role']) {
            'admin' => (int)qv("SELECT COUNT(*) FROM tickets WHERE recipient_role='admin' AND unread_recipient=1 AND status<>'closed'"),
            'teacher' => (int)qv("SELECT COUNT(*) FROM tickets WHERE (recipient_role='teacher' AND assigned_to=? AND unread_recipient=1 AND status<>'closed') OR (created_by=? AND unread_creator=1)", [$u['id'], $u['id']]),
            default => (int)qv('SELECT COUNT(*) FROM tickets WHERE created_by=? AND unread_creator=1', [$u['id']]),
        };
    } catch (Throwable) {
        return 0;
    }
}

function nav_badge(string $href, array $u): int {
    try {
        if (str_contains($href, 'tickets')) return unread_tickets($u);
        if ($href === 'admin/journey.php') return (int)qv("SELECT COUNT(*) FROM journey_status WHERE status='submitted'");
        if ($href === 'teacher/journey.php') return (int)qv("SELECT COUNT(*) FROM journey_status WHERE teacher_id=? AND status='needs_work'", [$u['id']]);
        if (str_ends_with($href, 'alerts.php')) return alerts_unread((int)$u['id']);
        if (str_ends_with($href, 'grading.php')) return $u['role'] === 'teacher' ? pending_verify_count((int)$u['id']) + pending_work_count((int)$u['id']) : 0;
        if ($href === 'student/assignments.php') {
            $n = 0;
            foreach (student_assignments((int)$u['id'], true) as $a) if ($a['progress']['pct'] < 100) $n++;
            return $n;
        }
        if ($href === 'student/grades.php') return student_new_grades((int)$u['id']);
        if ($href === 'student/achievements.php') return (int)qv('SELECT COUNT(*) FROM badges_earned WHERE student_id=? AND seen=0', [$u['id']]);
    } catch (Throwable) {}
    return 0;
}

function layout_start(string $title, array $opts = []): void {
    $u = current_user();
    $role = $u['role'] ?? 'guest';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $bodyClass = 'role-' . $role . ' ' . ($opts['body_class'] ?? '');
    $app = cfg('app_name', 'Klasa Digjitale');
    ?><!DOCTYPE html>
<html lang="sq">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📘</text></svg>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<meta name="theme-color" content="#2f5bea">
<link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=4">
<script>window.APP_BASE = <?= json_encode(rtrim((string)cfg('base_url', ''), '/')) ?>;</script>
<script src="<?= e(url('assets/js/app.js')) ?>?v=6" defer></script>
<?= i18n_js() ?>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip" href="#main">Kalo te përmbajtja</a>
<?php if ($u && $role !== 'student'): ?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><span class="logo" aria-hidden="true">📘</span><span><?= e($app) ?><small><?= $role === 'admin' ? 'Administrator' : 'Mësues' ?></small></span></div>
    <nav aria-label="Menyja kryesore">
      <?php foreach (nav_items($role) as [$href, $ico, $label]):
          $active = str_ends_with($script, '/' . $href) || (!empty($opts['nav']) && $opts['nav'] === $href);
          $badge = nav_badge($href, $u); ?>
        <a href="<?= e(url($href)) ?>" class="<?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
          <span class="ico" aria-hidden="true"><?= $ico ?></span><?= e($label) ?>
          <?php if ($badge): ?><span class="nbadge" aria-label="<?= $badge ?> të palexuara"><?= $badge ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <div class="me"><strong><?= e($u['full_name']) ?></strong><small><?= e($u['school_name'] ?? 'Të gjitha shkollat') ?></small></div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('logout.php')) ?>">⎋ Dil</a>
    </div>
    <?php if ($sw = i18n_switcher_html()): ?><div style="padding:0 16px 12px">🌐 <?= $sw ?></div><?php endif; ?>
  </aside>
  <div class="main-wrap">
    <header class="topbar">
      <button class="btn btn-ghost menu-btn" type="button" data-toggle-sidebar aria-label="Hap menynë">☰</button>
      <h1><?= e($title) ?></h1>
      <div class="topbar-actions"><?= $opts['actions'] ?? '' ?></div>
    </header>
    <main id="main" class="content">
      <?= flashes() ?>
<?php elseif ($u && $role === 'student'): ?>
<header class="kid-top">
  <a class="kid-brand" href="<?= e(url('student/index.php')) ?>"><span aria-hidden="true">📘</span> <?= e($app) ?></a>
  <nav class="kid-nav" aria-label="Menyja">
    <?php foreach (nav_items('student') as [$href, $ico, $label]):
        $active = str_ends_with($script, '/' . $href);
        $badge = nav_badge($href, $u); ?>
      <a href="<?= e(url($href)) ?>" class="<?= $active ? 'active' : '' ?>"><span aria-hidden="true"><?= $ico ?></span> <?= e($label) ?><?php if ($badge): ?> <span class="nbadge"><?= $badge ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="a11y-bar" role="group" aria-label="Ndihmë për leximin">
    <button type="button" data-a11y="smaller" aria-label="Shkronja më të vogla">A−</button>
    <button type="button" data-a11y="bigger" aria-label="Shkronja më të mëdha">A+</button>
    <button type="button" data-a11y="contrast" aria-label="Kontrast i lartë">🌓</button>
    <button type="button" data-a11y="speak" aria-label="Lexoje me zë faqen">🔊</button>
    <?= i18n_switcher_html() ?>
  </div>
  <div class="kid-me"><span class="avatar" aria-hidden="true"><?= e($u['avatar'] ?: '🙂') ?></span><span class="kid-name"><?= e(anon_is_code($u['full_name']) ? 'Nr. ' . (int)substr($u['full_name'], strrpos($u['full_name'], '-') + 1) : explode(' ', $u['full_name'])[0]) ?></span>
    <a class="btn btn-sm btn-ghost" href="<?= e(url('logout.php')) ?>">Dil 👋</a></div>
</header>
<main id="main" class="kid-content">
  <?= flashes() ?>
  <div class="net-banner" id="netBanner" role="status">📴 Pa internet — puna jote ruhet dhe dërgohet vetë kur të kthehet interneti.</div>
<?php else: ?>
<main id="main" class="guest">
  <?= flashes() ?>
<?php endif;
}

function layout_end(): void {
    $u = current_user();
    if ($u && $u['role'] !== 'student') {
        echo "    </main>\n  </div>\n</div>\n";
    } else {
        echo "</main>\n";
    }
    echo "</body>\n</html>";
}

/** Grafik shtyllash (HTML/CSS, pa librari të jashtme) */
function bar_chart(array $points, string $unit = 'min', string $label = 'Grafik'): string {
    // $points = [ ['label' => '12.10', 'value' => 34], ... ]
    if (!$points) return '<p class="muted">Nuk ka të dhëna ende.</p>';
    $rawMax = max(array_column($points, 'value'));
    if ($rawMax <= 0) return '<div class="bchart-empty">Nuk ka aktivitet në këtë periudhë.</div>';
    $mag = 10 ** max(0, (int)floor(log10(max(1, $rawMax))));
    $max = (int)(ceil($rawMax / $mag * 2) * $mag / 2) ?: 1;
    $n = count($points);
    $every = $n > 16 ? (int)ceil($n / 8) : 1;
    $h = '<figure class="bchart" role="img" aria-label="' . e($label) . '"><div class="bchart-y" aria-hidden="true"><span>' . $max . '</span><span>' . round($max / 2, 1) . '</span><span>0</span></div><div class="bchart-plot">';
    foreach ($points as $i => $p) {
        $pc = round($p['value'] * 100 / $max, 1);
        $h .= '<div class="bcol" title="' . e($p['label'] . ': ' . $p['value'] . ' ' . $unit) . '"><div class="bbar' . ($p['value'] ? '' : ' zero') . '" style="height:' . $pc . '%"></div>'
            . '<span class="blab">' . (($i % $every === 0) ? e($p['label']) : '') . '</span></div>';
    }
    return $h . '</div><figcaption class="sr-only">' . e($label) . ' (' . e($unit) . ')</figcaption></figure>';
}

function daily_series(array $rows, int $days = 30): array {
    // $rows: day => seconds
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $out[] = ['label' => date('d.m', strtotime($d)), 'value' => (int)round(($rows[$d] ?? 0) / 60)];
    }
    return $out;
}
