<?php
declare(strict_types=1);

/** Grafikë PNG (GD) për dokumentet Word — kthen bytes PNG ose null nëse GD mungon */
function chart_font(bool $bold = false): ?string {
    $f = APP_ROOT . '/assets/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
    return is_file($f) ? $f : null;
}
function chart_text($im, float $size, int $x, int $y, string $text, int $color, bool $bold = false, string $align = 'left'): void {
    $font = chart_font($bold);
    if (!$font || !function_exists('imagettftext')) { imagestring($im, 3, $x, $y - 12, $text, $color); return; }
    $box = imagettfbbox($size, 0, $font, $text);
    $w = $box[2] - $box[0];
    if ($align === 'right') $x -= $w; elseif ($align === 'center') $x -= (int)($w / 2);
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}
function chart_png($im): string {
    ob_start(); imagepng($im, null, 6); imagedestroy($im);
    return (string)ob_get_clean();
}

/** Grafik horizontal (p.sh. "funnel"): $rows = [[label, value, note?], ...] */
function chart_hbars(array $rows, string $title, string $color = '#2f5bea', ?float $max = null, string $suffix = ''): ?string {
    if (!function_exists('imagecreatetruecolor') || !$rows) return null;
    $W = 1600; $rowH = 74; $top = 90; $H = $top + count($rows) * $rowH + 30;
    $im = imagecreatetruecolor($W, $H);
    imagesavealpha($im, true);
    $white = imagecolorallocate($im, 255, 255, 255); $ink = imagecolorallocate($im, 28, 35, 51); $muted = imagecolorallocate($im, 93, 103, 130);
    $track = imagecolorallocate($im, 232, 236, 244);
    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x'); $bar = imagecolorallocate($im, $r, $g, $b);
    imagefill($im, 0, 0, $white);
    chart_text($im, 26, 30, 52, $title, $ink, true);
    $max = $max ?? max(1, ...array_map(fn($x) => (float)$x[1], $rows));
    $labelW = 560; $barX = $labelW + 30; $barW = $W - $barX - 210;
    foreach ($rows as $i => $row) {
        $y = $top + $i * $rowH;
        chart_text($im, 19, $labelW, $y + 38, (string)$row[0], $ink, false, 'right');
        imagefilledrectangle($im, $barX, $y + 14, $barX + $barW, $y + 50, $track);
        $len = (int)round($barW * min(1, (float)$row[1] / $max));
        if ($len > 0) imagefilledrectangle($im, $barX, $y + 14, $barX + $len, $y + 50, $bar);
        $val = (is_float($row[1]) ? number_format($row[1], 1) : number_format((float)$row[1])) . $suffix;
        chart_text($im, 19, $barX + $barW + 16, $y + 40, $val . (isset($row[2]) && $row[2] !== '' ? '  ' . $row[2] : ''), $muted, true);
    }
    return chart_png($im);
}

/** Grafik vertikal (p.sh. minutat në javë): $points = [[label, value], ...] */
function chart_vbars(array $points, string $title, string $unit = '', string $color = '#2f5bea'): ?string {
    if (!function_exists('imagecreatetruecolor') || !$points) return null;
    $W = 1600; $H = 620; $left = 110; $right = 30; $top = 100; $bottom = 90;
    $im = imagecreatetruecolor($W, $H);
    $white = imagecolorallocate($im, 255, 255, 255); $ink = imagecolorallocate($im, 28, 35, 51); $muted = imagecolorallocate($im, 93, 103, 130);
    $grid = imagecolorallocate($im, 226, 231, 240);
    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x'); $bar = imagecolorallocate($im, $r, $g, $b);
    imagefill($im, 0, 0, $white);
    chart_text($im, 26, 30, 52, $title, $ink, true);
    $max = max(1, ...array_map(fn($p) => (float)$p[1], $points));
    $mag = 10 ** max(0, (int)floor(log10($max)));
    $max = ceil($max / $mag * 2) * $mag / 2;
    $plotH = $H - $top - $bottom; $plotW = $W - $left - $right;
    foreach ([0, 0.25, 0.5, 0.75, 1] as $f) {
        $y = (int)($top + $plotH * (1 - $f));
        imageline($im, $left, $y, $W - $right, $y, $grid);
        chart_text($im, 15, $left - 12, $y + 6, number_format($max * $f), $muted, false, 'right');
    }
    $n = count($points); $slot = $plotW / $n; $bw = max(4, (int)($slot * 0.68));
    $every = $n > 16 ? (int)ceil($n / 12) : 1;
    foreach ($points as $i => [$label, $v]) {
        $x = (int)($left + $i * $slot + ($slot - $bw) / 2);
        $h = (int)round($plotH * (float)$v / $max);
        if ($h > 0) imagefilledrectangle($im, $x, $top + $plotH - $h, $x + $bw, $top + $plotH, $bar);
        if ($i % $every === 0) chart_text($im, 14, $x + (int)($bw / 2), $top + $plotH + 30, (string)$label, $muted, false, 'center');
    }
    if ($unit) chart_text($im, 15, $left, $H - 20, $unit, $muted);
    return chart_png($im);
}
