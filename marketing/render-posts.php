<?php

/**
 * Castl-it-POS — Facebook/Instagram post images (1080×1080).
 * One branded square card per marketing post, written to marketing/exports/posts/.
 * Uses GD + the bundled DejaVu font (no ImageMagick/Inter needed on the host).
 *
 * Run:  php marketing/render-posts.php
 */

$ROOT = dirname(__DIR__);
$OUT  = __DIR__.'/exports/posts';
@mkdir($OUT, 0775, true);

$BOLD = $ROOT.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
$REG  = $ROOT.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
if (! is_file($BOLD) || ! is_file($REG)) {
    fwrite(STDERR, "DejaVu fonts not found under vendor/dompdf.\n");
    exit(1);
}

$INK    = [14, 19, 48];
$INK2   = [22, 28, 60];
$BRAND  = [49, 87, 213];
$BRANDL = [64, 100, 232];
$WHITE  = [255, 255, 255];
$MUTED  = [200, 210, 235];
$ACCENT = [245, 158, 11];

function c($img, array $rgb, int $a = 0)
{
    return imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $a);
}

function vGradient($img, int $x1, int $y1, int $x2, int $y2, array $a, array $b): void
{
    $h = max(1, $y2 - $y1);
    for ($y = 0; $y <= $h; $y++) {
        $t = $y / $h;
        $col = imagecolorallocate($img, (int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t));
        imagefilledrectangle($img, $x1, $y1 + $y, $x2, $y1 + $y, $col);
    }
}

function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, $color): void
{
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as $p) {
        imagefilledellipse($img, $p[0], $p[1], $r * 2, $r * 2, $color);
    }
}

function drawMark($img, int $mx, int $my, int $S, string $bold, $BRAND, $WHITE, $ACCENT): void
{
    roundedRect($img, $mx, $my, $mx + $S, $my + $S, (int) ($S * 0.28), c($img, $BRAND));
    $fs = $S * 0.6;
    $bb = imagettfbbox($fs, 0, $bold, 'C');
    $w = $bb[2] - $bb[0];
    $h = $bb[1] - $bb[7];
    imagettftext($img, $fs, 0, (int) ($mx + ($S - $w) / 2 - $bb[0] - $S * 0.03), (int) ($my + ($S - $h) / 2 - $bb[7]), c($img, $WHITE), $bold, 'C');
    imagefilledellipse($img, (int) ($mx + $S * 0.84), (int) ($my + $S * 0.6), (int) ($S * 0.2), (int) ($S * 0.2), c($img, $ACCENT));
}

function wrapByWidth(string $text, float $fs, string $font, int $maxW): array
{
    $words = explode(' ', $text);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $try = $line === '' ? $word : $line.' '.$word;
        $bb = imagettfbbox($fs, 0, $font, $try);
        if (($bb[2] - $bb[0]) > $maxW && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines;
}

function drawCheck($img, float $cx, float $cy, float $s, $col): void
{
    imagesetthickness($img, max(3, (int) ($s * 0.22)));
    imageline($img, (int) ($cx - $s * 0.5), (int) $cy, (int) ($cx - $s * 0.08), (int) ($cy + $s * 0.42), $col);
    imageline($img, (int) ($cx - $s * 0.08), (int) ($cy + $s * 0.42), (int) ($cx + $s * 0.6), (int) ($cy - $s * 0.5), $col);
    imagesetthickness($img, 1);
}

function card(array $p, string $out, string $BOLD, string $REG, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $W = 1080;
    $img = imagecreatetruecolor($W, $W);
    imagesavealpha($img, true);
    vGradient($img, 0, 0, $W, $W, $INK, $INK2);
    imagefilledrectangle($img, 0, 0, $W, 10, c($img, $BRAND));

    // Logo lockup (top-left)
    drawMark($img, 80, 74, 96, $BOLD, $BRAND, $WHITE, $ACCENT);
    imagettftext($img, 44, 0, 200, 150, c($img, $WHITE), $BOLD, 'Castl-it-');
    $bb = imagettfbbox(44, 0, $BOLD, 'Castl-it-');
    imagettftext($img, 44, 0, 200 + ($bb[2] - $bb[0]), 150, c($img, [139, 166, 255]), $BOLD, 'POS');

    // Kicker
    imagettftext($img, 24, 0, 82, 300, c($img, $ACCENT), $BOLD, mb_strtoupper($p['kicker']));

    // Headline (wrapped)
    $fs = 62;
    $y = 380;
    foreach (wrapByWidth($p['headline'], $fs, $BOLD, $W - 160) as $line) {
        imagettftext($img, $fs, 0, 80, $y, c($img, $WHITE), $BOLD, $line);
        $y += 82;
    }

    // Bullets
    $y += 30;
    foreach ($p['bullets'] as $b) {
        drawCheck($img, 104, $y - 12, 34, c($img, $ACCENT));
        imagettftext($img, 33, 0, 150, $y, c($img, $MUTED), $REG, $b);
        $y += 74;
    }

    // Footer: free-trial chip (own line) then the domain
    $chip = 'Essai gratuit · sans engagement';
    $bb = imagettfbbox(24, 0, $REG, $chip);
    $cw = $bb[2] - $bb[0];
    roundedRect($img, 80, 916, 80 + $cw + 48, 970, 27, c($img, [32, 40, 80]));
    imagettftext($img, 24, 0, 104, 951, c($img, $MUTED), $REG, $chip);
    imagettftext($img, 36, 0, 80, 1022, c($img, $ACCENT), $BOLD, 'castlitpos.com');

    imagepng($img, $out);
    imagedestroy($img);
    echo '✓ '.basename($out)."\n";
}

$pal = [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT];

$posts = [
    ['file' => 'post-lancement.png', 'kicker' => 'Nouveau',
     'headline' => 'Une caisse à la hauteur de votre commerce.',
     'bullets' => ['Encaissez en quelques secondes', 'Stock en temps réel', 'Fonctionne hors-ligne', 'FR · AR · EN']],

    ['file' => 'post-hors-ligne.png', 'kicker' => 'Hors-ligne',
     'headline' => 'Internet coupé ? Vous vendez quand même.',
     'bullets' => ['Ventes & stock hors-ligne', 'Synchronisation automatique', 'Zéro vente perdue']],

    ['file' => 'post-gestion-stock.png', 'kicker' => 'Gestion de stock',
     'headline' => 'Votre stock à jour à chaque vente.',
     'bullets' => ['Alertes de stock bas', 'Entrées, sorties, retours', 'Transferts entre points de vente']],

    ['file' => 'post-multi-terminaux.png', 'kicker' => 'Multi-terminaux',
     'headline' => 'Une seule caisse. Tous vos appareils.',
     'bullets' => ['Ordinateur · Tablette · Mobile', 'Données synchronisées en direct', 'Ajoutez un poste en un clic']],

    ['file' => 'post-essai.png', 'kicker' => 'Essai gratuit',
     'headline' => 'Testez gratuitement, sans engagement.',
     'bullets' => ['Sans carte bancaire', 'Prêt en quelques minutes', 'FR · AR · EN']],

    ['file' => 'post-secteurs.png', 'kicker' => 'Pour votre métier',
     'headline' => 'Une caisse qui s\'adapte à votre commerce.',
     'bullets' => ['Librairie & papeterie', 'Café & restaurant', 'Pharmacie · Épicerie · Retail']],
];

foreach ($posts as $p) {
    card($p, "$OUT/{$p['file']}", $BOLD, $REG, $pal);
}

// ── Hardware illustrations ──────────────────────────────────────────────────

function barcode($img, int $x, int $y, int $w, int $h, $white, $dark): void
{
    roundedRect($img, $x, $y, $x + $w, $y + $h, 14, $white);
    $widths = [5, 3, 8, 4, 3, 7, 4, 9, 3, 5, 4, 8, 3, 4, 7, 3, 5, 4, 8, 3, 6, 4, 3, 8, 4, 5, 3, 7];
    $px = $x + 26;
    $i = 0;
    while ($px < $x + $w - 26) {
        $bw = $widths[$i % count($widths)];
        if ($i % 2 === 0) {
            imagefilledrectangle($img, $px, $y + 24, $px + $bw, $y + $h - 40, $dark);
        }
        $px += $bw;
        $i++;
    }
}

/** Cash drawer (open, top-down): tray with bill slots + coin cups. */
function illusDrawer($img, int $cx, int $cy, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $body = c($img, [46, 56, 96]);
    $tray = c($img, [30, 38, 74]);
    $slot = c($img, [20, 26, 54]);
    $w = 460;
    $h = 300;
    $x = $cx - $w / 2;
    $y = $cy - $h / 2;
    roundedRect($img, $x, $y, $x + $w, $y + $h, 24, $body);
    // front handle
    roundedRect($img, (int) ($cx - 46), $y + $h - 14, (int) ($cx + 46), $y + $h + 12, 12, c($img, [64, 76, 122]));
    // bill slots (top row)
    for ($i = 0; $i < 4; $i++) {
        $bx = $x + 30 + $i * 106;
        roundedRect($img, $bx, $y + 28, $bx + 88, $y + 150, 10, $tray);
        imagefilledrectangle($img, $bx + 12, $y + 44, $bx + 76, $y + 52, c($img, [90, 104, 150]));
    }
    // coin cups (bottom row)
    for ($i = 0; $i < 5; $i++) {
        $bx = $x + 34 + $i * 86;
        roundedRect($img, $bx, $y + 172, $bx + 70, $y + $h - 24, 12, $slot);
        imagefilledellipse($img, $bx + 35, $y + 210, 44, 44, c($img, [58, 70, 116]));
    }
    // amber "cha-ching" coin
    imagefilledellipse($img, $x + $w - 20, $y - 8, 46, 46, c($img, $ACCENT));
}

/** Handheld barcode scanner shooting a red beam at a barcode. */
function illusScanner($img, int $cx, int $cy, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $body = c($img, [46, 56, 96]);
    $dark = c($img, [17, 20, 35]);
    $white = c($img, [245, 247, 252]);
    $red = c($img, [239, 68, 68]);

    // barcode being scanned (lower area)
    barcode($img, $cx - 70, $cy + 60, 300, 150, $white, $dark);

    // scanner: head + grip (upper-left, pointing down-right)
    roundedRect($img, $cx - 250, $cy - 150, $cx - 70, $cy - 70, 26, $body);   // head
    roundedRect($img, $cx - 210, $cy - 74, $cx - 150, $cy + 40, 22, $body);   // grip
    imagefilledrectangle($img, $cx - 150, $cy - 128, $cx - 96, $cy - 92, $dark); // window
    // trigger
    roundedRect($img, $cx - 196, $cy - 40, $cx - 176, $cy - 4, 8, c($img, [30, 38, 74]));

    // red beam (3 rays from window to barcode)
    imagesetthickness($img, 4);
    imageline($img, $cx - 108, $cy - 96, $cx + 10, $cy + 58, $red);
    imageline($img, $cx - 108, $cy - 96, $cx + 80, $cy + 58, $red);
    imageline($img, $cx - 108, $cy - 96, $cx + 150, $cy + 58, $red);
    imagesetthickness($img, 1);
}

/** POS terminal: touchscreen on a stand showing a mini sale UI. */
function illusTerminal($img, int $cx, int $cy, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $screen = c($img, [24, 30, 58]);
    $bezel = c($img, [46, 56, 96]);
    $line = c($img, [90, 104, 150]);

    $w = 440;
    $h = 300;
    $x = $cx - $w / 2;
    $y = $cy - $h / 2 - 30;
    // stand
    imagefilledrectangle($img, (int) ($cx - 24), $y + $h, (int) ($cx + 24), $y + $h + 70, $bezel);
    roundedRect($img, (int) ($cx - 120), $y + $h + 66, (int) ($cx + 120), $y + $h + 96, 14, $bezel);
    // bezel + screen
    roundedRect($img, $x - 12, $y - 12, $x + $w + 12, $y + $h + 12, 26, $bezel);
    roundedRect($img, $x, $y, $x + $w, $y + $h, 16, $screen);
    // top bar
    roundedRect($img, $x + 20, $y + 20, $x + $w - 20, $y + 58, 10, c($img, [40, 50, 92]));
    imagefilledellipse($img, $x + 44, $y + 39, 20, 20, c($img, $ACCENT));
    // product rows
    for ($i = 0; $i < 3; $i++) {
        $ry = $y + 82 + $i * 46;
        imagefilledrectangle($img, $x + 24, $ry, $x + 210, $ry + 12, $line);
        imagefilledrectangle($img, $x + $w - 120, $ry, $x + $w - 24, $ry + 12, c($img, [70, 84, 130]));
    }
    // total + pay button
    imagefilledrectangle($img, $x + 24, $y + $h - 66, $x + 150, $y + $h - 52, c($img, [120, 134, 175]));
    roundedRect($img, $x + $w - 180, $y + $h - 76, $x + $w - 24, $y + $h - 26, 14, c($img, $ACCENT));
}

/** Stack of books + a pencil — back-to-school / bookshop theme. */
function illusBooks($img, int $cx, int $cy, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $books = [
        [380, [49, 87, 213]],     // brand indigo
        [430, [90, 104, 150]],    // slate
        [350, [245, 158, 11]],    // amber
        [300, [230, 235, 245]],   // near-white
    ];
    $h = 54;
    $gap = 9;
    $n = count($books);
    $base = $cy + 130;                     // bottom of the stack
    foreach ($books as $i => [$w, $col]) {
        $bx = (int) ($cx - $w / 2 + (($i % 2) ? 20 : -20));
        $by = (int) ($base - ($i + 1) * ($h + $gap));
        roundedRect($img, $bx, $by, $bx + $w, $by + $h, 10, c($img, $col));
        imagefilledrectangle($img, $bx + 8, $by + $h - 12, $bx + $w - 8, $by + $h - 7, c($img, [255, 255, 255], 45)); // page edge
        imagefilledrectangle($img, $bx + 16, $by + 9, $bx + 24, $by + $h - 9, c($img, [0, 0, 0], 75));                // spine band
    }
    // pencil resting on top
    $top = (int) ($base - $n * ($h + $gap) - 30);
    $px1 = $cx - 160;
    $px2 = $cx + 120;
    roundedRect($img, $px1, $top, $px2, $top + 24, 12, c($img, $ACCENT));
    imagefilledpolygon($img, [$px2, $top, $px2 + 38, $top + 12, $px2, $top + 24], c($img, [60, 66, 90])); // graphite tip
    roundedRect($img, $px1 - 28, $top, $px1, $top + 24, 8, c($img, [239, 120, 140]));                     // eraser
}

function hardwareCard(array $p, string $out, string $BOLD, string $REG, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $W = 1080;
    $img = imagecreatetruecolor($W, $W);
    imagesavealpha($img, true);
    vGradient($img, 0, 0, $W, $W, $INK, $INK2);
    imagefilledrectangle($img, 0, 0, $W, 10, c($img, $BRAND));

    drawMark($img, 80, 74, 96, $BOLD, $BRAND, $WHITE, $ACCENT);
    imagettftext($img, 44, 0, 200, 150, c($img, $WHITE), $BOLD, 'Castl-it-');
    $bb = imagettfbbox(44, 0, $BOLD, 'Castl-it-');
    imagettftext($img, 44, 0, 200 + ($bb[2] - $bb[0]), 150, c($img, [139, 166, 255]), $BOLD, 'POS');

    imagettftext($img, 24, 0, 82, 288, c($img, $ACCENT), $BOLD, mb_strtoupper($p['kicker']));
    $y = 360;
    foreach (wrapByWidth($p['headline'], 56, $BOLD, $W - 160) as $line) {
        imagettftext($img, 56, 0, 80, $y, c($img, $WHITE), $BOLD, $line);
        $y += 76;
    }
    imagettftext($img, 30, 0, 82, $y + 8, c($img, $MUTED), $REG, $p['sub']);

    // illustration centered lower
    $p['illus']($img, 540, $p['cy'] ?? 740, $pal);

    // footer
    $chip = 'Essai gratuit · sans engagement';
    $bb = imagettfbbox(24, 0, $REG, $chip);
    $cw = $bb[2] - $bb[0];
    roundedRect($img, 80, 916, 80 + $cw + 48, 970, 27, c($img, [32, 40, 80]));
    imagettftext($img, 24, 0, 104, 951, c($img, $MUTED), $REG, $chip);
    imagettftext($img, 36, 0, 80, 1022, c($img, $ACCENT), $BOLD, 'castlitpos.com');

    imagepng($img, $out);
    imagedestroy($img);
    echo '✓ '.basename($out)."\n";
}

$hardware = [
    ['file' => 'post-tiroir-caisse.png', 'kicker' => 'Matériel', 'illus' => 'illusDrawer', 'cy' => 720,
     'headline' => 'Compatible avec votre tiroir-caisse.',
     'sub' => 'Ouverture automatique à chaque vente.'],

    ['file' => 'post-code-barres.png', 'kicker' => 'Scan', 'illus' => 'illusScanner', 'cy' => 700,
     'headline' => 'Scannez, encaissez, terminé.',
     'sub' => 'Compatible lecteurs de codes-barres.'],

    ['file' => 'post-terminal.png', 'kicker' => 'Point de vente', 'illus' => 'illusTerminal', 'cy' => 690,
     'headline' => 'Un vrai point de vente, sur tout écran.',
     'sub' => 'Ordinateur, tablette ou terminal tactile.'],

    ['file' => 'post-librairie-rentree.png', 'kicker' => 'Rentrée scolaire', 'illus' => 'illusBooks', 'cy' => 700,
     'headline' => 'Prêt pour la ruée de la rentrée ?',
     'sub' => 'Manuels, fournitures, cahiers — vendez plus vite.'],
];

foreach ($hardware as $p) {
    hardwareCard($p, "$OUT/{$p['file']}", $BOLD, $REG, $pal);
}

// ── Marketplace offer card (with a big price badge) ─────────────────────────
function marketplaceCard(string $out, string $BOLD, string $REG, array $pal): void
{
    [$INK, $INK2, $BRAND, $BRANDL, $WHITE, $MUTED, $ACCENT] = $pal;
    $W = 1080;
    $img = imagecreatetruecolor($W, $W);
    imagesavealpha($img, true);
    vGradient($img, 0, 0, $W, $W, $INK, $INK2);
    imagefilledrectangle($img, 0, 0, $W, 10, c($img, $BRAND));

    // header
    drawMark($img, 80, 74, 96, $BOLD, $BRAND, $WHITE, $ACCENT);
    imagettftext($img, 44, 0, 200, 150, c($img, $WHITE), $BOLD, 'Castl-it-');
    $bb = imagettfbbox(44, 0, $BOLD, 'Castl-it-');
    imagettftext($img, 44, 0, 200 + ($bb[2] - $bb[0]), 150, c($img, [139, 166, 255]), $BOLD, 'POS');

    // "offre limitée" tag (top-right)
    $tag = 'OFFRE LIMITÉE';
    $tb = imagettfbbox(22, 0, $BOLD, $tag);
    $tw = $tb[2] - $tb[0];
    roundedRect($img, $W - 80 - $tw - 44, 96, $W - 80, 146, 25, c($img, $ACCENT));
    imagettftext($img, 22, 0, $W - 80 - $tw - 22, 130, c($img, $INK), $BOLD, $tag);

    // kicker + headline
    imagettftext($img, 24, 0, 82, 268, c($img, $ACCENT), $BOLD, 'PACK CAISSE COMPLET');
    $y = 336;
    foreach (wrapByWidth('Votre commerce, clé en main.', 56, $BOLD, $W - 160) as $line) {
        imagettftext($img, 54, 0, 80, $y, c($img, $WHITE), $BOLD, $line);
        $y += 74;
    }

    // bullets
    $y += 22;
    $bullets = [
        'Caisse tactile + gestion de stock',
        'Hors-ligne, multi-postes, FR · AR · EN',
        'Code-barres & tiroir-caisse compatibles',
        'Installation & accompagnement inclus',
    ];
    foreach ($bullets as $b) {
        drawCheck($img, 104, $y - 12, 32, c($img, $ACCENT));
        imagettftext($img, 30, 0, 148, $y, c($img, $MUTED), $REG, $b);
        $y += 62;
    }

    // BIG price badge (amber pill, centered)
    $price = '1 800 DH';
    $pfs = 88;
    $pb = imagettfbbox($pfs, 0, $BOLD, $price);
    $pw = $pb[2] - $pb[0];
    $ph = $pb[1] - $pb[7];
    $bw = $pw + 120;
    $bx = (int) (($W - $bw) / 2);
    $byTop = $y + 20;
    $byBot = $byTop + $ph + 56;
    roundedRect($img, $bx, $byTop, $bx + $bw, $byBot, 32, c($img, $ACCENT));
    imagettftext($img, $pfs, 0, (int) (($W - $pw) / 2 - $pb[0]), (int) ($byTop + 28 - $pb[7]), c($img, $INK), $BOLD, $price);

    // footer
    imagettftext($img, 34, 0, 80, 1022, c($img, $ACCENT), $BOLD, 'castlitpos.com');
    $note = 'Essai gratuit avant achat';
    $nb = imagettfbbox(26, 0, $REG, $note);
    imagettftext($img, 26, 0, (int) ($W - 80 - ($nb[2] - $nb[0])), 1018, c($img, $MUTED), $REG, $note);

    imagepng($img, $out);
    imagedestroy($img);
    echo '✓ '.basename($out)."\n";
}

marketplaceCard("$OUT/post-marketplace-1800dh.png", $BOLD, $REG, $pal);

echo "\nDone → marketing/exports/posts/\n";
