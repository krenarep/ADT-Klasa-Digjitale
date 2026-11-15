<?php
declare(strict_types=1);

/**
 * Kodimi (AES-256-GCM) i fjalëkalimeve fillestare të nxënësve dhe i sekuencës së fotove,
 * që ruhen vetëm për printimin e kartelave të hyrjes. Hyrja vetë verifikohet me hash (bcrypt).
 * Çelësi ruhet jashtë bazës: backups/.app_key (i bllokuar nga uebi). Kur zhvendosni serverin, kopjojeni edhe këtë skedar.
 */
function app_secret_key(): ?string {
    static $key = null;
    if ($key !== null) return $key ?: null;
    $f = APP_ROOT . '/backups/.app_key';
    if (!is_file($f)) {
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
        @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
    }
    $hex = is_file($f) ? trim((string)file_get_contents($f)) : '';
    $key = strlen($hex) === 64 ? hex2bin($hex) : '';
    return $key ?: null;
}

function enc_hint(?string $plain): ?string {
    if ($plain === null || $plain === '' || str_starts_with($plain, 'enc:')) return $plain;
    $k = app_secret_key();
    if (!$k || !function_exists('openssl_encrypt')) return $plain;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    return $ct === false ? $plain : 'enc:' . base64_encode($iv . $tag . $ct);
}

/** Kthen tekstin e hapur; null nëse s'mund të dekodohet (p.sh. çelësi mungon pas zhvendosjes) */
function dec_hint(?string $v): ?string {
    if ($v === null || $v === '' || !str_starts_with($v, 'enc:')) return $v;
    $k = app_secret_key();
    $raw = base64_decode(substr($v, 4), true);
    if (!$k || $raw === false || strlen($raw) < 29) return null;
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? null : $out;
}

/** Kodon një herë të gjitha fjalëkalimet/fotot që ruhen ende si tekst i hapur */
function encrypt_existing_hints(): int {
    if (!app_secret_key()) return 0;
    $n = 0;
    foreach (qa("SELECT id, plain_hint, picture_hint FROM users WHERE role='student' AND ((plain_hint IS NOT NULL AND plain_hint NOT LIKE 'enc:%') OR (picture_hint IS NOT NULL AND picture_hint NOT LIKE 'enc:%'))") as $r) {
        q('UPDATE users SET plain_hint=?, picture_hint=? WHERE id=?', [enc_hint($r['plain_hint']), enc_hint($r['picture_hint']), $r['id']]);
        $n++;
    }
    return $n;
}

/** Shfaqje e fshehur me buton 👁 (për listat) */
function secret_html(?string $plain, string $label = 'Shfaq'): string {
    if ($plain === null || $plain === '') return '<span class="muted">••••</span>';
    return '<span class="secret" data-secret="' . e($plain) . '"><code>••••••</code> <button type="button" class="btn btn-ghost btn-sm" onclick="var s=this.parentNode,c=s.querySelector(\'code\');c.textContent=c.textContent===\'••••••\'?s.dataset.secret:\'••••••\';" title="' . e($label) . '" aria-label="' . e($label) . '">👁</button></span>';
}
