<?php
declare(strict_types=1);

/**
 * Rrugëtimi i mësuesit në pilotim (metodologjia e punës si "progress bar").
 * Fazat: 0 Para trajnimit (pëlqimet) · I Pyetësori para · II Trajnimi · III Pyetësori pas ·
 *        IV Projekti (plane mësimore, instrumente, validimi i librit) · V Intervenimi (cikle pre-test → aktivitet → post-test) + ditari javor.
 * Statuset: pending → submitted (mësuesi dorëzoi) → done | done_comments | needs_work (admini shqyrton).
 */

const JOURNEY_EXT = ['doc', 'docx', 'xls', 'xlsx', 'pdf', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'csv', 'jpg', 'jpeg', 'png'];
const JOURNEY_MAX_MB = 50;

/** who: 'upload' = mësuesi ngarkon skedar, admini shqyrton · 'confirm' = mësuesi konfirmon, admini shqyrton · 'admin' = vetëm admini · 'self' = mësuesi e shënon vetë */
function journey_phases(int $cycles = 10, bool $all = false): array {
    $P = [
        ['key' => 'p0', 'label' => 'Para trajnimit', 'short' => 'Pëlqimet', 'icon' => '📝', 'steps' => [
            ['key' => 'consent_parents', 'label' => 'Pëlqimet e prindërve / kujdestarëve të fëmijëve', 'who' => 'upload', 'consent' => 'Kam pranuar pëlqimet e prindërve', 'qty' => true, 'help' => 'Ngarkoni pëlqimet e skanuara (PDF/foto) ose dorëzojini fizikisht — administratori i kontrollon dhe i aprovon.'],
            ['key' => 'consent_director', 'label' => 'Pëlqimi i drejtorit/es së shkollës', 'who' => 'upload', 'consent' => 'Kam pranuar pëlqimin e drejtorit/es', 'help' => 'Ngarkojeni të skanuar ose dorëzojeni fizikisht — administratori e kontrollon dhe e aprovon.'],
            ['key' => 'consent_teacher', 'label' => 'Pëlqimi i mësuesit/es', 'who' => 'upload', 'consent' => 'Kam pranuar pëlqimin e mësuesit/es', 'help' => 'Ngarkojeni të skanuar ose dorëzojeni fizikisht — administratori e kontrollon dhe e aprovon.'],
        ]],
        ['key' => 'p1', 'label' => 'Procesi I', 'short' => 'Pyetësori para', 'icon' => '📋', 'steps' => [
            ['key' => 'pre_survey', 'label' => 'Pyetësori para trajnimit (pre-test)', 'who' => 'confirm', 'form' => 'pre', 'help' => 'Plotësoni pyetësorin në link dhe klikoni «E kam plotësuar».'],
        ]],
        ['key' => 'p2', 'label' => 'Procesi II', 'short' => 'Trajnimi', 'icon' => '🎓', 'steps' => [
            ['key' => 'training', 'label' => 'Trajnimi 2-ditor', 'who' => 'admin', 'help' => 'Shënohet nga administratori pas pjesëmarrjes.'],
        ]],
        ['key' => 'p3', 'label' => 'Procesi III', 'short' => 'Pyetësori pas', 'icon' => '📋', 'steps' => [
            ['key' => 'post_survey', 'label' => 'Pyetësori pas trajnimit (post-test)', 'who' => 'confirm', 'form' => 'post', 'help' => 'Plotësoni pyetësorin në link dhe klikoni «E kam plotësuar».'],
        ]],
        ['key' => 'p4', 'label' => 'Procesi IV', 'short' => 'Projekti', 'icon' => '📂', 'steps' => [
            ['key' => 'lesson_plans', 'label' => 'Planifikimi i punës me ADT: 10 plane mësimore', 'who' => 'upload', 'help' => 'Mund t\'i ngarkoni të gjitha në një skedar ose secilin plan veçmas (emërtojini p.sh. «Plani 3 – Uji.docx»).'],
            ['key' => 'assessment', 'label' => 'Instrumentet e vlerësimit (para dhe post testi)', 'who' => 'upload'],
            ['key' => 'book_validation', 'label' => 'Validimi i librit (skedari Excel)', 'who' => 'upload'],
        ]],
    ];
    $steps = [];
    for ($i = 1; $i <= $cycles; $i++) {
        $steps[] = ['key' => "c{$i}_pre", 'label' => "Cikli $i · Pre-test", 'who' => 'self', 'cycle' => $i];
        $steps[] = ['key' => "c{$i}_act", 'label' => "Cikli $i · Aktiviteti", 'who' => 'self', 'cycle' => $i];
        $steps[] = ['key' => "c{$i}_post", 'label' => "Cikli $i · Post-test", 'who' => 'self', 'cycle' => $i];
        $steps[] = ['key' => "c{$i}_diary", 'label' => "Cikli $i · Ditari", 'who' => 'self', 'cycle' => $i, 'diary' => true];
    }
    $P[] = ['key' => 'p5', 'label' => 'Procesi V', 'short' => 'Intervenimi', 'icon' => '🔁', 'steps' => $steps, 'diary' => true];
    return $all ? $P : journey_apply_config($P);
}

/** Hapat që admini mund t'i përfshijë ose jo (cikleve të intervenimit nuk u prek) */
function journey_optional_steps(): array {
    $o = [];
    foreach (journey_phases(1, true) as $ph) if ($ph['key'] !== 'p5') foreach ($ph['steps'] as $s) $o[$s['key']] = $ph['label'] . ' — ' . $s['label'];
    return $o;
}
/** Linku i pyetësorit për një hap (pyetësorët para/pas ndajnë linkun me tabelën e formularëve) */
function journey_step_url(string $key): string {
    $f = journey_step_def($key)['form'] ?? null;
    return $f ? journey_form_link($f) : (string)setting('journey_url_' . $key);
}
function journey_set_step_url(string $key, string $url): void {
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) throw new RuntimeException('Linku duhet të fillojë me https:// (' . (journey_step_def($key)['label'] ?? $key) . ')');
    $f = journey_step_def($key)['form'] ?? null;
    set_setting($f ? 'journey_link_' . $f : 'journey_url_' . $key, mb_substr($url, 0, 500));
}
function journey_step_enabled(string $key): bool { return setting('journey_off_' . $key) !== '1'; }

/** Heq hapat e çaktivizuar nga admini dhe shton detajet (p.sh. datat e trajnimit) */
function journey_apply_config(array $P): array {
    $out = [];
    foreach ($P as $ph) {
        $ph['steps'] = array_values(array_filter($ph['steps'], fn($s) => !empty($s['cycle']) || journey_step_enabled($s['key'])));
        foreach ($ph['steps'] as &$s) {
            $d = trim((string)setting('journey_detail_' . $s['key']));
            if ($d !== '') $s['detail'] = $d;
        }
        unset($s);
        if ($ph['steps']) $out[] = $ph;
    }
    return $out;
}

function journey_statuses(): array {
    return [
        'pending' => ['Pa filluar', '#9aa3b5', '#f1f3f7', '○'],
        'submitted' => ['Në shqyrtim', '#a15c07', '#fff4e0', '⏳'],
        'needs_work' => ['Ka nevojë për punë', '#c0322b', '#fdecea', '⚠️'],
        'done_comments' => ['Përfunduar me komente', '#0a7cc1', '#e6f4fc', '✔'],
        'done' => ['Përfunduar me sukses', '#14804a', '#e3f6ec', '✅'],
    ];
}
function journey_is_done(string $st): bool { return $st === 'done' || $st === 'done_comments'; }

function journey_cycles(int $teacherId): int {
    $n = (int)qv('SELECT cycles FROM journey_teacher WHERE teacher_id=?', [$teacherId]);
    return $n > 0 ? $n : 10;
}

/* ---------- Formularët (Google Forms): linku + gjendja (pa hapur / hapur / përfunduar) + njoftimet ---------- */
function journey_forms(): array {
    return [
        'pre' => ['label' => 'Pyetësori para trajnimit', 'proc' => 'Procesi I', 'step' => 'pre_survey', 'anchor' => 'p1'],
        'post' => ['label' => 'Pyetësori pas trajnimit', 'proc' => 'Procesi III', 'step' => 'post_survey', 'anchor' => 'p3'],
        'diary' => ['label' => 'Ditari i cikleve', 'proc' => 'Procesi V — Intervenimi', 'step' => null, 'anchor' => 'p5'],
    ];
}
function journey_form_link(string $k): string { return (string)setting('journey_link_' . $k); }
/** '' = ende pa hapur · 'open' = plotësohet tani · 'done' = përfunduar/mbyllur (nuk plotësohet më) */
function journey_form_state(string $k): string {
    $v = (string)setting('journey_state_' . $k);
    if ($v === '' && journey_form_link($k) !== '' && setting('journey_state_' . $k . '_set') !== '1') return 'open'; // linqet e vjetra pa gjendje
    return in_array($v, ['', 'open', 'done'], true) ? $v : '';
}
function journey_form_states(): array { return ['' => '🕓 Ende pa hapur', 'open' => '🟢 Hapur — plotësohet tani', 'done' => '✅ Përfunduar — mbyllur']; }

/** Dërgon njoftim (🔔) te mësuesit që ende duhet ta plotësojnë formularin. Kthen numrin e mësuesve të njoftuar. */
function journey_notify_form(string $k, string $dedupe): int {
    $F = journey_forms()[$k] ?? null;
    if (!$F || journey_form_state($k) !== 'open' || journey_form_link($k) === '') return 0;
    if ($F['step'] && !journey_step_enabled($F['step'])) return 0;
    $n = 0;
    foreach (qa("SELECT id FROM users WHERE role='teacher' AND active=1") as $t) {
        if ($F['step']) {
            $st = (string)qv('SELECT status FROM journey_status WHERE teacher_id=? AND step_key=?', [$t['id'], $F['step']]);
            if ($st === 'submitted' || journey_is_done($st)) continue;
        }
        $msg = $k === 'diary' ? '📓 Mos harroni ditarin e ciklit (Intervenimi) — plotësojeni pas çdo cikli.' : '📋 ' . $F['label'] . ' është hapur — ju lutem plotësojeni (' . $F['proc'] . ').';
        try { add_alert((int)$t['id'], null, 'journey', $msg, 'teacher/journey.php#' . $F['anchor'], 'jf:' . $k . ':' . $dedupe); $n++; } catch (Throwable) {}
    }
    return $n;
}

/** Kujtesat javore (thirret nga detyrat e përditshme): pyetësorët e hapur dhe ditari i intervenimit */
function journey_weekly_reminders(): int {
    $wk = date('o-W'); $n = 0;
    foreach (array_keys(journey_forms()) as $k) $n += journey_notify_form($k, 'w' . $wk);
    return $n;
}

/** Emërtimi i planit mësimor / projektit që po finalizohet në secilin cikël */
function journey_cycle_plans(int $teacherId): array {
    try { return array_column(qa('SELECT cycle, plan_title FROM journey_cycle WHERE teacher_id=?', [$teacherId]), 'plan_title', 'cycle'); }
    catch (Throwable) { return []; }
}

/** Gjendja e plotë e rrugëtimit për një mësues */
function journey_state(int $teacherId): array {
    $cycles = journey_cycles($teacherId);
    $rows = [];
    foreach (qa('SELECT * FROM journey_status WHERE teacher_id=?', [$teacherId]) as $r) $rows[$r['step_key']] = $r;
    $files = [];
    foreach (qa('SELECT id, step_key, original_name, size, uploaded_at FROM journey_files WHERE teacher_id=? ORDER BY uploaded_at', [$teacherId]) as $f) $files[$f['step_key']][] = $f;
    $phases = journey_phases($cycles);
    $total = 0; $done = 0; $current = null; $pendingReview = 0; $needsWork = 0;
    foreach ($phases as $pi => &$ph) {
        $pt = 0; $pd = 0;
        foreach ($ph['steps'] as &$s) {
            $r = $rows[$s['key']] ?? null;
            $s['status'] = $r['status'] ?? 'pending';
            $s['comment'] = $r['admin_comment'] ?? null;
            $s['qty'] = isset($r['qty']) ? (int)$r['qty'] : null;
            $s['updated_at'] = $r['updated_at'] ?? null;
            $s['reviewed_at'] = $r['reviewed_at'] ?? null;
            $s['files'] = $files[$s['key']] ?? [];
            $pt++; $total++;
            if (journey_is_done($s['status'])) { $pd++; $done++; }
            if ($s['status'] === 'submitted') $pendingReview++;
            if ($s['status'] === 'needs_work') $needsWork++;
        }
        unset($s);
        $ph['total'] = $pt; $ph['done'] = $pd; $ph['pct'] = $pt ? (int)round(100 * $pd / $pt) : 0;
        $ph['complete'] = $pt > 0 && $pd === $pt;
        if ($current === null && !$ph['complete']) $current = $pi;
    }
    unset($ph);
    return ['phases' => $phases, 'cycles' => $cycles, 'plans' => journey_cycle_plans($teacherId), 'total' => $total, 'done' => $done, 'pct' => $total ? (int)round(100 * $done / $total) : 0,
        'current' => $current, 'pending_review' => $pendingReview, 'needs_work' => $needsWork];
}

function journey_set_status(int $teacherId, string $stepKey, string $status, ?string $comment = null, ?int $reviewer = null): void {
    if (!isset(journey_statuses()[$status])) throw new RuntimeException('Status i pavlefshëm.');
    $review = in_array($status, ['done', 'done_comments', 'needs_work'], true) && $reviewer;
    q('INSERT INTO journey_status (teacher_id, step_key, status, admin_comment, reviewed_by, reviewed_at, updated_at) VALUES (?,?,?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE status=VALUES(status), admin_comment=IF(VALUES(admin_comment) IS NULL, admin_comment, VALUES(admin_comment)),
       reviewed_by=IF(VALUES(reviewed_by) IS NULL, reviewed_by, VALUES(reviewed_by)), reviewed_at=IF(VALUES(reviewed_at) IS NULL, reviewed_at, VALUES(reviewed_at)), updated_at=NOW()',
        [$teacherId, $stepKey, $status, $comment, $review ? $reviewer : null, $review ? date('Y-m-d H:i:s') : null]);
}

function journey_step_def(string $key): array {
    foreach (journey_phases(1, true) as $ph) foreach ($ph['steps'] as $s) if ($s['key'] === $key) return $s;
    return [];
}

function journey_step_exists(int $teacherId, string $stepKey): ?array {
    foreach (journey_phases(journey_cycles($teacherId)) as $ph) foreach ($ph['steps'] as $s) if ($s['key'] === $stepKey) return $s;
    return null;
}

function journey_dir(): string {
    $d = APP_ROOT . '/backups/journey';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/** Ruan skedarin e ngarkuar (dosje e bllokuar nga uebi) */
function journey_store_upload(int $teacherId, string $stepKey, array $f): int {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Zgjidhni një skedar.');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('Skedari është shumë i madh.');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Ngarkimi dështoi (kodi ' . (int)$f['error'] . ').');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, JOURNEY_EXT, true)) throw new RuntimeException('Lejohen vetëm: ' . implode(', ', JOURNEY_EXT) . '.');
    if ((int)$f['size'] > JOURNEY_MAX_MB * 1048576) throw new RuntimeException('Skedari kalon ' . JOURNEY_MAX_MB . ' MB.');
    $stored = $teacherId . '_' . preg_replace('/[^a-z0-9_]/', '', $stepKey) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], journey_dir() . '/' . $stored)) throw new RuntimeException('Skedari nuk u ruajt (kontrolloni lejet e dosjes backups).');
    $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\x{2013}\-]/u', '_', basename((string)$f['name'])), 0, 180);
    q('INSERT INTO journey_files (teacher_id, step_key, original_name, stored_name, size) VALUES (?,?,?,?,?)', [$teacherId, $stepKey, $name, $stored, (int)$f['size']]);
    return (int)db()->lastInsertId();
}

/** Shiriti i progresit (fazat si hapa) */
function journey_bar_html(array $J, bool $compact = false): string {
    $cur = $J['current'];
    ob_start(); ?>
    <div class="jr-bar" role="group" aria-label="Rrugëtimi i pilotimit">
      <div class="jr-top"><strong><?= $J['pct'] ?>%</strong> e përfunduar
        <span class="muted">· <?= $cur === null ? '🎉 Të gjitha fazat janë përfunduar' : 'Faza aktuale: <strong>' . e($J['phases'][$cur]['label'] . ' — ' . $J['phases'][$cur]['short']) . '</strong>' ?></span></div>
      <div class="jr-track"><div class="jr-fill" style="width:<?= $J['pct'] ?>%"></div></div>
      <ol class="jr-steps">
        <?php foreach ($J['phases'] as $i => $ph):
            $cls = $ph['complete'] ? 'done' : ($i === $cur ? 'cur' : ''); ?>
          <li class="<?= $cls ?>"><span class="jr-dot"><?= $ph['complete'] ? '✓' : $ph['icon'] ?></span>
            <span class="jr-lbl"><?= e($ph['label']) ?><br><small><?= e($ph['short']) ?><?= $compact ? '' : ' · ' . $ph['done'] . '/' . $ph['total'] ?></small></span></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php return (string)ob_get_clean();
}

function journey_css(): string {
    return '<style>
.jr-bar{background:var(--surface,#fff);border:1px solid var(--line,#e2e7f0);border-radius:14px;padding:14px 16px;margin-bottom:16px}
.jr-top{margin-bottom:8px}.jr-track{height:12px;background:#eef1f6;border-radius:8px;overflow:hidden}.jr-fill{height:100%;background:linear-gradient(90deg,#00AEEF,#0A7CC1)}
.jr-steps{list-style:none;display:flex;gap:6px;padding:0;margin:12px 0 0;flex-wrap:wrap}.jr-steps li{flex:1;min-width:110px;display:flex;gap:8px;align-items:center;padding:8px;border-radius:10px;background:#f6f8fb;font-size:.85rem}
.jr-steps li.done{background:#e3f6ec}.jr-steps li.cur{background:#e6f4fc;outline:2px solid #00AEEF}
.jr-dot{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:#fff;border:1px solid #d5dbe6;flex:none}.jr-steps li.done .jr-dot{background:#14804a;color:#fff;border-color:#14804a}
.jr-phase{margin-bottom:14px}.jr-step{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:start;padding:10px 0;border-top:1px solid var(--line,#e2e7f0)}
.jr-st{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8rem;font-weight:700;white-space:nowrap}
.jr-files{font-size:.85rem;margin-top:4px}.jr-comment{margin-top:6px;padding:6px 10px;border-left:3px solid #0A7CC1;background:#f3f9fd;font-size:.88rem}
.jr-cycles{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}.jr-cycle{border:1px solid var(--line,#e2e7f0);border-radius:10px;padding:10px}
.jr-sub{display:flex;gap:6px;align-items:center;justify-content:space-between;margin-top:6px;padding:4px 6px;border-radius:8px;background:#f6f8fb}.jr-sub.ok{background:#e3f6ec}
.jr-sub .jr-up{display:none}.jr-sub label.btn{margin:0;cursor:pointer}.jr-sub-files{font-size:.8rem;margin:2px 0 0 26px}
.jr-plan{width:100%;margin-top:6px;font-size:.88rem}
.jr-mini{height:8px;background:#eef1f6;border-radius:6px;overflow:hidden;min-width:120px}.jr-mini>div{height:100%;background:#00AEEF}
</style>';
}

function journey_status_badge(string $st): string {
    [$l, $c, $bg, $ic] = journey_statuses()[$st] ?? journey_statuses()['pending'];
    return '<span class="jr-st" style="color:' . $c . ';background:' . $bg . '">' . $ic . ' ' . e($l) . '</span>';
}

/** Lista e fazave dhe hapave me veprimet (për mësuesin ose për administratorin) */
function journey_detail_html(array $J, int $teacherId, string $mode): string {
    $admin = $mode === 'admin';
    $st = journey_statuses();
    $hid = $admin ? '<input type="hidden" name="teacher" value="' . $teacherId . '">' : '';
    ob_start();
    foreach ($J['phases'] as $i => $ph): ?>
      <section class="card jr-phase" id="<?= e($ph['key']) ?>">
        <div class="row-between"><h2 style="margin:0"><?= $ph['icon'] ?> <?= e($ph['label']) ?> — <?= e($ph['short']) ?></h2>
          <span class="<?= $ph['complete'] ? 'badge' : 'muted' ?>"><?= $ph['complete'] ? '✅ Përfunduar' : $ph['done'] . ' / ' . $ph['total'] ?></span></div>
        <?php if (!empty($ph['diary'])): $dst = journey_form_state('diary'); $diary = journey_form_link('diary'); ?>
          <?php if ($dst === 'done'): ?><div class="small muted" style="margin:8px 0">🔒 Ditari i cikleve është mbyllur — nuk plotësohet më.</div><?php elseif ($dst !== 'open' || !$diary): ?><div class="small muted" style="margin:8px 0">🕓 Linku i ditarit ende nuk është hapur — do të njoftoheni te 🔔.</div><?php endif; ?>
          <form method="post" class="row" style="margin-bottom:10px"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="cycles">
            <label class="small" style="margin:0">Numri i cikleve <input type="number" name="cycles" min="1" max="30" value="<?= (int)$J['cycles'] ?>" style="width:80px"></label>
            <button class="btn btn-sm">Ruaj</button><span class="small muted">Çdo cikël: pre-test → aktiviteti me planin mësimor → post-test → 📓 ditari i ciklit.</span></form>
          <datalist id="jr-plans"><?php foreach ($J['phases'] as $pp) foreach ($pp['steps'] as $ss) if ($ss['key'] === 'lesson_plans') foreach ($ss['files'] as $f): ?><option value="<?= e(pathinfo($f['original_name'], PATHINFO_FILENAME)) ?>"><?php endforeach; ?></datalist>
          <div class="jr-cycles">
          <?php foreach (array_chunk($ph['steps'], 4) as $trio): $c = $trio[0]['cycle']; $cd = count(array_filter($trio, fn($s) => journey_is_done($s['status']))); ?>
            <div class="jr-cycle" id="cikli-<?= $c ?>"><div class="row-between"><strong>🔁 Cikli <?= $c ?></strong> <span class="small <?= $cd === count($trio) ? '' : 'muted' ?>"><?= $cd === count($trio) ? '✅ ' : '' ?><?= $cd ?>/<?= count($trio) ?></span></div>
              <form method="post" style="margin:0"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="plan"><input type="hidden" name="cycle" value="<?= $c ?>">
                <input class="jr-plan" name="plan_title" list="jr-plans" maxlength="190" value="<?= e((string)($J['plans'][$c] ?? '')) ?>" placeholder="📘 Plani mësimor / projekti (p.sh. Plani <?= $c ?> – …)" onchange="this.form.submit()" title="Cili plan mësimor nga projekti i dorëzuar po zbatohet / finalizohet në këtë cikël"></form>
              <?php foreach ($trio as $s): $short = trim(substr($s['label'], strpos($s['label'], '·') + 2)); $ok = journey_is_done($s['status']); ?>
                <div class="jr-sub <?= $ok ? 'ok' : '' ?>">
                  <form method="post" style="margin:0"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="tick"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                    <label class="checkline" style="margin:0;font-size:.9rem"><input type="checkbox" name="on" value="1" <?= $ok ? 'checked' : '' ?> onchange="this.form.submit()"> <?= !empty($s['diary']) ? '📓 ' : '' ?><?= e($short) ?></label></form>
                  <?php if (!empty($s['diary'])): ?>
                    <?php if ($dst === 'open' && $diary): ?><a class="btn btn-sm btn-primary" href="<?= e($diary) ?>" target="_blank" rel="noopener" title="Plotëso ditarin e ciklit <?= $c ?>">📝 Plotëso</a>
                    <?php elseif ($dst === 'done'): ?><span class="small muted">🔒</span><?php else: ?><span class="small muted" title="Ende pa hapur">🕓</span><?php endif; ?>
                  <?php else: ?>
                  <form method="post" enctype="multipart/form-data" style="margin:0"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="upload"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                    <label class="btn btn-ghost btn-sm" title="Ngarko mostrën (<?= e($short) ?>)">📎<?= $s['files'] ? ' ' . count($s['files']) : '' ?><input class="jr-up" type="file" name="doc" accept=".<?= implode(',.', JOURNEY_EXT) ?>" onchange="this.form.submit()"></label></form>
                  <?php endif; ?>
                </div>
                <?php foreach ($s['files'] as $f): ?><div class="jr-sub-files">📄 <a href="<?= e(url('journey_file.php?id=' . $f['id'])) ?>"><?= e($f['original_name']) ?></a>
                  <form method="post" class="inline" data-confirm="Ta fshij skedarin?"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="delfile"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-ghost btn-sm" title="Fshi">🗑️</button></form></div><?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          </div>
        <?php else: foreach ($ph['steps'] as $s):
            $fk = $s['form'] ?? null; $fst = $fk ? journey_form_state($fk) : ''; $link = $fk && $fst === 'open' ? journey_form_link($fk) : ''; ?>
          <div class="jr-step">
            <div><strong><?= e($s['label']) ?></strong> <?= journey_status_badge($s['status']) ?>
              <?php if (!empty($s['detail'])): ?><div style="margin-top:2px">📅 <strong><?= e($s['detail']) ?></strong></div><?php endif; ?>
              <?php if ($s['key'] === 'training'): foreach (tcert_teacher_list($teacherId) as $tr): ?>
                <div style="margin-top:4px"><a class="btn btn-sm btn-primary" target="_blank" href="<?= e(url('teacher_certificate.php?training=' . $tr['id'] . ($admin ? '&teacher=' . $teacherId : ''))) ?>">🎓 Certifikata<?= tcert_merge_all() ? ' e trajnimit · ' : ' · ' ?><?= e(mb_strimwidth($tr['title'], 0, 50, '…')) ?></a></div>
              <?php endforeach; endif; ?>
              <?php if ($s['key'] === 'training' && ($mats = tmat_for_teacher($teacherId))): ?>
                <details class="small" style="margin-top:6px" open><summary><strong>📚 Materialet e trajnimit (<?= count($mats) ?>)</strong></summary>
                  <?php foreach ($mats as $m): ?><div style="margin-top:3px"><?= tmat_icon($m['original_name']) ?> <a href="<?= e(url('training_material.php?id=' . $m['id'])) ?>" target="_blank"><?= e($m['title'] ?: $m['original_name']) ?></a>
                    <span class="muted">· <?= tmat_size((int)$m['size']) ?> · <?= e(mb_strimwidth($m['training_title'], 0, 40, '…')) ?></span>
                    <a class="muted" href="<?= e(url('training_material.php?id=' . $m['id'] . '&dl=1')) ?>" title="Shkarko">⬇️</a></div><?php endforeach; ?>
                </details>
              <?php endif; ?>
              <?php if (!empty($s['help'])): ?><div class="small muted"><?= e($s['help']) ?></div><?php endif; ?>
              <?php if ($link): ?><div style="margin-top:4px"><a class="btn btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">🔗 Hape pyetësorin</a></div>
              <?php elseif ($fk && $fst === 'done'): ?><div class="small" style="margin-top:4px">🔒 Pyetësori është mbyllur — nuk plotësohet më.</div>
              <?php elseif ($fk): ?><div class="small muted" style="margin-top:4px">🕓 Pyetësori ende nuk është hapur — do të njoftoheni te 🔔 kur të hapet.</div>
              <?php elseif (($su = journey_step_url($s['key'])) !== ''): ?><div style="margin-top:4px"><a class="btn btn-sm" href="<?= e($su) ?>" target="_blank" rel="noopener">🔗 Hape pyetësorin</a></div><?php endif; ?>
              <?php if (!empty($s['consent']) && journey_is_done($s['status'])): ?><div class="jr-comment" style="border-color:#14804a;background:#eef9f2">✅ <strong>Administratori:</strong> <?= e($s['consent']) ?><?= !empty($s['qty']) ? ' — <strong>' . (int)$s['qty'] . ' gjithsej</strong>' : '' ?><?= $s['updated_at'] ? ' · ' . e(fmt_dt($s['updated_at'], false)) : '' ?></div><?php endif; ?>
              <?php if ($s['files']): ?><div class="jr-files"><?php foreach ($s['files'] as $f): ?>
                <div>📄 <a href="<?= e(url('journey_file.php?id=' . $f['id'])) ?>"><?= e($f['original_name']) ?></a> <span class="muted">· <?= $f['size'] >= 1048576 ? round($f['size'] / 1048576, 1) . ' MB' : max(1, round($f['size'] / 1024)) . ' KB' ?> · <?= e(fmt_dt($f['uploaded_at'])) ?></span>
                  <?php if ($admin || !journey_is_done($s['status'])): ?><form method="post" class="inline" data-confirm="Ta fshij skedarin?"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="delfile"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-ghost btn-sm" title="Fshi">🗑️</button></form><?php endif; ?></div>
              <?php endforeach; ?></div><?php endif; ?>
              <?php if ($s['comment']): ?><div class="jr-comment">💬 <strong>Komenti i administratorit:</strong> <?= nl2br(e($s['comment'])) ?></div><?php endif; ?>
            </div>
            <div style="min-width:240px">
              <?php if ($s['who'] === 'upload' && !($admin && !empty($s['consent'])) && ($admin || !journey_is_done($s['status']))): ?>
                <form method="post" enctype="multipart/form-data" class="form" style="gap:6px"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="upload"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                  <input type="file" name="doc" required accept=".<?= implode(',.', JOURNEY_EXT) ?>">
                  <button class="btn btn-sm <?= $admin ? '' : 'btn-primary' ?>">⬆️ Ngarko</button></form>
              <?php elseif ($s['who'] === 'confirm' && !$admin && $fst === 'open' && in_array($s['status'], ['pending', 'needs_work'], true)): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                  <button class="btn btn-sm btn-primary">✔ E kam plotësuar</button></form>
              <?php endif; ?>
              <?php if ($admin && !empty($s['consent'])): ?>
                <form method="post" enctype="multipart/form-data" class="form" style="gap:6px;margin:0 0 6px"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="consent"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                  <?php if ($s['status'] === 'submitted'): ?><div class="small" style="color:#a15c07">⏳ Mësuesi/ja i ka ngarkuar — kontrollojini dhe aprovojini.</div><?php endif; ?>
                  <label class="checkline" style="margin:0"><input type="checkbox" name="on" value="1" <?= journey_is_done($s['status']) ? 'checked' : '' ?>> <strong>✔ Aprovo · <?= e($s['consent']) ?></strong></label>
                  <?php if (!empty(journey_step_def($s['key'])['qty'])): ?><label class="small" style="margin:0">Numri gjithsej <input type="number" name="qty" min="0" max="999" value="<?= $s['qty'] !== null ? (int)$s['qty'] : '' ?>" style="width:90px" placeholder="p.sh. 23"></label><?php endif; ?>
                  <label class="small" style="margin:0">📎 Skedari (opsional) <input type="file" name="doc" accept=".<?= implode(',.', JOURNEY_EXT) ?>"></label>
                  <button class="btn btn-sm btn-primary">💾 Ruaj</button></form>
              <?php elseif ($admin): ?>
                <form method="post" style="margin:0 0 6px"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="tick"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                  <label class="checkline" style="margin:0"><input type="checkbox" name="on" value="1" <?= journey_is_done($s['status']) ? 'checked' : '' ?> onchange="this.form.submit()"> <strong>Përfunduar</strong></label></form>
                <form method="post" class="form" style="gap:6px;margin-top:8px;border-top:1px dashed var(--line);padding-top:8px"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="review"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                  <select name="status"><?php foreach ($st as $k => [$l]): ?><option value="<?= $k ?>" <?= $s['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                  <textarea name="comment" rows="2" placeholder="Komenti për mësuesin (opsional)"><?= e((string)$s['comment']) ?></textarea>
                  <button class="btn btn-sm btn-primary">💾 Ruaj vlerësimin</button></form>
              <?php endif; ?>
              <?php if ($admin && !empty($s['consent'])): ?>
                <details class="small" style="margin-top:6px"><summary>💬 Koment për mësuesin</summary>
                <form method="post" class="form" style="gap:6px;margin-top:6px"><?= csrf_field() ?><?= $hid ?><input type="hidden" name="action" value="review"><input type="hidden" name="step" value="<?= e($s['key']) ?>">
                  <select name="status"><?php foreach ($st as $k => [$l]): ?><option value="<?= $k ?>" <?= $s['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                  <textarea name="comment" rows="2" placeholder="p.sh. Mungojnë 2 pëlqime"><?= e((string)$s['comment']) ?></textarea>
                  <button class="btn btn-sm">💾 Ruaj</button></form></details>
              <?php elseif ($s['who'] === 'admin'): ?><span class="small muted">Shënohet nga administratori.</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </section>
    <?php endforeach;
    return (string)ob_get_clean();
}

/** Veprimet e formularëve (të përbashkëta për mësuesin dhe administratorin) */
function journey_handle_post(array $actor, int $teacherId): void {
    $admin = $actor['role'] === 'admin';
    $a = post('action');
    $step = (string)post('step');
    if (in_array($a, ['upload', 'tick', 'review', 'confirm', 'consent'], true)) {
        $s = journey_step_exists($teacherId, $step);
        if (!$s) throw new RuntimeException('Hapi nuk u gjet.');
    }
    if ($a === 'upload') {
        // ngarkimet lejohen vetëm te Projekti dhe Intervenimi
        if (!in_array($s['who'], ['upload', 'self'], true) && !($admin && $s['who'] === 'admin')) throw new RuntimeException('Në këtë hap nuk ngarkohen dokumente.');
        journey_store_upload($teacherId, $step, $_FILES['doc'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!$admin && $s['who'] === 'upload') journey_set_status($teacherId, $step, 'submitted');
        audit('journey.upload', $step, (string)($_FILES['doc']['name'] ?? ''));
        flash('ok', $s['who'] === 'self' ? '📎 Mostra u ngarkua.' : 'U dorëzua. Administratori do ta shqyrtojë së shpejti.');
    } elseif ($a === 'consent') {
        if (!$admin || empty($s['consent'])) throw new RuntimeException('Vetëm administratori.');
        $on = (bool)post('on');
        $qty = !empty($s['qty']) && post('qty') !== '' ? max(0, min(999, posti('qty'))) : null;
        $has = isset($_FILES['doc']) && ($_FILES['doc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($has) journey_store_upload($teacherId, $step, $_FILES['doc']);
        $hasFiles = (bool)qv('SELECT 1 FROM journey_files WHERE teacher_id=? AND step_key=?', [$teacherId, $step]);
        journey_set_status($teacherId, $step, $on ? 'done' : ($hasFiles ? 'submitted' : 'pending'), null, (int)$actor['id']);
        q('UPDATE journey_status SET qty=? WHERE teacher_id=? AND step_key=?', [$qty, $teacherId, $step]);
        if ($on) try { add_alert($teacherId, null, 'journey', '✅ ' . $s['consent'] . ($qty ? " ($qty gjithsej)" : ''), 'teacher/journey.php#p0', 'jc:' . $step . ':' . time()); } catch (Throwable) {}
        audit('journey.review', $step, ($on ? $s['consent'] : 'hequr') . ($qty ? " · $qty" : '') . ' · mësuesi #' . $teacherId);
        flash('ok', $on ? '✅ Aprovuar: ' . $s['consent'] . ($qty ? " — $qty gjithsej" : '') . '.' : 'Shënimi u hoq.');
    } elseif ($a === 'confirm') {
        if (($s['form'] ?? null) && journey_form_state($s['form']) !== 'open') throw new RuntimeException('Pyetësori nuk është i hapur.');
        if ($s['who'] !== 'confirm') throw new RuntimeException('Veprim i pavlefshëm.');
        if (!$admin) journey_set_status($teacherId, $step, 'submitted');
        audit('journey.upload', $step, 'konfirmim');
        flash('ok', 'Faleminderit! Administratori do ta konfirmojë.');
    } elseif ($a === 'plan') {
        $c = posti('cycle');
        if ($c < 1 || $c > journey_cycles($teacherId)) throw new RuntimeException('Cikli nuk u gjet.');
        $t = mb_substr(trim((string)post('plan_title')), 0, 190);
        q('INSERT INTO journey_cycle (teacher_id, cycle, plan_title) VALUES (?,?,?) ON DUPLICATE KEY UPDATE plan_title=VALUES(plan_title)', [$teacherId, $c, $t]);
        flash('ok', "Cikli $c: " . ($t !== '' ? "«{$t}»" : 'emërtimi u hoq') . '.');
    } elseif ($a === 'tick') {
        if (!$admin && $s['who'] !== 'self') throw new RuntimeException('Ky hap shënohet nga administratori.');
        journey_set_status($teacherId, $step, post('on') ? 'done' : 'pending', null, $admin ? (int)$actor['id'] : null);
        flash('ok', post('on') ? '✔ U shënua si i kryer.' : 'U hoq shënimi.');
    } elseif ($a === 'review') {
        if (!$admin) throw new RuntimeException('Vetëm administratori.');
        $status = (string)post('status');
        if (!isset(journey_statuses()[$status])) throw new RuntimeException('Statusi nuk është i vlefshëm.');
        journey_set_status($teacherId, $step, $status, trim((string)post('comment')) ?: '', (int)$actor['id']);
        if ($status !== 'pending') {
            $msg = ['needs_work' => '⚠️ Ka nevojë për punë: ', 'done' => '✅ Përfunduar me sukses: ', 'done_comments' => '✔ Përfunduar me komente: ', 'submitted' => '⏳ Në shqyrtim: '][$status] . $s['label'];
            try { add_alert($teacherId, null, 'journey', $msg, 'teacher/journey.php', 'jr:' . $step . ':' . time()); } catch (Throwable) {}
        }
        audit('journey.review', $step, (journey_statuses()[$status][0] ?? $status) . ' · mësuesi #' . $teacherId);
        flash('ok', 'Vlerësimi u ruajt: ' . (journey_statuses()[$status][0] ?? $status) . '.');
    } elseif ($a === 'cycles') {
        $n = max(1, min(30, posti('cycles')));
        q('INSERT INTO journey_teacher (teacher_id, cycles) VALUES (?,?) ON DUPLICATE KEY UPDATE cycles=VALUES(cycles), updated_at=NOW()', [$teacherId, $n]);
        flash('ok', "Numri i cikleve u vendos: $n.");
    } elseif ($a === 'delfile') {
        $f = q1('SELECT * FROM journey_files WHERE id=? AND teacher_id=?', [posti('id'), $teacherId]);
        if (!$f) throw new RuntimeException('Skedari nuk u gjet.');
        if (!$admin && journey_is_done((string)qv('SELECT status FROM journey_status WHERE teacher_id=? AND step_key=?', [$teacherId, $f['step_key']]))) throw new RuntimeException('Hapi është përfunduar; skedari nuk mund të fshihet.');
        @unlink(journey_dir() . '/' . $f['stored_name']);
        q('DELETE FROM journey_files WHERE id=?', [$f['id']]);
        flash('ok', 'Skedari u fshi.');
    }
}
