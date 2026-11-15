<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('admin', 'teacher');

if (is_post()) {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $rep = (string)($_POST['repeat'] ?? '');
    $email = post('email');
    $phone = post('phone');
    if (!password_verify($cur, $u['password_hash'])) {
        flash('err', 'Fjalëkalimi aktual është i gabuar.');
    } elseif ($u['role'] === 'teacher' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || normalize_phone((string)$phone) === null)) {
        flash('err', 'Email-i dhe telefoni janë të detyrueshëm dhe duhet të jenë të vlefshëm.');
    } elseif ($new !== '' && (strlen($new) < 8 || $new !== $rep)) {
        flash('err', 'Fjalëkalimi i ri duhet të ketë së paku 8 karaktere dhe të përputhet me përsëritjen.');
    } else {
        q('UPDATE users SET email = ?, phone = ? WHERE id = ?', [$email ?: null, normalize_phone((string)$phone), $u['id']]);
        if ($new !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        }
        flash('ok', 'Profili u përditësua.');
    }
    redirect('profile.php');
}

layout_start('Profili im');
?>
<div class="grid-2">
  <section class="card">
    <h2>👤 Të dhënat</h2>
    <dl class="kv">
      <dt>Emri</dt><dd><?= e($u['full_name']) ?></dd>
      <dt>Përdoruesi</dt><dd><code><?= e($u['username']) ?></code></dd>
      <dt>Roli</dt><dd><?= $u['role'] === 'admin' ? 'Administrator' : 'Mësues' ?></dd>
      <dt>Shkolla</dt><dd><?= e($u['school_name'] ?? '—') ?></dd>
      <?php if ($u['role'] === 'teacher'): ?><dt>Komuna</dt><dd><?= e($u['municipality'] ?: '—') ?> <span class="small muted">(ndryshohet nga administratori)</span></dd><?php endif; ?>
      <dt>Hyrja e fundit</dt><dd><?= e(fmt_dt($u['last_login'])) ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2>🔒 Ndrysho fjalëkalimin</h2>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Email<?= $u['role'] === 'teacher' ? ' *' : '' ?><input type="email" name="email" value="<?= e($u['email']) ?>" <?= $u['role'] === 'teacher' ? 'required' : '' ?>></label>
      <label>Telefoni<?= $u['role'] === 'teacher' ? ' *' : '' ?><input type="tel" name="phone" value="<?= e($u['phone']) ?>" placeholder="+383 44 123 456" <?= $u['role'] === 'teacher' ? 'required' : '' ?>></label>
      <label>Fjalëkalimi aktual *<input type="password" name="current" required autocomplete="current-password"></label>
      <label>Fjalëkalimi i ri (min. 8)<input type="password" name="new" minlength="8" autocomplete="new-password"></label>
      <label>Përsërite<input type="password" name="repeat" autocomplete="new-password"></label>
      <button class="btn btn-primary">Ruaj</button>
    </form>
  </section>
</div>
<?php layout_end();
