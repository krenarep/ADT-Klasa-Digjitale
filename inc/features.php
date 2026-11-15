<?php
declare(strict_types=1);

/** Katalogu i veçorive që ndiqen. a11y = veçori qasshmërie */
function feature_catalog(): array {
    return [
        'tts'             => ['🔊', 'Teksti në të folur (lexim me zë)', true],
        'sign_language'   => ['🤟', 'Gjuha e shenjave', true],
        'easy_read'       => ['📗', 'Lexim i lehtë (tekst i thjeshtuar)', true],
        'eli5'            => ['💡', 'Shpjegoje për mua (zgjerimi i tekstit)', true],
        'syllables'       => ['✂️', 'Ndarja në rrokje', true],
        'glossary'        => ['📖', 'Fjalori', true],
        'describe_images' => ['🖼️', 'Përshkrimi i imazheve', true],
        'highlight'       => ['🖍️', 'Theksimi i tekstit', true],
        'language'        => ['🌐', 'Gjuha / përkthimi', true],
        'display'         => ['🎨', 'Pamja (tema, madhësia, lëvizja)', true],
        'screen_reader'   => ['🦻', 'Navigim me tastierë / lexues ekrani', true],
        'portal_a11y'     => ['♿', 'Qasshmëria në portal (A+, kontrast, lexim)', true],
        'notepad'         => ['📝', 'Shënimet', false],
        'navigation'      => ['➡️', 'Navigimi (faqja tjetër/e mëparshme)', false],
        'toc'             => ['📑', 'Përmbajtja / indeksi', false],
        'settings'        => ['⚙️', 'Paneli i cilësimeve', false],
        'tutorial'        => ['🧭', 'Tutoriali / udhëzimet', false],
        'activity'        => ['✍️', 'Plotësimi i ushtrimeve', false],
        'submit'          => ['✅', 'Dërgo përgjigjet', false],
        'retry'           => ['🔁', 'Provo përsëri / rivendos', false],
        'help'            => ['🙋', 'Më ndihmo (tiketa)', false],
        'print'           => ['🖨️', 'Printimi', false],
        'keyboard'        => ['⌨️', 'Shkurtore tastiere', false],
        'content'         => ['👆', 'Klikime të tjera në përmbajtje', false],
    ];
}
function feature_label(string $f): string {
    $c = feature_catalog()[$f] ?? ['•', $f];
    return $c[0] . ' ' . $c[1];
}
function a11y_features(): array {
    return array_keys(array_filter(feature_catalog(), fn($c) => $c[2]));
}

/** Rregullat: çelësi i përkthimit të ndërfaqes ADT → veçoria */
function feature_key_rules(): array {
    return [
        ['/sign-language/', 'sign_language'],
        ['/easy-read/', 'easy_read'],
        ['/eli5/', 'eli5'],
        ['/syllable/', 'syllables'],
        ['/glossary|filter-terms/', 'glossary'],
        ['/describe-images/', 'describe_images'],
        ['/highlight/', 'highlight'],
        ['/notepad/', 'notepad'],
        ['/tts|read-aloud|narrator|audio|autoplay|mute|volume|^play-|^pause-|^stop-/', 'tts'],
        ['/^language|shortcut-language/', 'language'],
        ['/tutorial/', 'tutorial'],
        ['/^submit-text$/', 'submit'],
        ['/retry|reset-text|try-again/', 'retry'],
        ['/print/', 'print'],
        ['/^toc|nav-label|nav-page|main-menu|shortcut-toc|cover-label|search-placeholder/', 'toc'],
        ['/next-page|previous-page|^next$|^previous$|next-activity|^finish$/', 'navigation'],
        ['/sidebar-settings|shortcut-settings|settings|sidebar-title|sidebar-assistant/', 'settings'],
        ['/state-label|theme|icon-size|motion|dock/', 'display'],
    ];
}

/**
 * Harta etiketë → çelës nga përkthimet e ndërfaqes së librit ADT (të gjitha gjuhët).
 * Kthen [ 'etiketa e normalizuar' => 'feature' ]
 */
function book_label_map(array $book): array {
    $base = APP_ROOT . '/content/books/' . $book['folder'] . '/assets/interface_translations';
    $map = [];
    foreach (glob($base . '/*/interface_translations.json') ?: [] as $f) {
        $tr = json_decode((string)file_get_contents($f), true);
        if (!is_array($tr)) continue;
        foreach ($tr as $key => $label) {
            if (!is_string($label) || trim($label) === '' || str_contains($label, '${') || mb_strlen($label) > 80) continue;
            foreach (feature_key_rules() as [$re, $feat]) {
                if (preg_match($re, (string)$key)) {
                    $map[mb_strtolower(trim(strip_tags($label)))] = $feat;
                    break;
                }
            }
        }
    }
    return $map;
}
