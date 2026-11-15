<?php
declare(strict_types=1);

/**
 * Importon një paketë SCORM 1.2/2004 ose ADT (ZIP) në content/books/{folder}/
 * Kthen ID-në e librit ose hedh RuntimeException.
 */
function import_book_zip(string $zipPath, array $meta, ?array $uploader): int {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Zgjerimi PHP "zip" nuk është aktiv në server.');
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Skedari nuk është ZIP i vlefshëm.');

    // Gjej imsmanifest.xml (më i cekti) ose index.html
    $manifestDir = null; $indexDir = null; $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        $st = $zip->statIndex($i);
        $total += (int)$st['size'];
        if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\..')) {
            $zip->close();
            throw new RuntimeException('Paketa përmban shtigje të pasigurta.');
        }
        $base = basename($n);
        $dir = rtrim(substr($n, 0, strlen($n) - strlen($base)), '/');
        if (strtolower($base) === 'imsmanifest.xml' && ($manifestDir === null || strlen($dir) < strlen($manifestDir))) $manifestDir = $dir;
        if (strtolower($base) === 'index.html' && ($indexDir === null || strlen($dir) < strlen($indexDir))) $indexDir = $dir;
    }
    if ($total > 1024 * 1024 * 1024) { $zip->close(); throw new RuntimeException('Paketa është shumë e madhe (mbi 1 GB e shpaketuar).'); }
    $fmt = in_array($meta['format'] ?? 'auto', ['scorm', 'html'], true) ? $meta['format'] : 'auto';
    if ($fmt === 'scorm') {
        if ($manifestDir === null) { $zip->close(); throw new RuntimeException('Kjo nuk është paketë SCORM: mungon imsmanifest.xml. Zgjidhni formatin "Paketë HTML" ose "Automatik".'); }
        $rootDir = $manifestDir;
    } elseif ($fmt === 'html') {
        if ($indexDir === null) { $zip->close(); throw new RuntimeException('Paketa HTML duhet të ketë skedarin index.html (ziponi të gjithë dosjen e librit).'); }
        $rootDir = $indexDir;
    } else {
        $rootDir = $manifestDir ?? $indexDir;
    }
    if ($rootDir === null) { $zip->close(); throw new RuntimeException('Nuk u gjet imsmanifest.xml as index.html në paketë.'); }

    $folder = date('Ymd') . '_' . bin2hex(random_bytes(5));
    $dest = APP_ROOT . '/content/books/' . $folder;
    if (!mkdir($dest, 0775, true)) { $zip->close(); throw new RuntimeException('Nuk mund të krijohet dosja e librit (kontrolloni lejet e content/books).'); }

    $blocked = '/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|exe|bat|cmd|asp|aspx|jsp)$/i';
    $prefix = $rootDir === '' ? '' : $rootDir . '/';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if ($prefix !== '' && !str_starts_with($n, $prefix)) continue;
        $rel = substr($n, strlen($prefix));
        if ($rel === '' || str_ends_with($n, '/')) continue;
        if (preg_match($blocked, $rel) || basename($rel) === '.htaccess' || basename($rel) === '.user.ini') continue;
        $target = $dest . '/' . $rel;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
        $in = $zip->getStream($n);
        if (!$in) continue;
        $out = fopen($target, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out); fclose($in);
    }
    $zip->close();
    // Paketë HTML: injoro manifestin SCORM (nëse ka) dhe nis nga index.html
    if ($fmt === 'html' && is_file($dest . '/imsmanifest.xml')) rename($dest . '/imsmanifest.xml', $dest . '/imsmanifest.xml.orig');

    try {
        $info = analyse_package($dest);
    } catch (Throwable $ex) {
        rrmdir($dest);
        throw $ex;
    }

    $title = trim($meta['title'] ?? '') ?: $info['title'];
    q('INSERT INTO books (title, description, grade, package_type, folder, entry_href, cover, page_count, activity_count, uploaded_by, uploaded_by_name, school_id, is_global)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        mb_substr($title, 0, 200), $meta['description'] ?? null, $meta['grade'] ?: null, $info['type'], $folder, $info['entry'], $info['cover'],
        count($info['pages']), $info['activities'], $uploader['id'] ?? null, uploader_label($uploader),
        ($uploader && $uploader['role'] === 'teacher') ? $uploader['school_id'] : null,
        ($uploader && $uploader['role'] === 'admin') ? 1 : 0,
    ]);
    $bookId = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT IGNORE INTO book_pages (book_id, position, section_id, href, page_number, title, section_type, is_activity) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($info['pages'] as $i => $p) {
        $ins->execute([$bookId, $i + 1, $p['section_id'], $p['href'], $p['page_number'], $p['title'], $p['type'], $p['is_activity'] ? 1 : 0]);
    }
    return $bookId;
}

/** "Emri Mbiemri (Mësues · Shkolla)" — ruhet me librin */
function uploader_label(?array $u): ?string {
    if (!$u) return null;
    $role = $u['role'] === 'admin' ? 'Administrator' : 'Mësues';
    $school = $u['role'] === 'teacher' && !empty($u['school_id']) ? (string)qv('SELECT name FROM schools WHERE id=?', [$u['school_id']]) : '';
    return mb_substr($u['full_name'] . ' (' . $role . ($school ? ' · ' . $school : '') . ')', 0, 160);
}

/** Bllok i vogël HTML: kush e ngarkoi/ndryshoi librin dhe kur */
function book_provenance_html(array $b): string {
    $who = $b['uploaded_by_name'] ?? ($b['uploader'] ?? null) ?: 'i panjohur';
    $h = '<div class="small muted">📤 Ngarkuar nga <strong>' . e($who) . '</strong> · ' . e(fmt_dt($b['created_at'])) . '</div>';
    if (!empty($b['updated_at'])) $h .= '<div class="small muted">✏️ Ndryshuar nga ' . e($b['updated_by_name'] ?: '—') . ' · ' . e(fmt_dt($b['updated_at'])) . '</div>';
    return $h;
}

function analyse_package(string $dir): array {
    $type = 'html'; $title = 'Libër pa titull'; $entry = is_file($dir . '/index.html') ? 'index.html' : null;
    $mf = $dir . '/imsmanifest.xml';
    if (is_file($mf)) {
        $xml = @simplexml_load_file($mf, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml) {
            $raw = file_get_contents($mf);
            $type = (str_contains($raw, '2004') || str_contains($raw, 'adlcp_v1p3')) ? 'scorm2004' : 'scorm12';
            $xml->registerXPathNamespace('c', $xml->getNamespaces()[''] ?? 'http://www.imsproject.org/xsd/imscp_rootv1p1p2');
            $t = $xml->xpath('//c:organization/c:title');
            if ($t) $title = trim((string)$t[0]);
            $res = $xml->xpath('//c:resource[@href]');
            if ($res) {
                foreach ($res as $r) {
                    $attrs = $r->attributes('adlcp', true);
                    $st = strtolower((string)($attrs['scormtype'] ?? $attrs['scormType'] ?? ''));
                    if ($st === 'sco' || !$entry) { $entry = (string)$r['href']; if ($st === 'sco') break; }
                }
            }
        }
    }
    if (!$entry || !is_file($dir . '/' . strtok($entry, '?#'))) {
        throw new RuntimeException('Pika hyrëse e paketës nuk u gjet.');
    }

    $pages = []; $activities = 0;
    $pagesJson = $dir . '/content/pages.json';
    if (is_file($pagesJson)) {
        // Paketë ADT (Accessible Digital Textbook)
        $type = 'adt';
        $cfgJson = $dir . '/assets/config.json';
        if (is_file($cfgJson)) {
            $c = json_decode((string)file_get_contents($cfgJson), true);
            if (!empty($c['title'])) $title = $c['title'];
        }
        $toc = [];
        if (is_file($dir . '/content/toc.json')) {
            foreach (json_decode((string)file_get_contents($dir . '/content/toc.json'), true) ?: [] as $t) {
                $toc[$t['section_id']] = $t['title'] ?? null;
            }
        }
        $lastTitle = null;
        foreach (json_decode((string)file_get_contents($pagesJson), true) ?: [] as $p) {
            $sid = (string)($p['section_id'] ?? '');
            $href = (string)($p['href'] ?? '');
            if ($sid === '' || $href === '') continue;
            $secType = null;
            $html = is_file($dir . '/' . $href) ? (string)file_get_contents($dir . '/' . $href, false, null, 0, 20000) : '';
            if (preg_match('/data-section-type="([^"]+)"/', $html, $m)) $secType = $m[1];
            $isAct = $secType && str_starts_with($secType, 'activity_') || str_starts_with($sid, 'qz');
            if ($isAct) $activities++;
            $h = null;
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m)) $h = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $lastTitle = $toc[$sid] ?? $lastTitle;
            $pages[] = [
                'section_id' => $sid, 'href' => $href,
                'page_number' => isset($p['page_number']) ? (int)$p['page_number'] : null,
                'title' => mb_substr($h ?: ($lastTitle ?: $sid), 0, 250),
                'type' => $secType, 'is_activity' => $isAct,
            ];
        }
        if ($pages) $entry = $pages[0]['href'];
    } else {
        $pages[] = ['section_id' => 'sco', 'href' => $entry, 'page_number' => null, 'title' => $title, 'type' => 'scorm', 'is_activity' => false];
    }

    $cover = null;
    foreach (['cover.png', 'cover.jpg', 'cover.jpeg', 'thumbnail.png', 'images/cover.png'] as $c) {
        if (is_file($dir . '/' . $c)) { $cover = $c; break; }
    }
    return ['type' => $type, 'title' => $title, 'entry' => $entry, 'pages' => $pages, 'activities' => $activities, 'cover' => $cover];
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}

function delete_book(int $bookId): void {
    $b = q1('SELECT folder FROM books WHERE id = ?', [$bookId]);
    if (!$b) return;
    q('DELETE FROM books WHERE id = ?', [$bookId]);
    if (preg_match('/^[A-Za-z0-9_]+$/', $b['folder'])) rrmdir(APP_ROOT . '/content/books/' . $b['folder']);
}

function book_url(array $b, ?string $href = null): string {
    return url('content/books/' . $b['folder'] . '/' . ($href ?? $b['entry_href']));
}
function book_cover_url(array $b): ?string {
    return $b['cover'] ? url('content/books/' . $b['folder'] . '/' . $b['cover']) : null;
}

/**
 * Librat e administratorit (globalë) me klasë të caktuar u shfaqen automatikisht
 * të gjitha klasave të atij niveli (p.sh. libri i klasës 5 → të gjitha klasat e 5-ta).
 */
function auto_assign_book_by_grade(int $bookId): int {
    if (setting('auto_assign_grade') !== '1') return 0;
    return q('INSERT IGNORE INTO class_books (class_id, book_id)
              SELECT c.id, b.id FROM classes c JOIN books b ON b.id = ? WHERE b.is_global = 1 AND b.grade IS NOT NULL AND c.grade = b.grade', [$bookId])->rowCount();
}
function auto_assign_class_books(int $classId): int {
    if (setting('auto_assign_grade') !== '1') return 0;
    return q('INSERT IGNORE INTO class_books (class_id, book_id)
              SELECT c.id, b.id FROM classes c JOIN books b ON b.is_global = 1 AND b.grade = c.grade WHERE c.id = ?', [$classId])->rowCount();
}
function auto_assign_all_by_grade(): int {
    if (setting('auto_assign_grade') !== '1') return 0;
    return q('INSERT IGNORE INTO class_books (class_id, book_id)
              SELECT c.id, b.id FROM classes c JOIN books b ON b.is_global = 1 AND b.grade IS NOT NULL AND b.grade = c.grade')->rowCount();
}

/** Skedari i përkohshëm i ngarkimit në copa (në backups/uploads, i bllokuar nga uebi) */
function upload_chunk_path(string $id, int $userId): string {
    $dir = APP_ROOT . '/backups/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    // pastro ngarkimet e papërfunduara më të vjetra se 2 ditë
    foreach (glob($dir . '/*.part') ?: [] as $old) if (filemtime($old) < time() - 172800) @unlink($old);
    return $dir . '/' . $userId . '_' . $id . '.part';
}

function handle_book_upload(?array $uploader): ?int {
    $uid = (string)post('upload_id');
    if (preg_match('/^[a-f0-9]{32}$/', $uid) && $uploader) {
        // Libri u ngarkua në copa (api/upload_chunk.php) — importo skedarin e bashkuar
        $path = upload_chunk_path($uid, (int)$uploader['id']);
        if (!is_file($path)) { flash('err', 'Ngarkimi nuk u gjet ose ka skaduar. Provoni përsëri.'); return null; }
        if (!preg_match('/\.zip$/i', (string)post('upload_name'))) { @unlink($path); flash('err', 'Lejohen vetëm skedarë .zip'); return null; }
        @set_time_limit(900);
        try {
            $id = import_book_zip($path, ['title' => post('title'), 'description' => post('description'), 'grade' => posti('grade') ?: null, 'format' => (string)post('format')], $uploader);
            $b = q1('SELECT title, page_count, activity_count FROM books WHERE id=?', [$id]);
            flash('ok', 'Libri "' . $b['title'] . '" u ngarkua: ' . $b['page_count'] . ' faqe, ' . $b['activity_count'] . ' ushtrime.');
            return $id;
        } catch (Throwable $ex) {
            flash('err', 'Ngarkimi dështoi: ' . $ex->getMessage());
            return null;
        } finally {
            @unlink($path);
        }
    }
    $f = $_FILES['package'] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) { flash('err', 'Zgjidhni skedarin ZIP të librit (SCORM ose HTML).'); return null; }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        flash('err', 'Skedari është më i madh se kufiri i serverit (' . ini_get('upload_max_filesize') . '). Rritni upload_max_filesize dhe post_max_size në php.ini.');
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) { flash('err', 'Ngarkimi dështoi (kodi ' . (int)$f['error'] . ').'); return null; }
    if (!preg_match('/\.zip$/i', $f['name'])) { flash('err', 'Lejohen vetëm skedarë .zip'); return null; }
    try {
        $id = import_book_zip($f['tmp_name'], ['title' => post('title'), 'description' => post('description'), 'grade' => posti('grade') ?: null, 'format' => (string)post('format')], $uploader);
        $b = q1('SELECT title, page_count, activity_count FROM books WHERE id=?', [$id]);
        flash('ok', 'Libri "' . $b['title'] . '" u ngarkua: ' . $b['page_count'] . ' faqe, ' . $b['activity_count'] . ' ushtrime.');
        return $id;
    } catch (Throwable $ex) {
        flash('err', 'Ngarkimi dështoi: ' . $ex->getMessage());
        return null;
    }
}
