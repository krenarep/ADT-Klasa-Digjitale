<?php
declare(strict_types=1);

/**
 * Shumëgjuhësia e ndërfaqes (si te librat ADT).
 * - Gjuha burimore është shqipja: tekstet e ndërfaqes nxirren automatikisht nga kodi (i18n_scan_sources).
 * - Administratori shton çdo gjuhë dhe i përkthen tekstet nga faqja 🌐 Gjuhët (admin/languages.php).
 * - Secili përdorues zgjedh gjuhën e vet; faqja përkthehet në dalje (i18n_filter) pa ndryshuar logjikën e aplikacionit.
 * - Numrat në tekst zëvendësohen me {x}: "Ke {x} vlerësime" → "You have {x} grades".
 */

const I18N_SOURCE = 'sq';

function i18n_ready(): bool {
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)qv("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='i18n_languages'"); } catch (Throwable) { $ok = false; } }
    return $ok;
}

/** @return array<string,array{code:string,name:string,flag:string,dir:string,active:int}> */
function i18n_languages(bool $activeOnly = true): array {
    static $cache = [];
    $k = $activeOnly ? 'a' : 'all';
    if (isset($cache[$k])) return $cache[$k];
    $out = [I18N_SOURCE => ['code' => I18N_SOURCE, 'name' => 'Shqip', 'flag' => '🇦🇱', 'dir' => 'ltr', 'active' => 1]];
    if (i18n_ready()) {
        try { foreach (qa('SELECT code, name, flag, dir, active FROM i18n_languages' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY code=? DESC, name', [I18N_SOURCE]) as $r) $out[$r['code']] = $r; } catch (Throwable) {}
    }
    return $cache[$k] = $out;
}

function i18n_valid_code(string $c): bool { return (bool)preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,8})?$/', $c); }

/** Gjuha e faqes aktuale: zgjedhja e përdoruesit → cookie → gjuha e paracaktuar e platformës → shqip */
function current_lang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    $langs = i18n_languages();
    foreach ([$_SESSION['lang'] ?? null, $_COOKIE['kd_lang'] ?? null, setting('default_lang') ?: null] as $c) {
        if (is_string($c) && isset($langs[$c])) return $lang = $c;
    }
    return $lang = I18N_SOURCE;
}

/** ?lang=xx në çdo faqe ndërron gjuhën (ruhet në sesion, cookie dhe profilin e përdoruesit) */
function i18n_handle_switch(): void {
    if (PHP_SAPI === 'cli') return;
    $c = $_GET['lang'] ?? null;
    if (!is_string($c) || !isset(i18n_languages()[$c])) {
        // gjuha e ruajtur në profil, pas hyrjes
        if (!empty($_SESSION['uid']) && !isset($_SESSION['lang']) && i18n_ready()) {
            try { $ul = (string)qv('SELECT lang FROM users WHERE id=?', [$_SESSION['uid']]); if ($ul !== '' && isset(i18n_languages()[$ul])) $_SESSION['lang'] = $ul; else $_SESSION['lang'] = ''; } catch (Throwable) {}
        }
        return;
    }
    $_SESSION['lang'] = $c;
    setcookie('kd_lang', $c, ['expires' => time() + 365 * 86400, 'path' => ($GLOBALS['CFG']['base_url'] ?: '') . '/', 'samesite' => 'Lax', 'httponly' => true]);
    $_COOKIE['kd_lang'] = $c;
    if (!empty($_SESSION['uid'])) { try { q('UPDATE users SET lang=? WHERE id=?', [$c, $_SESSION['uid']]); } catch (Throwable) {} }
    // hiq ?lang= nga adresa
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $clean = preg_replace('/([?&])lang=[^&#]*(&|$)/', '$1', $uri);
    $clean = rtrim((string)$clean, '?&');
    if ($clean !== $uri && $_SERVER['REQUEST_METHOD'] === 'GET') { header('Location: ' . $clean); exit; }
}

/* ---------------- Normalizimi i teksteve ---------------- */

/** Ndan tekstin në: [hapësira para, simbole para, bërthama, simbole pas, hapësira pas] */
function i18n_split(string $s): array {
    if (!preg_match('/^(\s*)([^\p{L}\p{N}«"“({]*)(.*?)([^\p{L}\p{N}»"”).!?…:}]*)(\s*)$/su', $s, $m)) return ['', '', $s, '', ''];
    return [$m[1], $m[2], $m[3], $m[4], $m[5]];
}
function i18n_key(string $core): string {
    $core = html_entity_decode($core, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/u', ' ', $core));
}
/** Zëvendëson numrat me {x}; kthen [çelësi, vlerat] */
function i18n_numkey(string $key): array {
    $vals = [];
    $k = preg_replace_callback('/\d+(?:[.,:]\d+)*/', function ($m) use (&$vals) { $vals[] = $m[0]; return '{x}'; }, $key);
    return [(string)$k, $vals];
}
function i18n_hash(string $key): string { return sha1($key); }

/** A duket si tekst ndërfaqeje (jo kod, SQL, rrugë)? */
function i18n_is_text(string $core): bool {
    if ($core === '' || mb_strlen($core) > 600 || !preg_match('/\p{L}{2,}/u', $core)) return false;
    if (preg_match('/\b(SELECT|INSERT|UPDATE \w+ SET|DELETE FROM|WHERE|JOIN|EXISTS \(|ALTER TABLE|CREATE TABLE|COALESCE|GROUP BY|ORDER BY)\b/', $core)) return false;
    if (preg_match('/^[a-z0-9_\-\.\/:#\[\]=?&%+]+$/', $core) && !(!empty($GLOBALS['__i18n_inline']) && preg_match('/^[a-z]{4,}$/', $core))) return false;
    if (preg_match('/[$;]|=>|::|->|\w\(\)|<\?|\bfunction\b|\\\\[nrt]/', $core)) return false;
    if (preg_match('/^(https?:|www\.|data:|mailto:)/i', $core)) return false;
    if (preg_match('/[=<>]|\(\'|\'\)|\b[a-z]{1,3}\.[a-z_]{2,}\b|\b(FROM|AS|AND|OR|NULL|IN|ON|DESC|ASC|LIMIT)\b(?=\s|$)/', $core)) return false;   // SQL / HTML / kod
    if (preg_match('/^[A-Z][A-Za-z-]+: [a-z]/', $core)) return false;                    // koka HTTP
    if (substr_count($core, '"') % 2 === 1 || preg_match('/^["“”]\s|\s["“”]$/u', $core)) return false;   // copa vargjesh me thonjëza të pambyllura
    if (preg_match('/[A-Za-z_]\(|(^|\s)\.[a-z]|\/[a-z_]+\/|\.(php|js|css|html)\b|\bstore\.|\bwindow\.|\bdocument\./', $core)) return false;   // thirrje funksionesh, CSS, rrugë
    if (preg_match('/^[A-Z]{3}-[A-Z0-9]{1,4}-\d{2,3}-\d{2,3}$/', $core)) return false;   // kod nxënësi
    $w = rtrim($core, ':;,.!?');
    if (!preg_match('/\s/u', $w) && !preg_match('/^\p{Lu}\p{Ll}+$/u', $w) && !preg_match('/[ëçËÇ]/u', $w)
        && !(!empty($GLOBALS['__i18n_inline']) && preg_match('/^\p{Ll}{4,}$/u', $w))) return false;   // fjalë e vetme me shkronjë të vogël: vetëm nga teksti i dukshëm i faqes
    return true;
}

/* ---------------- Nxjerrja e teksteve nga kodi ---------------- */

function i18n_collect_html(array &$out, string $html, string $origin): void {
    $html = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html);
    foreach (preg_split('~<[^>]*>~', (string)$html) as $seg) i18n_collect_seg($out, $seg, $origin);
    $inl = $GLOBALS['__i18n_inline'] ?? false; $GLOBALS['__i18n_inline'] = false;   // atributet: pa fjalë të vetme me shkronja të vogla
    if (preg_match_all('~\b(?:placeholder|title|aria-label|alt|data-confirm|data-label)="([^"<>]*)"~u', (string)$html, $m)) foreach ($m[1] as $a) i18n_collect_seg($out, $a, $origin);
    if (preg_match_all('~<input\b[^>]*\btype="(?:submit|button|reset)"[^>]*\bvalue="([^"<>]*)"~u', (string)$html, $m)) foreach ($m[1] as $a) i18n_collect_seg($out, $a, $origin);
    $GLOBALS['__i18n_inline'] = $inl;
}
function i18n_collect_seg(array &$out, string $seg, string $origin): void {
    $seg = (string)preg_replace('/(\{x\}\s*)+\{x\}/', '{x}', $seg);
    $cands = [$seg];
    if (preg_match('/\s[·|•]\s/u', $seg)) foreach (preg_split('/\s[·|•]\s/u', $seg) as $p) $cands[] = $p;   // pjesët e teksteve me "·"
    foreach ($cands as $part) {
        [, , $core] = i18n_split((string)$part);
        [$key] = i18n_numkey(i18n_key($core));
        $key = trim((string)preg_replace('/^(\{x\}\s*)+|(\s*\{x\})+$/', '', $key));
        [, , $core2] = i18n_split($key);
        $key = i18n_key($core2);
        if (str_contains($key, '{x}') && !preg_match('/\p{L}{2,}/u', str_replace('{x}', '', $key))) continue;
        if (i18n_is_text($key)) $out[$key] = $origin;
    }
}

/** Skanon kodin PHP dhe JS dhe regjistron tekstet e reja. Kthen [gjithsej, të reja]. */
function i18n_scan_sources(): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = str_replace('\\', '/', (string)$f);
        if (preg_match('~/(content|backups|uploads|install|tools|\.git)/~', $path)) continue;
        if (str_ends_with($path, '.php')) {
            if (str_ends_with($path, '/config.php') || str_ends_with($path, '/config.sample.php') || str_ends_with($path, '/inc/i18n.php')) continue;
            i18n_scan_php((string)file_get_contents($path), $out);
        } elseif (preg_match('~/assets/js/[^/]+\.js$~', $path)) {
            if (preg_match_all("/'((?:[^'\\\\\\n]|\\\\.){3,300})'/u", (string)file_get_contents($path), $m)) foreach ($m[1] as $s) i18n_collect_html($out, stripcslashes($s), 'js');
        }
    }
    $new = 0;
    $st = db()->prepare('INSERT IGNORE INTO i18n_strings (src_hash, src, origin) VALUES (?,?,?)');
    foreach ($out as $src => $origin) { $st->execute([i18n_hash($src), $src, $origin]); $new += $st->rowCount(); }
    set_setting('i18n_scanned', date('Y-m-d H:i'));
    return [count($out), $new];
}

function i18n_scan_php(string $code, array &$out): void {
    $tokens = token_get_all($code);
    $buf = ''; $inEcho = false; $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            [$id, $text] = $t;
            if ($id === T_INLINE_HTML) { $buf .= $text; continue; }   // teksti i dukshëm i faqes
            if ($id === T_OPEN_TAG_WITH_ECHO) { $buf .= '{x}'; $inEcho = true; continue; }
            if ($id === T_CLOSE_TAG) { $inEcho = false; continue; }
            if ($id === T_OPEN_TAG) { if ($buf !== '' && !str_ends_with($buf, '{x}')) $buf .= '{x}'; continue; }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $s = substr($text, 1, -1);
                $s = $text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $s) : stripcslashes($s);
                i18n_collect_html($out, $s, 'php');
            }
        } elseif ($t === '"') {
            $s = ''; $i++;
            while ($i < $n && $tokens[$i] !== '"') {
                $tk = $tokens[$i];
                if (is_array($tk) && $tk[0] === T_ENCAPSED_AND_WHITESPACE) $s .= $tk[1];
                elseif (is_array($tk) && $tk[0] === T_VARIABLE) $s .= '{x}';
                $i++;
            }
            i18n_collect_html($out, stripcslashes((string)preg_replace('/(\{x\})+/', '{x}', $s)), 'php');
        }
    }
    if ($buf !== '') { $GLOBALS['__i18n_inline'] = true; i18n_collect_html($out, $buf, 'php'); $GLOBALS['__i18n_inline'] = false; }
}

/* ---------------- Fjalori dhe përkthimi ---------------- */

/** @return array{exact:array<string,string>,patterns:array<int,array{0:string,1:string,2:string}>} */
function i18n_dict(string $lang): array {
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];
    $exact = []; $patterns = [];
    if ($lang !== I18N_SOURCE && i18n_ready()) {
        try {
            foreach (qa("SELECT s.src, t.dst FROM i18n_translations t JOIN i18n_strings s ON s.id=t.string_id WHERE t.lang=? AND t.dst<>'' AND s.ignored=0", [$lang]) as $r) {
                $exact[$r['src']] = $r['dst'];
                if (str_contains($r['src'], '{x}')) {
                    $parts = explode('{x}', $r['src']);
                    $body = implode('(.+?)', array_map(fn($p) => preg_quote($p, '/'), $parts));
                    $lit = ''; foreach ($parts as $p) if (mb_strlen(trim($p)) > mb_strlen($lit)) $lit = trim($p);
                    $patterns[] = ['/^' . $body . '$/su', $r['dst'], $lit, false];
                    if (mb_strlen($lit) >= 12) $patterns[] = ['/^(.+?)\s' . $body . '$/su', $r['dst'], $lit, true];   // p.sh. "Emri (klasa) nuk ka lexuar…"
                } elseif (mb_strlen($r['src']) >= 14 && preg_match('/^\p{Ll}/u', $r['src'])) {
                    // fjali që vijnë pas një emri: "Emri Mbiemri ka saktësi mesatare"
                    $patterns[] = ['/^(.+?)\s' . preg_quote($r['src'], '/') . '$/su', '{x} ' . $r['dst'], $r['src'], true];
                }
            }
        } catch (Throwable) {}
    }
    return $cache[$lang] = ['exact' => $exact, 'patterns' => $patterns];
}

function i18n_fill(string $dst, array $vals): string {
    $i = 0;
    return (string)preg_replace_callback('/\{x\}/', function () use (&$i, $vals) { return $vals[$i++] ?? ''; }, $dst);
}

/** Përkthen një copë teksti (ruan hapësirat, emoji-t dhe pikësimin përreth). Kthen null nëse s'ka përkthim. */
function i18n_translate_core(string $key, array $dict): ?string {
    if ($key === '') return null;
    if (isset($dict['exact'][$key])) return $dict['exact'][$key];
    [$nk, $vals] = i18n_numkey($key);
    if ($vals && isset($dict['exact'][$nk])) return i18n_fill($dict['exact'][$nk], $vals);
    foreach ($dict['patterns'] as [$re, $dst, $lit, $lead]) {
        if ($lit !== '' && !str_contains($key, $lit)) continue;
        if (preg_match($re, $key, $m)) {
            array_shift($m);
            if ($lead) { $first = array_shift($m); return (str_starts_with($dst, '{x} ') ? $first . ' ' . i18n_fill(substr($dst, 4), $m) : $first . ' ' . i18n_fill($dst, $m)); }
            return i18n_fill($dst, $m);
        }
    }
    // pjesë në kllapa në fund: "Zgjidh të gjitha (41"
    if (preg_match('/^(.+?)\s+\(([^()]*)\)?$/u', $key, $mm) && isset($dict['exact'][$mm[1]])) return $dict['exact'][$mm[1]] . ' (' . $mm[2] . (str_ends_with($key, ')') ? ')' : '');
    // numri në fillim ose në fund: "2 libra të caktuar", "Faqja 8"
    if ($vals) {
        if (preg_match('/^\{x\}\s+(.+)$/u', $nk, $mm) && isset($dict['exact'][$mm[1]]) && !str_contains($mm[1], '{x}')) return $vals[0] . ' ' . $dict['exact'][$mm[1]];
        if (preg_match('/^(.+?)\s+\{x\}$/u', $nk, $mm) && isset($dict['exact'][$mm[1]]) && !str_contains($mm[1], '{x}')) return $dict['exact'][$mm[1]] . ' ' . end($vals);
    }
    return null;
}

function i18n_translate_segment(string $seg, array $dict, ?array &$miss = null): string {
    if (trim($seg) === '' || !preg_match('/\p{L}/u', $seg)) return $seg;
    [$sp1, $pre, $core, $post, $sp2] = i18n_split($seg);
    $key = i18n_key($core);
    $tr = i18n_translate_core($key, $dict);
    if ($tr === null) {
        // tekst i përbërë me "·" (p.sh. "f. 8 · Plotëso tabelën"): përkthe secilën pjesë
        if (preg_match('/\s[·|•—–→]\s|,\s|:\s/u', $core)) {
            $parts = preg_split('/(\s[·|•—–→]\s|,\s|:\s)/u', $core, -1, PREG_SPLIT_DELIM_CAPTURE);
            $any = false;
            foreach ($parts as $i => $p) if ($i % 2 === 0) { [, $pp, $pc, $ps] = i18n_split($p); $t = i18n_translate_core(i18n_key($pc), $dict); if ($t !== null) $t = $pp . $t . $ps; if ($t !== null) { $parts[$i] = htmlspecialchars($t, ENT_NOQUOTES, 'UTF-8', false); $any = true; } elseif ($miss !== null) $miss[] = i18n_key($p); }
            if ($any) return $sp1 . $pre . implode('', $parts) . $post . $sp2;
        } elseif ($miss !== null) $miss[] = $key;
        return $seg;
    }
    return $sp1 . $pre . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8', false) . $post . $sp2;
}

/** Filtri i daljes: përkthen tekstet dhe atributet e faqes HTML sipas gjuhës së përdoruesit */
function i18n_filter(string $html): string {
    $lang = current_lang();
    $collect = !empty($_SESSION['i18n_collect']) && ($_SESSION['role'] ?? '') === 'admin';
    if ($lang === I18N_SOURCE && !$collect) return $html;
    foreach (headers_list() as $h) if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
    if (!preg_match('/<(html|body|div|main|section|p|span|a|button)\b/i', substr($html, 0, 5000))) return $html;
    $dict = i18n_dict($lang);
    $miss = $collect ? [] : null;
    $skip = 0;   // brenda <script>, <style>, <textarea>, <code>, [contenteditable], translate="no"
    $stack = [];
    $parts = preg_split('~(<!--.*?-->|<[^>]*>)~s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $p) {
        if ($p === '') continue;
        if ($p[0] === '<') {
            if (str_starts_with($p, '<!--')) continue;
            if (preg_match('~^</\s*([a-zA-Z0-9]+)~', $p, $m)) {
                $tag = strtolower($m[1]);
                if ($skip && $stack && end($stack) === $tag) { array_pop($stack); if (!$stack) $skip = 0; }
                continue;
            }
            if (preg_match('~^<\s*([a-zA-Z0-9]+)~', $p, $m)) {
                $tag = strtolower($m[1]);
                if ($tag === 'html' && $lang !== I18N_SOURCE) {
                    $parts[$i] = preg_replace('/\blang="[^"]*"/', 'lang="' . $lang . '"', $p);
                    if ((i18n_languages()[$lang]['dir'] ?? 'ltr') === 'rtl') $parts[$i] = str_replace('<html', '<html dir="rtl"', $parts[$i]);
                    continue;
                }
                $selfClose = str_ends_with(rtrim($p), '/>') || in_array($tag, ['input', 'img', 'br', 'hr', 'meta', 'link', 'source', 'col', 'area', 'base', 'wbr'], true);
                if (!$skip && (in_array($tag, ['script', 'style', 'textarea', 'code', 'pre'], true) || preg_match('/\b(contenteditable|translate="no")/i', $p))) {
                    if (!$selfClose) { $skip = 1; $stack = [$tag]; }
                    continue;
                }
                if ($skip) { if (!$selfClose && end($stack) === $tag) $stack[] = $tag; continue; }
                // atributet
                $parts[$i] = preg_replace_callback('~\b(placeholder|title|aria-label|alt|data-confirm|data-label)="([^"]*)"~u', function ($m) use ($dict, &$miss) {
                    return $m[1] . '="' . htmlspecialchars(html_entity_decode(i18n_translate_segment(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $dict, $miss), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '"';
                }, $p);
                if (preg_match('~^<input\b[^>]*\btype="(submit|button|reset)"~i', $p)) {
                    $parts[$i] = preg_replace_callback('~\bvalue="([^"]*)"~u', fn($m) => 'value="' . htmlspecialchars(i18n_translate_segment(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $dict, $miss), ENT_QUOTES, 'UTF-8') . '"', (string)$parts[$i]);
                }
            }
            continue;
        }
        if ($skip) continue;
        $parts[$i] = i18n_translate_segment($p, $dict, $miss);
    }
    if ($collect && $miss) i18n_record_missing($miss);
    return implode('', $parts);
}

/** Mënyra «mblidh tekstet»: regjistron tekstet e faqes që nuk janë ende në listë (pa emra njerëzish, shkollash, librash) */
function i18n_record_missing(array $miss): void {
    try {
        $known = [];
        foreach ([["SELECT full_name FROM users"], ["SELECT username FROM users"], ["SELECT name FROM schools"], ["SELECT name FROM classes"], ["SELECT title FROM books"], ["SELECT title FROM book_pages WHERE title IS NOT NULL"], ["SELECT title FROM trainings"]] as [$sql]) {
            foreach (qa($sql) as $r) $known[i18n_key((string)reset($r))] = 1;
        }
        $st = db()->prepare('INSERT IGNORE INTO i18n_strings (src_hash, src, origin) VALUES (?,?,?)');
        foreach (array_unique($miss) as $k) {
            [$nk] = i18n_numkey($k);
            if (isset($known[$k]) || !i18n_is_text($nk) || mb_strlen($nk) < 3) continue;
            $st->execute([i18n_hash($nk), $nk, 'page']);
        }
    } catch (Throwable) {}
}

/** Përkthim i drejtpërdrejtë (për tekste që ndërtohen në JavaScript) */
function t(string $s): string {
    $lang = current_lang();
    if ($lang === I18N_SOURCE) return $s;
    return html_entity_decode(i18n_translate_segment($s, i18n_dict($lang)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Fjalori për JavaScript (mesazhet e lexuesit të librave, njoftimet) */
function i18n_js(): string {
    $lang = current_lang();
    if ($lang === I18N_SOURCE || !i18n_ready()) return '';
    $map = [];
    try { foreach (qa("SELECT s.src, t.dst FROM i18n_translations t JOIN i18n_strings s ON s.id=t.string_id WHERE t.lang=? AND t.dst<>'' AND (s.origin='js' OR s.src IN ('faqe'))", [$lang]) as $r) $map[$r['src']] = $r['dst']; } catch (Throwable) {}
    if (!$map) return '';
    $js = <<<'JS'
window.__t = function (s) {
  s = String(s); var m = window.I18N || {};
  var g = s.match(/^(\s*[^\p{L}\p{N}]*)([\s\S]*?)([^\p{L}\p{N}.!?…)]*\s*)$/u) || ['', '', s, ''];
  var c = g[2].replace(/\s+/g, ' ');
  if (m[c]) return g[1] + m[c] + g[3];
  var v = [], n = c.replace(/\d+(?:[.,:]\d+)*/g, function (x) { v.push(x); return '{x}'; });
  if (m[n]) { var i = 0; return g[1] + m[n].replace(/\{x\}/g, function () { return v[i++] || ''; }) + g[3]; }
  return s;
};
JS;
    return '<script>window.I18N=' . json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';' . $js . '</script>';
}

/** Butonat/zgjedhja e gjuhës */
function i18n_switcher_html(string $style = 'select'): string {
    $langs = i18n_languages();
    if (count($langs) < 2) return '';
    $cur = current_lang();
    if ($style === 'buttons') {
        $h = '<div class="lang-switch" translate="no" style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin:10px 0">';
        foreach ($langs as $l) $h .= '<a class="btn btn-sm' . ($l['code'] === $cur ? ' btn-primary' : '') . '" href="?lang=' . e($l['code']) . '" hreflang="' . e($l['code']) . '" lang="' . e($l['code']) . '">' . e($l['flag'] . ' ' . $l['name']) . '</a>';
        return $h . '</div>';
    }
    $h = '<select translate="no" aria-label="Language / Gjuha" onchange="location.search=(location.search?location.search+\'&\':\'?\')+\'lang=\'+this.value" style="width:auto;padding:4px 8px;font-size:.9rem">';
    foreach ($langs as $l) $h .= '<option value="' . e($l['code']) . '"' . ($l['code'] === $cur ? ' selected' : '') . ' lang="' . e($l['code']) . '">' . e($l['flag'] . ' ' . $l['name']) . '</option>';
    return $h . '</select>';
}

/**
 * Paketat e gatshme të përkthimeve (inc/lang/xx.json): importohen automatikisht pas çdo përditësimi.
 * Nuk mbishkruajnë përkthimet që administratori i ka ndryshuar vetë.
 */
function i18n_auto_setup(): void {
    if (PHP_SAPI === 'cli' || !i18n_ready()) return;
    $files = glob(APP_ROOT . '/inc/lang/*.json') ?: [];
    $sig = md5(implode('|', array_map(fn($f) => basename($f) . filemtime($f), $files)) . '|' . filemtime(__FILE__));
    if (setting('i18n_setup_sig') === $sig) return;
    set_setting('i18n_setup_sig', $sig);   // një herë
    try {
        @set_time_limit(120);
        i18n_scan_sources();
        foreach ($files as $f) i18n_import_pack(basename($f, '.json'), $f);
    } catch (Throwable) {}
}

function i18n_import_pack(string $lang, string $file): int {
    if (!i18n_valid_code($lang) || $lang === I18N_SOURCE) return 0;
    $pack = json_decode((string)file_get_contents($file), true);
    if (!is_array($pack)) return 0;
    if (!qv('SELECT 1 FROM i18n_languages WHERE code=?', [$lang])) return 0;   // vetëm për gjuhët e shtuara
    $ids = [];
    foreach (qa('SELECT id, src_hash FROM i18n_strings') as $r) $ids[$r['src_hash']] = (int)$r['id'];
    $st = db()->prepare('INSERT IGNORE INTO i18n_translations (lang, string_id, dst) VALUES (?,?,?)');
    $n = 0;
    foreach ($pack as $src => $dst) {
        $id = $ids[i18n_hash((string)$src)] ?? null;
        if (!$id || trim((string)$dst) === '') continue;
        $st->execute([$lang, $id, (string)$dst]);
        $n += $st->rowCount();
    }
    if ($lang === 'en') {
        // disa faqe (raporti për palët, trajnimet) janë shkruar në anglisht: këto tekste mbeten siç janë
        $en = '/\b(the|and|of|to|for|with|teacher|teachers|students|training|session|report|average|by|per|score|book|class|school|not|this|your|you|all|total|data|hours|attendance|status|name|date|notes|add|remove|edit|delete|save|new|back|week|minutes|step|count|summary|value|group)\b/i';
        foreach (qa("SELECT s.id, s.src FROM i18n_strings s WHERE NOT EXISTS (SELECT 1 FROM i18n_translations t WHERE t.string_id=s.id AND t.lang='en')") as $r) {
            if (preg_match('/[ëçËÇ]/u', $r['src']) || !preg_match($en, $r['src'])) continue;
            $st->execute(['en', $r['id'], $r['src']]);
            $n += $st->rowCount();
        }
    }
    return $n;
}

/** A duhet filtruar kjo kërkesë? (jo skedarë, jo API, jo librat) */
function i18n_should_filter(): bool {
    if (PHP_SAPI === 'cli') return false;
    $s = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/api/', '/journey_file.php', '/training_material.php', '/sw.js'] as $x) if (str_contains($s, $x)) return false;
    if (str_ends_with($s, '/teacher_certificate.php') && ($_SESSION['role'] ?? '') === 'admin') return false;   // admini e redakton certifikatën në gjuhën burimore
    if (!empty($_GET['export']) || !empty($_GET['download']) || !empty($_GET['csv'])) return false;
    return true;
}
