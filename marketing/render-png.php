<?php

/**
 * Castl-it-POS — marketing PNG renderer.
 * Rasterises the brand assets (logo + banners) into marketing/exports/*.png
 * using GD + the bundled DejaVu font, so we get real files on any host
 * (ImageMagick here has no Freetype/SVG support). The .svg files are the
 * editable masters; these PNGs are the ready-to-post exports.
 *
 * Run:  php marketing/render-png.php
 */

$ROOT = dirname(__DIR__);
$OUT  = __DIR__.'/exports';
@mkdir($OUT, 0775, true);

$BOLD = $ROOT.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
$REG  = $ROOT.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
if (! is_file($BOLD) || ! is_file($REG)) {
    fwrite(STDERR, "DejaVu fonts not found under vendor/dompdf.\n");
    exit(1);
}

// ── Palette ─────────────────────────────────────────────────────────────────
$INK    = [14, 19, 48];
$INK2   = [22, 28, 60];
$BRAND  = [49, 87, 213];
$BRANDL = [64, 100, 232];
$WHITE  = [255, 255, 255];
$MUTED  = [190, 202, 235];
$ACCENT = [245, 158, 11];
$CHIP   = [32, 40, 80];

// ── Helpers ───────────────────────────────────────────────────────────────
function c($img, array $rgb, int $a = 0)
{
    return imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $a);
}

function canvas(int $w, int $h)
{
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 127)); // transparent
    imagealphablending($img, true);

    return $img;
}

function vGradient($img, int $x1, int $y1, int $x2, int $y2, array $c1, array $c2): void
{
    $h = max(1, $y2 - $y1);
    for ($y = 0; $y <= $h; $y++) {
        $t = $y / $h;
        $col = imagecolorallocate(
            $img,
            (int) ($c1[0] + ($c2[0] - $c1[0]) * $t),
            (int) ($c1[1] + ($c2[1] - $c1[1]) * $t),
            (int) ($c1[2] + ($c2[2] - $c1[2]) * $t),
        );
        imagefilledrectangle($img, $x1, $y1 + $y, $x2, $y1 + $y, $col);
    }
}

function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, $color): void
{
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as $cnr) {
        imagefilledellipse($img, $cnr[0], $cnr[1], $r * 2, $r * 2, $color);
    }
}

/** Draw the brand mark (indigo squircle + white "C" + amber dot) at (mx,my), side S. */
function drawMark($img, int $mx, int $my, int $S, string $bold, array $BRAND, array $WHITE, array $ACCENT): void
{
    roundedRect($img, $mx, $my, $mx + $S, $my + $S, (int) ($S * 0.28), c($img, $BRAND));

    $fs = $S * 0.6;
    $bb = imagettfbbox($fs, 0, $bold, 'C');
    $w = $bb[2] - $bb[0];
    $h = $bb[1] - $bb[7];
    $tx = $mx + ($S - $w) / 2 - $bb[0] - $S * 0.03;
    $ty = $my + ($S - $h) / 2 - $bb[7];
    imagettftext($img, $fs, 0, (int) $tx, (int) $ty, c($img, $WHITE), $bold, 'C');

    imagefilledellipse($img, (int) ($mx + $S * 0.84), (int) ($my + $S * 0.6), (int) ($S * 0.2), (int) ($S * 0.2), c($img, $ACCENT));
}

/** Draw "Castl-it-" + "POS" two-tone wordmark; returns total width. */
function wordmark($img, int $x, int $baseline, float $fs, string $bold, $col1, $col2): int
{
    $a = 'Castl-it-';
    imagettftext($img, $fs, 0, $x, $baseline, $col1, $bold, $a);
    $bb = imagettfbbox($fs, 0, $bold, $a);
    $x2 = $x + ($bb[2] - $bb[0]);
    imagettftext($img, $fs, 0, $x2, $baseline, $col2, $bold, 'POS');
    $bb2 = imagettfbbox($fs, 0, $bold, 'POS');

    return ($x2 + ($bb2[2] - $bb2[0])) - $x;
}

function save($img, string $path): void
{
    imagepng($img, $path);
    imagedestroy($img);
    echo '✓ '.basename($path)."\n";
}

// ── 1. App icon (512 + 1024) ────────────────────────────────────────────────
foreach ([512, 1024] as $S) {
    $img = canvas($S, $S);
    $pad = (int) ($S * 0.06);
    drawMark($img, $pad, $pad, $S - 2 * $pad, $BOLD, $BRAND, $WHITE, $ACCENT);
    save($img, "$OUT/castlit-icon-$S.png");
}

// ── 1b. Facebook / social profile picture — full-bleed indigo square that
//        crops cleanly to a circle (no transparent corners, no clipped mark) ──
$S = 1024;
$img = imagecreatetruecolor($S, $S);
imagesavealpha($img, true);
vGradient($img, 0, 0, $S, $S, $BRANDL, $BRAND);
$fs = $S * 0.5;
$bb = imagettfbbox($fs, 0, $BOLD, 'C');
$w = $bb[2] - $bb[0];
$h = $bb[1] - $bb[7];
imagettftext($img, $fs, 0, (int) (($S - $w) / 2 - $bb[0] - $S * 0.04), (int) (($S - $h) / 2 - $bb[7]), c($img, $WHITE), $BOLD, 'C');
imagefilledellipse($img, (int) ($S * 0.6), (int) ($S * 0.55), (int) ($S * 0.15), (int) ($S * 0.15), c($img, $ACCENT));
save($img, "$OUT/castlit-facebook-1024.png");

// ── 1c. Google Play app icon — 512×512, full-bleed, opaque (Play masks the
//        corners + adds shadow itself, so no transparency / no rounding) ──────
$S = 512;
$img = imagecreatetruecolor($S, $S); // opaque (no savealpha)
vGradient($img, 0, 0, $S, $S, $BRANDL, $BRAND);
$fs = $S * 0.5;
$bb = imagettfbbox($fs, 0, $BOLD, 'C');
$w = $bb[2] - $bb[0];
$h = $bb[1] - $bb[7];
imagettftext($img, $fs, 0, (int) (($S - $w) / 2 - $bb[0] - $S * 0.04), (int) (($S - $h) / 2 - $bb[7]), c($img, $WHITE), $BOLD, 'C');
imagefilledellipse($img, (int) ($S * 0.6), (int) ($S * 0.55), (int) ($S * 0.15), (int) ($S * 0.15), c($img, $ACCENT));
save($img, "$OUT/castlit-playstore-icon-512.png");

// ── 2. Horizontal logo (light + dark), transparent background ───────────────
foreach ([['light', $INK, $BRAND], ['dark', $WHITE, $ACCENT]] as [$variant, $c1, $c2]) {
    $H = 300;
    $S = 200;
    $fs = 110;
    $markX = 40;
    $textX = $markX + $S + 60;

    // Size the canvas to the measured wordmark so nothing is clipped.
    $bbA = imagettfbbox($fs, 0, $BOLD, 'Castl-it-');
    $bbB = imagettfbbox($fs, 0, $BOLD, 'POS');
    $wmW = ($bbA[2] - $bbA[0]) + ($bbB[2] - $bbB[0]);
    $W = $textX + $wmW + 48;

    $img = canvas($W, $H);
    $my = (int) (($H - $S) / 2);
    drawMark($img, $markX, $my, $S, $BOLD, $BRAND, $WHITE, $ACCENT);
    wordmark($img, $textX, (int) ($H / 2 + $fs * 0.36), $fs, $BOLD, c($img, $c1), c($img, $c2));
    save($img, "$OUT/castlit-logo-$variant.png");
}

// ── 3. Social banner 1200×630 ───────────────────────────────────────────────
$img = imagecreatetruecolor(1200, 630);
imagesavealpha($img, true);
vGradient($img, 0, 0, 1200, 630, $INK, $INK2);
imagefilledrectangle($img, 0, 0, 1200, 8, c($img, $BRAND));
drawMark($img, 80, 92, 120, $BOLD, $BRAND, $WHITE, $ACCENT);
wordmark($img, 232, 168, 60, $BOLD, c($img, $WHITE), c($img, [139, 166, 255]));
imagettftext($img, 25, 0, 234, 232, c($img, $MUTED), $REG, 'Vendez plus vite. Gérez votre stock. Sans effort.');
imagettftext($img, 52, 0, 80, 360, c($img, $WHITE), $BOLD, 'Votre caisse. Votre stock.');
imagettftext($img, 52, 0, 80, 428, c($img, $ACCENT), $BOLD, 'En ligne & hors-ligne.');
$chips = ['Caisse tactile', 'Gestion de stock', 'Hors-ligne', 'FR · AR · EN'];
$x = 80;
foreach ($chips as $chip) {
    $bb = imagettfbbox(21, 0, $REG, $chip);
    $w = $bb[2] - $bb[0];
    roundedRect($img, $x, 500, $x + $w + 44, 550, 25, c($img, $CHIP));
    imagettftext($img, 21, 0, $x + 22, 532, c($img, $MUTED), $REG, $chip);
    $x += $w + 44 + 16;
}
imagettftext($img, 24, 0, 80, 602, c($img, $ACCENT), $BOLD, 'castlitpos.com');
save($img, "$OUT/banner-social-1200x630.png");

// ── 4. Wide header 1500×500 ─────────────────────────────────────────────────
$img = imagecreatetruecolor(1500, 500);
imagesavealpha($img, true);
vGradient($img, 0, 0, 1500, 500, $INK, $INK2);
drawMark($img, 486, 150, 128, $BOLD, $BRAND, $WHITE, $ACCENT);
wordmark($img, 638, 240, 72, $BOLD, c($img, $WHITE), c($img, [139, 166, 255]));
$tag = 'Le point de vente et la gestion de stock pour votre commerce';
$bb = imagettfbbox(28, 0, $REG, $tag);
imagettftext($img, 28, 0, (int) (750 - ($bb[2] - $bb[0]) / 2), 330, c($img, $MUTED), $REG, $tag);
$dom = 'castlitpos.com';
$bb = imagettfbbox(22, 0, $BOLD, $dom);
imagettftext($img, 22, 0, (int) (750 - ($bb[2] - $bb[0]) / 2), 392, c($img, $ACCENT), $BOLD, $dom);
save($img, "$OUT/banner-wide-1500x500.png");

echo "\nDone → marketing/exports/\n";
