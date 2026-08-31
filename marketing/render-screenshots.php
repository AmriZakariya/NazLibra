<?php

/**
 * Castl-it-POS — Play Store visual assets.
 *   • 4 phone screenshots (1080×1920) — device frame + branded bg + caption,
 *     each showing a clean recreation of a key app screen.
 *   • 1 feature graphic (1024×500) — required by the Play listing.
 * Output → marketing/exports/playstore/. Uses GD + bundled DejaVu font.
 *
 * Run:  php marketing/render-screenshots.php
 */

$ROOT = dirname(__DIR__);
$OUT  = __DIR__.'/exports/playstore';
@mkdir($OUT, 0775, true);

$BOLD = $ROOT.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
$REG  = $ROOT.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
if (! is_file($BOLD) || ! is_file($REG)) {
    fwrite(STDERR, "DejaVu fonts not found.\n");
    exit(1);
}

// palette
$INK=[14,19,48]; $INK2=[22,28,60]; $BRAND=[49,87,213]; $BRANDL=[64,100,232];
$WHITE=[255,255,255]; $MUTED=[190,202,235]; $ACCENT=[245,158,11];
$SCREEN=[244,246,251]; $CARD=[255,255,255]; $BORDER=[228,232,242];
$TXT=[14,19,48]; $SUB=[138,147,168]; $GREY=[224,228,240];

function c($img,$rgb,$a=0){ return imagecolorallocatealpha($img,$rgb[0],$rgb[1],$rgb[2],$a); }
function vGrad($img,$x1,$y1,$x2,$y2,$a,$b){ $h=max(1,$y2-$y1); for($y=0;$y<=$h;$y++){ $t=$y/$h; imagefilledrectangle($img,$x1,$y1+$y,$x2,$y1+$y, imagecolorallocate($img,(int)($a[0]+($b[0]-$a[0])*$t),(int)($a[1]+($b[1]-$a[1])*$t),(int)($a[2]+($b[2]-$a[2])*$t))); } }
function rr($img,$x1,$y1,$x2,$y2,$r,$col){ if($x2-$x1<2*$r)$r=(int)(($x2-$x1)/2); if($y2-$y1<2*$r)$r=(int)(($y2-$y1)/2); if($r<1){imagefilledrectangle($img,$x1,$y1,$x2,$y2,$col);return;} imagefilledrectangle($img,$x1+$r,$y1,$x2-$r,$y2,$col); imagefilledrectangle($img,$x1,$y1+$r,$x2,$y2-$r,$col); foreach([[$x1+$r,$y1+$r],[$x2-$r,$y1+$r],[$x1+$r,$y2-$r],[$x2-$r,$y2-$r]] as $p) imagefilledellipse($img,$p[0],$p[1],$r*2,$r*2,$col); }
function mark($img,$mx,$my,$S,$bold,$BRAND,$WHITE,$ACCENT){ rr($img,$mx,$my,$mx+$S,$my+$S,(int)($S*0.28),c($img,$BRAND)); $fs=$S*0.6; $bb=imagettfbbox($fs,0,$bold,'C'); $w=$bb[2]-$bb[0]; $h=$bb[1]-$bb[7]; imagettftext($img,$fs,0,(int)($mx+($S-$w)/2-$bb[0]-$S*0.03),(int)($my+($S-$h)/2-$bb[7]),c($img,$WHITE),$bold,'C'); imagefilledellipse($img,(int)($mx+$S*0.84),(int)($my+$S*0.6),(int)($S*0.2),(int)($S*0.2),c($img,$ACCENT)); }
function wrapW($t,$fs,$f,$max){ $words=explode(' ',$t); $lines=[]; $l=''; foreach($words as $w){ $try=$l===''?$w:$l.' '.$w; $bb=imagettfbbox($fs,0,$f,$try); if(($bb[2]-$bb[0])>$max&&$l!==''){ $lines[]=$l; $l=$w; } else $l=$try; } if($l!=='')$lines[]=$l; return $lines; }
function ctext($img,$cx,$y,$fs,$f,$col,$t){ $bb=imagettfbbox($fs,0,$f,$t); imagettftext($img,$fs,0,(int)($cx-($bb[2]-$bb[0])/2),$y,$col,$f,$t); }

/**
 * Draw the phone frame on the branded canvas + fill the screen; returns
 * [sx,sy,sw,sh] of the inner screen area.
 */
function phone($img,$cx,$top,$sw,$sh,$SCREEN){
    $bez=14; $rad=54;
    $fx=(int)($cx-$sw/2); $fy=$top;
    rr($img,$fx-$bez,$fy-$bez,$fx+$sw+$bez,$fy+$sh+$bez,$rad+$bez, c($img,[8,11,26])); // bezel
    rr($img,$fx,$fy,$fx+$sw,$fy+$sh,$rad, c($img,$SCREEN));                            // screen
    // camera dot
    imagefilledellipse($img,$cx,$fy+26,12,12,c($img,[40,48,80]));
    return [$fx,$fy,$sw,$sh];
}

function save($img,$path){ imagepng($img,$path); imagedestroy($img); echo '✓ '.basename($path)."\n"; }

$PAL = compact('INK','INK2','BRAND','BRANDL','WHITE','MUTED','ACCENT','SCREEN','CARD','BORDER','TXT','SUB','GREY');

// ── App screen renderers (draw inside inner rect [x,y,w,h]) ─────────────────
function appHeader($img,$x,$y,$w,$title,$P,$BOLD,$REG){
    imagettftext($img,15,0,$x+28,$y+60,c($img,$P['SUB']),$REG,'9:41');
    imagefilledellipse($img,$x+$w-52,$y+52,44,44,c($img,$P['BRAND']));
    $ab=imagettfbbox(16,0,$BOLD,'AM');
    imagettftext($img,16,0,(int)($x+$w-52-($ab[2]-$ab[0])/2),$y+58,c($img,$P['WHITE']),$BOLD,'AM');
    imagettftext($img,26,0,$x+28,$y+118,c($img,$P['TXT']),$BOLD,$title);
    return $y+150;
}

function screenPOS($img,$r,$P,$BOLD,$REG){
    [$x,$y,$w,$h]=$r;
    $cy=appHeader($img,$x,$y,$w,'Caisse',$P,$BOLD,$REG);
    // search
    rr($img,$x+24,$cy,$x+$w-24,$cy+56,16,c($img,$P['GREY'])); imagettftext($img,16,0,$x+48,$cy+36,c($img,$P['SUB']),$REG,'Rechercher un article…'); $cy+=80;
    // product grid 2 cols x 3 rows
    $prices=['24,00','6,50','48,00','12,00','40,00','18,00']; $names=['Cahier 96p','Stylo bleu','Roman','Gomme','Cartable','Feutres'];
    $gw=($w-24*2-20)/2; $gh=200; $i=0;
    for($rIdx=0;$rIdx<3;$rIdx++){ for($cIdx=0;$cIdx<2;$cIdx++){ $gx=$x+24+$cIdx*($gw+20); $gy=$cy+$rIdx*($gh+18);
        rr($img,(int)$gx,(int)$gy,(int)($gx+$gw),(int)($gy+$gh),18,c($img,$P['CARD']));
        rr($img,(int)($gx+18),(int)($gy+16),(int)($gx+$gw-18),(int)($gy+108),12,c($img,$P['GREY']));
        imagefilledrectangle($img,(int)($gx+18),(int)($gy+126),(int)($gx+$gw-70),(int)($gy+140),c($img,[210,215,230]));
        imagettftext($img,17,0,(int)($gx+18),(int)($gy+180),c($img,$P['BRAND']),$BOLD,$prices[$i].' DH'); $i++; }}
    // bottom cart bar
    $by=$y+$h-120; rr($img,$x+20,$by,$x+$w-20,$y+$h-24,22,c($img,$P['TXT']));
    imagettftext($img,16,0,$x+44,$by+46,c($img,[190,198,220]),$REG,'Panier · 3 articles'); imagettftext($img,24,0,$x+44,$by+82,c($img,$P['WHITE']),$BOLD,'78,50 DH');
    rr($img,$x+$w-220,$by+22,$x+$w-44,$by+74,16,c($img,$P['ACCENT'])); imagettftext($img,20,0,$x+$w-186,$by+56,c($img,$P['INK']),$BOLD,'PAYER');
}

function screenDash($img,$r,$P,$BOLD,$REG){
    [$x,$y,$w,$h]=$r;
    $cy=appHeader($img,$x,$y,$w,'Tableau de bord',$P,$BOLD,$REG);
    $kpis=[['CA du jour','4 250 DH',$P['BRAND']],['Ventes','37',$P['TXT']],['Ticket moyen','114 DH',$P['TXT']],['Marge','32%',[16,150,90]]];
    $gw=($w-24*2-20)/2; $gh=150;
    foreach($kpis as $k=>$kpi){ $gx=$x+24+($k%2)*($gw+20); $gy=$cy+intdiv($k,2)*($gh+18);
        rr($img,(int)$gx,(int)$gy,(int)($gx+$gw),(int)($gy+$gh),18,c($img,$P['CARD']));
        imagettftext($img,15,0,(int)($gx+22),(int)($gy+42),c($img,$P['SUB']),$REG,$kpi[0]);
        imagettftext($img,30,0,(int)($gx+22),(int)($gy+100),c($img,$kpi[2]),$BOLD,$kpi[1]); }
    // chart card
    $chy=$cy+2*($gh+18)+4; rr($img,$x+24,$chy,$x+$w-24,$y+$h-40,18,c($img,$P['CARD']));
    imagettftext($img,16,0,$x+46,$chy+44,c($img,$P['TXT']),$BOLD,'7 derniers jours');
    $bars=[60,110,80,140,100,170,130]; $bx=$x+56; $bw=48; $base=$y+$h-90;
    foreach($bars as $j=>$bv){ $col=$j===5?$P['ACCENT']:$P['BRAND']; rr($img,(int)$bx,(int)($base-$bv),(int)($bx+$bw),(int)$base,10,c($img,$col)); $bx+=$bw+22; }
}

function screenStock($img,$r,$P,$BOLD,$REG){
    [$x,$y,$w,$h]=$r;
    $cy=appHeader($img,$x,$y,$w,'Stock',$P,$BOLD,$REG);
    $rows=[['Cahier 96 pages','128','ok'],['Stylo bleu (x50)','12','low'],['Roman — Le Petit Prince','43','ok'],['Cartable scolaire','5','low'],['Feutres couleur','76','ok'],['Règle 30cm','210','ok'],['Sac à dos','9','low']];
    foreach($rows as $k=>$row){ $ry=$cy+$k*96; rr($img,$x+24,$ry,$x+$w-24,$ry+80,16,c($img,$P['CARD']));
        rr($img,$x+40,$ry+18,$x+40+44,$ry+62,10,c($img,$P['GREY']));
        imagettftext($img,18,0,$x+104,$ry+38,c($img,$P['TXT']),$BOLD,$row[0]);
        imagettftext($img,14,0,$x+104,$ry+64,c($img,$P['SUB']),$REG,'En stock : '.$row[1]);
        if($row[2]==='low'){ rr($img,$x+$w-150,$ry+22,$x+$w-40,$ry+58,18,c($img,[254,242,232])); imagettftext($img,14,0,$x+$w-138,$ry+45,c($img,[194,120,20]),$BOLD,'Stock bas'); }
        else { rr($img,$x+$w-120,$ry+22,$x+$w-40,$ry+58,18,c($img,[232,246,238])); imagettftext($img,15,0,$x+$w-104,$ry+45,c($img,[16,140,86]),$BOLD,$row[1]); } }
}

function screenReceipt($img,$r,$P,$BOLD,$REG){
    [$x,$y,$w,$h]=$r;
    $cy=appHeader($img,$x,$y,$w,'Ticket',$P,$BOLD,$REG);
    $tx=$x+70; $tw=$w-140; rr($img,$tx,$cy,$tx+$tw,$y+$h-70,20,c($img,$P['CARD']));
    mark($img,(int)($x+$w/2-34),(int)($cy+34),68,$BOLD,$P['BRAND'],$P['WHITE'],$P['ACCENT']);
    ctext($img,$x+$w/2,$cy+150,20,$BOLD,c($img,$P['TXT']),'Librairie Al Manara');
    ctext($img,$x+$w/2,$cy+184,14,$REG,c($img,$P['SUB']),'Ticket #10428 · 26/08/2026');
    $items=[['Cahier 96p','24,00'],['Stylo bleu','6,50'],['Roman','48,00']]; $iy=$cy+240;
    foreach($items as $it){ imagettftext($img,17,0,$tx+36,$iy,c($img,$P['TXT']),$REG,$it[0]); $bb=imagettfbbox(17,0,$BOLD,$it[1].' DH'); imagettftext($img,17,0,(int)($tx+$tw-36-($bb[2]-$bb[0])),$iy,c($img,$P['TXT']),$BOLD,$it[1].' DH'); $iy+=52; }
    imagefilledrectangle($img,$tx+36,$iy,$tx+$tw-36,$iy+2,c($img,$P['BORDER'])); $iy+=48;
    imagettftext($img,20,0,$tx+36,$iy,c($img,$P['TXT']),$BOLD,'Total'); $bb=imagettfbbox(22,0,$BOLD,'78,50 DH'); imagettftext($img,22,0,(int)($tx+$tw-36-($bb[2]-$bb[0])),$iy,c($img,$P['BRAND']),$BOLD,'78,50 DH');
    $iy+=60; rr($img,$tx+36,$iy,$tx+$tw-36,$iy+56,16,c($img,[232,246,238])); ctext($img,$x+$w/2,$iy+37,18,$BOLD,c($img,[16,140,86]),'✓ Payé');
}

// ── Build the 4 screenshots ─────────────────────────────────────────────────
$slides=[
    ['file'=>'screen-1-caisse.png','caption'=>'Encaissez en quelques secondes','fn'=>'screenPOS'],
    ['file'=>'screen-2-dashboard.png','caption'=>'Pilotez vos ventes en un coup d’œil','fn'=>'screenDash'],
    ['file'=>'screen-3-stock.png','caption'=>'Votre stock à jour en temps réel','fn'=>'screenStock'],
    ['file'=>'screen-4-ticket.png','caption'=>'Tickets & factures en un clic','fn'=>'screenReceipt'],
];

foreach($slides as $s){
    $W=1080; $H=1920; $img=imagecreatetruecolor($W,$H); imagesavealpha($img,true);
    vGrad($img,0,0,$W,$H,$INK,$INK2);
    imagefilledrectangle($img,0,0,$W,10,c($img,$BRAND));
    // caption
    $cyy=150; foreach(wrapW($s['caption'],52,$BOLD,$W-160) as $line){ ctext($img,$W/2,$cyy,52,$BOLD,c($img,$WHITE),$line); $cyy+=70; }
    // phone
    $r=phone($img,$W/2,$cyy+40,660,1160,$SCREEN);
    $s['fn']($img,$r,$PAL,$BOLD,$REG);
    // footer brand
    mark($img,(int)($W/2-120),$H-118,60,$BOLD,$BRAND,$WHITE,$ACCENT);
    imagettftext($img,30,0,(int)($W/2-44),$H-72,c($img,$WHITE),$BOLD,'Castl-it-');
    $bb=imagettfbbox(30,0,$BOLD,'Castl-it-'); imagettftext($img,30,0,(int)($W/2-44+($bb[2]-$bb[0])),$H-72,c($img,[139,166,255]),$BOLD,'POS');
    save($img,"$OUT/{$s['file']}");
}

// ── Feature graphic 1024×500 ────────────────────────────────────────────────
$W=1024;$H=500;$img=imagecreatetruecolor($W,$H);imagesavealpha($img,true);
vGrad($img,0,0,$W,$H,$INK,$INK2);
mark($img,72,180,140,$BOLD,$BRAND,$WHITE,$ACCENT);
imagettftext($img,58,0,244,238,c($img,$WHITE),$BOLD,'Castl-it-');
$bb=imagettfbbox(58,0,$BOLD,'Castl-it-'); imagettftext($img,58,0,(int)(244+($bb[2]-$bb[0])),238,c($img,[139,166,255]),$BOLD,'POS');
imagettftext($img,24,0,246,292,c($img,$MUTED),$REG,'Caisse & gestion de stock');
$chips=['Caisse tactile','Stock','Hors-ligne','FR · AR · EN']; $cx=246;
foreach($chips as $ch){ $bb=imagettfbbox(19,0,$REG,$ch); $cw=$bb[2]-$bb[0]; rr($img,$cx,340,$cx+$cw+36,384,22,c($img,[32,40,80])); imagettftext($img,19,0,$cx+18,369,c($img,$MUTED),$REG,$ch); $cx+=$cw+36+14; }
save($img,"$OUT/feature-graphic-1024x500.png");

echo "\nDone → marketing/exports/playstore/\n";
