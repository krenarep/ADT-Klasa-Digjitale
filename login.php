<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect(home_for(current_user()['role']));

$mode = (picture_login_enabled() && get('mode', 'pass') === 'pic') ? 'pic' : 'pass';
$error = '';
$class = null;
$students = [];
$picked = null;

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'password') {
        $r = login_password(post('username'), (string)($_POST['password'] ?? ''));
        if (is_array($r)) redirect(home_for($r['role']));
        $error = $r;
    } elseif (picture_login_enabled() && ($action === 'code' || $action === 'pick' || $action === 'picture')) {
        $mode = 'pic';
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('class_code')));
        $class = $code ? q1('SELECT c.*, s.name AS school_name FROM classes c JOIN schools s ON s.id=c.school_id WHERE c.class_code = ? AND c.picture_login = 1', [$code]) : null;
        if (!$class) {
            $error = 'Ky kod klase nuk ekziston. Kontrollo me mësuesin.';
        } else {
            $students = qa('SELECT u.id, u.full_name, u.avatar FROM users u JOIN class_students cs ON cs.student_id=u.id
                            WHERE cs.class_id = ? AND u.active = 1 AND u.picture_hash IS NOT NULL ORDER BY u.full_name', [$class['id']]);
            if ($action === 'pick' || $action === 'picture') {
                $sid = posti('student_id');
                foreach ($students as $s) if ((int)$s['id'] === $sid) $picked = $s;
                if (!$picked) $error = 'Zgjidh emrin tënd.';
            }
            if ($action === 'picture' && $picked) {
                $r = login_picture($code, (int)$picked['id'], post('seq'));
                if (is_array($r)) redirect(home_for($r['role']));
                $error = $r;
            }
        }
    }
}

function short_name(string $n): string {
    if (anon_is_code($n)) return 'Nr. ' . (int)substr($n, strrpos($n, '-') + 1);
    $p = preg_split('/\s+/u', trim($n));
    return $p[0] . (count($p) > 1 ? ' ' . mb_substr(end($p), 0, 1) . '.' : '');
}

layout_start('Hyrje', ['body_class' => 'login-page']);
?>
<div class="login-wrap">
  <section class="login-hero" aria-hidden="true">
    <div class="hero-emoji">📘✏️🌈</div>
    <h2><?= e(cfg('app_name')) ?></h2>
    <p>Libra digjitalë, ushtrime dhe ndihmë — për nxënësit e klasave 3–5, mësuesit dhe shkollat.</p>
  </section>

  <section class="card login-card">
    <?= i18n_switcher_html('buttons') ?>
    <?php if (picture_login_enabled()): ?>
    <div class="tabs" role="tablist">
      <a role="tab" href="?mode=pass" class="<?= $mode === 'pass' ? 'active' : '' ?>" aria-selected="<?= $mode === 'pass' ? 'true' : 'false' ?>">🔑 Me fjalëkalim</a>
      <a role="tab" href="?mode=pic" class="<?= $mode === 'pic' ? 'active' : '' ?>" aria-selected="<?= $mode === 'pic' ? 'true' : 'false' ?>">🖼️ Me fotografi</a>
    </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="flash flash-err" role="alert">⚠️ <?= e($error) ?></div><?php endif; ?>

    <?php if ($mode === 'pass'): ?>
      <form method="post" class="form" autocomplete="on">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Emri i përdoruesit
          <input name="username" required autofocus autocomplete="username" value="<?= e(post('username')) ?>" autocapitalize="none" spellcheck="false">
        </label>
        <label>Fjalëkalimi
          <span class="pw-wrap"><input type="password" name="password" required autocomplete="current-password" id="pw">
          <button type="button" class="btn btn-ghost btn-sm" data-showpw="pw" aria-label="Shfaq fjalëkalimin">👁️</button></span>
        </label>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Hyr ➜</button>
        <p class="muted small"><a href="privacy.php">🔒 Privatësia</a> · Për nxënës, mësues dhe administratorë.<?= picture_login_enabled() ? ' Nxënësit e vegjël mund të hyjnë edhe <a href="?mode=pic">me fotografi</a>.' : '' ?></p>
        <p class="muted small" style="margin-top:4px">💡 Ideuar dhe krijuar nga <a href="https://github.com/krenarep" target="_blank" rel="noopener">Krenare Pireva Nuci</a></p>
      </form>

    <?php elseif (!$class): ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="code">
        <p class="kid-say">👋 Shkruaj <strong>kodin e klasës</strong> që të dha mësuesi.</p>
        <label class="sr-only" for="cc">Kodi i klasës</label>
        <input id="cc" name="class_code" class="code-input" maxlength="6" required autofocus autocomplete="off" autocapitalize="characters" placeholder="ABC123" value="<?= e(post('class_code')) ?>">
        <button class="btn btn-primary btn-lg btn-block" type="submit">Vazhdo ➜</button>
      </form>

    <?php elseif (!$picked): ?>
      <p class="kid-say">🙋 Kush je ti? Shtyp emrin tënd. <small class="muted">(<?= e($class['name']) ?> · <?= e($class['school_name']) ?>)</small></p>
      <?php if (!$students): ?>
        <p class="muted">Kjo klasë nuk ka ende nxënës me hyrje me foto.</p>
      <?php endif; ?>
      <div class="pick-grid">
        <?php foreach ($students as $s): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pick">
            <input type="hidden" name="class_code" value="<?= e($class['class_code']) ?>">
            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
            <button class="pick-btn" type="submit"><span class="avatar-lg" aria-hidden="true"><?= e($s['avatar'] ?: '🙂') ?></span><?= e(short_name($s['full_name'])) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
      <p><a href="?mode=pic">↩ Kod tjetër klase</a></p>

    <?php else: ?>
      <form method="post" class="pic-login" id="picForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="picture">
        <input type="hidden" name="class_code" value="<?= e($class['class_code']) ?>">
        <input type="hidden" name="student_id" value="<?= (int)$picked['id'] ?>">
        <input type="hidden" name="seq" id="seq">
        <p class="kid-say"><span class="avatar-lg" aria-hidden="true"><?= e($picked['avatar'] ?: '🙂') ?></span> Përshëndetje, <strong><?= e(short_name($picked['full_name'])) ?></strong>! Shtyp <strong>3 fotot e tua</strong> me radhë.</p>
        <div class="seq-dots" aria-live="polite" id="seqDots"><span></span><span></span><span></span></div>
        <div class="pic-grid">
          <?php foreach (picture_set() as $i => [$emo, $name]): ?>
            <button type="button" class="pic-btn" data-pic="<?= $i ?>" aria-label="<?= e($name) ?>"><span aria-hidden="true"><?= $emo ?></span><small><?= e($name) ?></small></button>
          <?php endforeach; ?>
        </div>
        <div class="row-center">
          <button type="button" class="btn btn-ghost" id="seqClear">⌫ Fshi</button>
        </div>
      </form>
      <p><a href="?mode=pic">↩ Nuk jam unë</a></p>
    <?php endif; ?>
  </section>
</div>
<?php layout_end();
