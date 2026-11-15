<?php
// 🌐 Gjuhët e ndërfaqes — shto gjuhë dhe përkthe tekstet e platformës
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');
$lang = (string)get('edit');   // gjuha që po përkthehet (?lang= është ndërruesi i gjuhës së ndërfaqes)
$langs = i18n_languages(false);

if (is_post()) {
    csrf_check();
    $a = post('action');
    try {
        if ($a === 'add') {
            $code = strtolower(trim((string)post('code')));
            if (!i18n_valid_code($code)) throw new RuntimeException('Kodi i gjuhës duhet të jetë si: en, sr, tr, bs, rom, sr-cyrl.');
            if (isset($langs[$code])) throw new RuntimeException('Kjo gjuhë ekziston.');
            $name = mb_substr(trim((string)post('name')), 0, 60);
            if ($name === '') throw new RuntimeException('Shkruani emrin e gjuhës (p.sh. Srpski, Türkçe).');
            q('INSERT INTO i18n_languages (code, name, flag, dir, active) VALUES (?,?,?,?,1)', [$code, $name, mb_substr(trim((string)post('flag')) ?: '🌐', 0, 16), post('dir') === 'rtl' ? 'rtl' : 'ltr']);
            audit('settings', 'Gjuha: ' . $name, 'u shtua');
            flash('ok', "Gjuha «{$name}» u shtua. Tani përkthejini tekstet.");
            redirect('admin/languages.php?edit=' . $code);
        } elseif ($a === 'toggle' && post('code') !== I18N_SOURCE) {
            q('UPDATE i18n_languages SET active = 1 - active WHERE code=?', [post('code')]);
        } elseif ($a === 'default') {
            if (isset($langs[post('code')])) { set_setting('default_lang', (string)post('code')); flash('ok', 'Gjuha e paracaktuar u ndryshua.'); }
        } elseif ($a === 'delete' && post('code') !== I18N_SOURCE) {
            q('DELETE FROM i18n_translations WHERE lang=?', [post('code')]);
            q('DELETE FROM i18n_languages WHERE code=?', [post('code')]);
            if (setting('default_lang') === post('code')) set_setting('default_lang', I18N_SOURCE);
            flash('ok', 'Gjuha u fshi.');
        } elseif ($a === 'edit_lang' && isset($langs[post('code')])) {
            q('UPDATE i18n_languages SET name=?, flag=?, dir=? WHERE code=?', [mb_substr(trim((string)post('name')), 0, 60) ?: post('code'), mb_substr(trim((string)post('flag')) ?: '🌐', 0, 16), post('dir') === 'rtl' ? 'rtl' : 'ltr', post('code')]);
            flash('ok', 'U ruajt.');
            redirect('admin/languages.php?edit=' . post('code'));
        } elseif ($a === 'scan') {
            [$tot, $new] = i18n_scan_sources();
            foreach (glob(APP_ROOT . '/inc/lang/*.json') ?: [] as $pf) i18n_import_pack(basename($pf, '.json'), $pf);
            flash('ok', "🔄 U skanuan tekstet e platformës: $tot gjithsej, $new të reja.");
        } elseif ($a === 'collect') {
            $_SESSION['i18n_collect'] = empty($_SESSION['i18n_collect']);
            flash('ok', $_SESSION['i18n_collect'] ? '🔍 Mbledhja e teksteve u ndez: shfletoni faqet e platformës dhe tekstet që mungojnë shtohen vetë.' : 'Mbledhja e teksteve u fik.');
        } elseif ($a === 'save' && isset($langs[$lang]) && $lang !== I18N_SOURCE) {
            $n = 0;
            foreach ((array)($_POST['tr'] ?? []) as $sid => $dst) {
                $dst = trim(str_replace("\r", '', (string)$dst));
                if ($dst === '') { q('DELETE FROM i18n_translations WHERE lang=? AND string_id=?', [$lang, (int)$sid]); continue; }
                q('INSERT INTO i18n_translations (lang, string_id, dst, updated_by, updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE dst=VALUES(dst), updated_by=VALUES(updated_by), updated_at=NOW()', [$lang, (int)$sid, mb_substr($dst, 0, 2000), $u['id']]);
                $n++;
            }
            foreach ((array)($_POST['shown'] ?? []) as $sid) q('UPDATE i18n_strings SET ignored=? WHERE id=?', [isset($_POST['ign'][$sid]) ? 1 : 0, (int)$sid]);
            flash('ok', "💾 U ruajtën $n përkthime.");
            redirect('admin/languages.php?' . http_build_query(['edit' => $lang, 'f' => get('f'), 'q' => get('q'), 'p' => get('p')]));
        } elseif ($a === 'import' && isset($langs[$lang]) && $lang !== I18N_SOURCE) {
            $f = $_FILES['csv'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Zgjidhni skedarin CSV.');
            $h = fopen($f['tmp_name'], 'r'); $n = 0; $first = true;
            while (($row = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
                if ($first) { $first = false; $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]); if (!ctype_digit((string)$row[0])) continue; }
                if (count($row) < 3 || trim((string)$row[2]) === '') continue;
                $sid = (int)$row[0];
                if (!$sid) $sid = (int)qv('SELECT id FROM i18n_strings WHERE src_hash=?', [i18n_hash(i18n_key((string)$row[1]))]);
                if (!$sid || !qv('SELECT 1 FROM i18n_strings WHERE id=?', [$sid])) continue;
                q('INSERT INTO i18n_translations (lang, string_id, dst, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE dst=VALUES(dst), updated_by=VALUES(updated_by), updated_at=NOW()', [$lang, $sid, mb_substr(trim((string)$row[2]), 0, 2000), $u['id']]);
                $n++;
            }
            fclose($h);
            flash('ok', "📥 U importuan $n përkthime.");
            redirect('admin/languages.php?edit=' . $lang);
        }
    } catch (Throwable $ex) { flash('err', $ex->getMessage()); }
    redirect('admin/languages.php' . ($lang ? '?edit=' . $lang : ''));
}

// Eksporti CSV për përkthyesit (Excel)
if ($lang && get('csv') && isset($langs[$lang])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="perkthimet_' . $lang . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'Shqip', $langs[$lang]['name']], ';', '"', '\\');
    foreach (qa('SELECT s.id, s.src, t.dst FROM i18n_strings s LEFT JOIN i18n_translations t ON t.string_id=s.id AND t.lang=? WHERE s.ignored=0 ORDER BY s.src', [$lang]) as $r) fputcsv($out, [$r['id'], $r['src'], (string)$r['dst']], ';', '"', '\\');
    fclose($out); exit;
}

$total = (int)qv('SELECT COUNT(*) FROM i18n_strings WHERE ignored=0');
$done = [];
foreach (qa("SELECT t.lang, COUNT(*) n FROM i18n_translations t JOIN i18n_strings s ON s.id=t.string_id AND s.ignored=0 WHERE t.dst<>'' GROUP BY t.lang") as $r) $done[$r['lang']] = (int)$r['n'];
$def = setting('default_lang') ?: I18N_SOURCE;

if ($lang && isset($langs[$lang]) && $lang !== I18N_SOURCE) {
    $L = $langs[$lang];
    $f = in_array(get('f'), ['todo', 'done', 'all', 'ignored'], true) ? get('f') : 'todo';
    $qq = trim((string)get('q'));
    $page = max(1, geti('p', 1)); $per = 40;
    $where = ['1=1']; $args = [$lang];
    if ($f === 'todo') $where[] = "s.ignored=0 AND (t.dst IS NULL OR t.dst='')";
    elseif ($f === 'done') $where[] = "s.ignored=0 AND t.dst<>''";
    elseif ($f === 'ignored') $where[] = 's.ignored=1';
    else $where[] = 's.ignored=0';
    if ($qq !== '') { $where[] = '(s.src LIKE ? OR t.dst LIKE ?)'; $args[] = "%$qq%"; $args[] = "%$qq%"; }
    $sqlW = implode(' AND ', $where);
    $count = (int)qv("SELECT COUNT(*) FROM i18n_strings s LEFT JOIN i18n_translations t ON t.string_id=s.id AND t.lang=? WHERE $sqlW", $args);
    $rows = qa("SELECT s.id, s.src, s.origin, s.ignored, t.dst FROM i18n_strings s LEFT JOIN i18n_translations t ON t.string_id=s.id AND t.lang=? WHERE $sqlW ORDER BY s.origin='page', CHAR_LENGTH(s.src), s.src LIMIT $per OFFSET " . (($page - 1) * $per), $args);
    $pages = max(1, (int)ceil($count / $per));
    $link = fn(array $o) => '?' . http_build_query(array_filter($o + ['edit' => $lang, 'f' => $f, 'q' => $qq, 'p' => $page], fn($v) => $v !== '' && $v !== null));
    $pct = $total ? (int)floor(100 * ($done[$lang] ?? 0) / $total) : 0;
    layout_start('🌐 ' . $L['flag'] . ' ' . $L['name'], ['nav' => 'admin/languages.php', 'actions' => '<a class="btn" href="languages.php">↩ Gjuhët</a><a class="btn" href="?edit=' . e($lang) . '&csv=1">⬇️ Eksporto CSV</a><button class="btn" data-open="dlgImp">⬆️ Importo CSV</button>']);
    ?>
    <div class="card" translate="no">
      <div class="row-between"><span>Përkthyer: <strong><?= $done[$lang] ?? 0 ?></strong> nga <?= $total ?> tekste</span><strong><?= $pct ?>%</strong></div>
      <?= progress_bar($pct) ?>
      <p class="small muted" style="margin:8px 0 0">Shkruani përkthimin pranë çdo teksti dhe klikoni <b>💾 Ruaj</b>. <code>{x}</code> zëvendëson numrat ose emrat — mbajeni në përkthim (p.sh. «Ke {x} vlerësime» → «You have {x} grades»). Emoji-t dhe pikësimi në fillim/fund ruhen vetë. Tekstet që nuk duhen përkthyer (emra, kode) shënojini «Injoro».</p>
    </div>
    <form method="get" class="row mb" translate="no"><input type="hidden" name="edit" value="<?= e($lang) ?>">
      <?php foreach (['todo' => '⏳ Të papërkthyera', 'done' => '✅ Të përkthyera', 'all' => '📋 Të gjitha', 'ignored' => '🚫 Të injoruara'] as $k => $l): ?>
        <a class="btn btn-sm <?= $f === $k ? 'btn-primary' : '' ?>" href="<?= e($link(['f' => $k, 'p' => 1])) ?>"><?= $l ?></a>
      <?php endforeach; ?>
      <input type="search" name="q" value="<?= e($qq) ?>" placeholder="🔎 Kërko tekst…" style="max-width:260px"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="btn btn-sm">Kërko</button>
    </form>
    <form method="post" translate="no"><?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="table-wrap"><table class="t">
      <thead><tr><th style="width:44%">🇦🇱 Shqip (teksti origjinal)</th><th><?= e($L['flag'] . ' ' . $L['name']) ?></th><th style="width:70px">Injoro</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= e($r['src']) ?><?= $r['origin'] === 'js' ? ' <span class="small muted">· mesazh</span>' : ($r['origin'] === 'page' ? ' <span class="small muted">· nga faqet</span>' : '') ?></td>
          <td><textarea name="tr[<?= (int)$r['id'] ?>]" rows="<?= mb_strlen($r['src']) > 70 ? 3 : 1 ?>" dir="<?= e($L['dir']) ?>" lang="<?= e($lang) ?>" style="width:100%;min-height:38px"><?= e((string)$r['dst']) ?></textarea></td>
          <td style="text-align:center"><input type="hidden" name="shown[]" value="<?= (int)$r['id'] ?>"><input type="checkbox" name="ign[<?= (int)$r['id'] ?>]" value="1" <?= $r['ignored'] ? 'checked' : '' ?> style="width:auto"></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="3" class="empty"><?= $f === 'todo' ? '🎉 Të gjitha tekstet janë përkthyer!' : 'Asnjë tekst.' ?></td></tr><?php endif; ?>
      </tbody></table></div>
    <?php if ($rows): ?><p class="row mt"><button class="btn btn-primary">💾 Ruaj këtë faqe</button>
      <?php if ($page > 1): ?><a class="btn" href="<?= e($link(['p' => $page - 1])) ?>">← Para</a><?php endif; ?>
      <span class="small muted">Faqja <?= $page ?> / <?= $pages ?> · <?= $count ?> tekste</span>
      <?php if ($page < $pages): ?><a class="btn" href="<?= e($link(['p' => $page + 1])) ?>">Pas →</a><?php endif; ?></p><?php endif; ?>
    </form>
    <form method="post" class="card mt" translate="no"><?= csrf_field() ?><input type="hidden" name="action" value="edit_lang"><input type="hidden" name="code" value="<?= e($lang) ?>">
      <h2>⚙️ Të dhënat e gjuhës</h2>
      <div class="row"><label>Emri<input name="name" value="<?= e($L['name']) ?>"></label><label>Flamuri/ikona<input name="flag" value="<?= e($L['flag']) ?>" style="width:90px"></label>
        <label>Drejtimi<select name="dir"><option value="ltr">Majtas → djathtas</option><option value="rtl" <?= $L['dir'] === 'rtl' ? 'selected' : '' ?>>Djathtas → majtas (arabisht…)</option></select></label><button class="btn">Ruaj</button></div>
    </form>
    <dialog id="dlgImp"><div class="dlg-body" translate="no"><div class="dlg-head"><h2>⬆️ Importo përkthimet</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
      <p class="small">1. Klikoni <b>⬇️ Eksporto CSV</b> dhe hapeni në Excel.<br>2. Përkthyesi plotëson kolonën e tretë (mos ndryshoni kolonën <b>id</b>).<br>3. Ruajeni si CSV (i ndarë me pikëpresje <b>;</b>, UTF-8) dhe ngarkojeni këtu.</p>
      <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="file" name="csv" accept=".csv" required><button class="btn btn-primary">Importo</button></form>
    </div></dialog>
    <?php layout_end();
    exit;
}

layout_start('🌐 Gjuhët e platformës');
?>
<div translate="no">
<p class="muted">Platforma mund të përdoret në shumë gjuhë, si librat ADT. Çdo përdorues zgjedh gjuhën e vet (te faqja e hyrjes ose në meny), dhe zgjedhja i ruhet. Shtoni një gjuhë të re dhe përkthejini tekstet këtu — ose eksportojini në Excel për një përkthyes.</p>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Gjuha</th><th>Kodi</th><th style="min-width:200px">Përkthyer</th><th>Statusi</th><th>E paracaktuar</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($langs as $c => $l): $p = $c === I18N_SOURCE ? 100 : ($total ? (int)floor(100 * ($done[$c] ?? 0) / $total) : 0); ?>
    <tr><td><strong><?= e($l['flag'] . ' ' . $l['name']) ?></strong></td><td><code><?= e($c) ?></code></td>
      <td><?php if ($c === I18N_SOURCE): ?><span class="small muted">gjuha burimore</span><?php else: ?><div class="pcell"><?= progress_bar($p) ?><span class="small"><?= $p ?>% · <?= $done[$c] ?? 0 ?>/<?= $total ?></span></div><?php endif; ?></td>
      <td><?php if ($c === I18N_SOURCE): ?>✅ aktive<?php else: ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="code" value="<?= e($c) ?>"><button class="btn btn-sm btn-ghost"><?= $l['active'] ? '✅ Aktive' : '⏸️ Joaktive' ?></button></form><?php endif; ?></td>
      <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="code" value="<?= e($c) ?>"><button class="btn btn-sm btn-ghost"><?= $def === $c ? '⭐ Po' : '☆' ?></button></form></td>
      <td class="actions"><?php if ($c !== I18N_SOURCE): ?><a class="btn btn-sm btn-primary" href="?edit=<?= e($c) ?>">✏️ Përkthe</a>
        <form method="post" class="inline" data-confirm="Ta fshij gjuhën dhe të gjitha përkthimet e saj?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="code" value="<?= e($c) ?>"><button class="btn btn-sm btn-danger">🗑️</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<div class="grid-2 mt">
  <form method="post" class="card form" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2>＋ Shto gjuhë</h2>
    <div class="row"><label>Kodi<input name="code" required placeholder="sr, tr, bs, rom…" style="width:120px"></label><label>Emri (në atë gjuhë)<input name="name" required placeholder="Srpski, Türkçe, Bosanski…"></label><label>Flamuri<input name="flag" placeholder="🇷🇸" style="width:80px"></label></div>
    <label class="checkline"><input type="checkbox" name="dir" value="rtl" style="width:auto"> Shkruhet nga e djathta në të majtë</label>
    <button class="btn btn-primary">＋ Shto gjuhën</button>
  </form>
  <div class="card" style="margin:0">
    <h2>🔄 Tekstet e platformës</h2>
    <p class="small"><?= $total ?> tekste për përkthim<?= setting('i18n_scanned') ? ' · skanuar më ' . e(setting('i18n_scanned')) : '' ?>.</p>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="scan"><button class="btn">🔄 Skano tekstet nga kodi</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="collect"><button class="btn <?= !empty($_SESSION['i18n_collect']) ? 'btn-primary' : '' ?>"><?= !empty($_SESSION['i18n_collect']) ? '🔍 Mbledhja: NDEZUR' : '🔍 Mblidh tekstet ndërsa shfletoj' ?></button></form>
    <p class="small muted" style="margin-top:8px">«Skano» gjen tekstet në kodin e platformës (bëjeni pas çdo përditësimi). «Mblidh» shton edhe tekstet që shfaqen vetëm gjatë punës — ndizeni, kaloni nëpër faqe, pastaj fikeni. Emrat e njerëzve, shkollave dhe librave nuk mblidhen.</p>
  </div>
</div>
</div>
<?php layout_end();
