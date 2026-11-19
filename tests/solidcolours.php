<?php
/*
 * Off-device test for the built-in solid-colour generator.
 *
 * Needs no FPP checkout and no device - it calls the real generator, then parses
 * the .fseq back byte for byte, which is the only way to be sure a customer's
 * last pixel actually lights.
 *
 * The sizing target is the full WinterLights 48-port build at 1500 pixels per
 * port, so this file is what stops anyone shrinking PS_SOLID_CHANNELS below it.
 *
 *   php tests/solidcolours.php
 */

define('WL_PORTS', 48);
define('WL_PX_PER_PORT', 1500);
define('WL_NEED_RGB',  WL_PORTS * WL_PX_PER_PORT * 3);   // 216,000
define('WL_NEED_RGBW', WL_PORTS * WL_PX_PER_PORT * 4);   // 288,000

$GLOBALS['ps_fail'] = 0;
$GLOBALS['ps_pass'] = 0;
function ok($cond, $what) {
    if ($cond) { $GLOBALS['ps_pass']++; echo "  PASS  $what\n"; }
    else       { $GLOBALS['ps_fail']++; echo "  FAIL  $what\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

$tmp = sys_get_temp_dir() . '/pssolid-' . getmypid();
@mkdir($tmp, 0775, true);
$settings = array('configDirectory' => $tmp, 'mediaDirectory' => $tmp,
                  'sequenceDirectory' => $tmp, 'playlistDirectory' => $tmp);
$here = dirname(__FILE__);
require_once dirname($here) . '/lib/common.php';
require_once dirname($here) . '/lib/solidcolors.php';

/* Parse a V2 FSEQ far enough to read one frame back. */
function fseq_read($path) {
    $f = file_get_contents($path);
    $h = array();
    $h['magic']    = substr($f, 0, 4);
    $h['chOffset'] = unpack('v', substr($f, 4, 2))[1];
    $h['minor']    = ord($f[6]);
    $h['major']    = ord($f[7]);
    $h['channels'] = unpack('V', substr($f, 10, 4))[1];
    $h['frames']   = unpack('V', substr($f, 14, 4))[1];
    $h['stepMs']   = ord($f[18]);
    $h['comp']     = ord($f[20]) & 0x0F;
    $h['blocks']   = ord($f[21]);
    $h['id']       = substr($f, 24, 8);
    $h['size']     = strlen($f);
    list($first, $clen) = array_values(unpack('V2', substr($f, 32, 8)));
    $h['frame0'] = @gzuncompress(substr($f, $h['chOffset'], $clen));
    return $h;
}

section('palette');
$pal = ps_palette();
ok(count($pal) >= 24, 'at least 24 colours (' . count($pal) . ')');
$ids = array(); $labels = array(); $files = array(); $bad = 0;
foreach ($pal as $p) {
    $ids[] = $p['id']; $labels[] = $p['label']; $files[] = ps_solid_filename($p['label']);
    if (count($p['rgb']) !== 3) { $bad++; continue; }
    foreach ($p['rgb'] as $v) if (!is_int($v) || $v < 0 || $v > 255) $bad++;
}
ok($bad === 0, 'every colour is three ints in 0..255');
ok(count(array_unique($ids)) === count($ids), 'ids are unique');
ok(count(array_unique($labels)) === count($labels), 'labels are unique');
ok(count(array_unique($files)) === count($files), 'filenames are unique');

section('sizing for the 48-port build at 1500 px/port');
printf("  target: %d ports x %d px x 3 = %d channels\n", WL_PORTS, WL_PX_PER_PORT, WL_NEED_RGB);
printf("  shipped PS_SOLID_CHANNELS   = %d (%d px, %.0f px/port)\n",
       PS_SOLID_CHANNELS, intdiv(PS_SOLID_CHANNELS, 3), intdiv(PS_SOLID_CHANNELS, 3) / WL_PORTS);
ok(PS_SOLID_CHANNELS >= WL_NEED_RGB,  'covers all 48 ports at 1500 px (RGB)');
ok(PS_SOLID_CHANNELS >= WL_NEED_RGBW, 'and the same pixel count as RGBW channel-wise');
ok(PS_SOLID_CHANNELS % 3 === 0, 'channel count is a whole number of RGB nodes');

section('one generated colour, read back');
$red = null;
foreach ($pal as $p) if ($p['id'] === 'red') $red = $p;
ok($red !== null, 'palette still has "red" to test with');
$path = $tmp . '/' . ps_solid_filename($red['label']);
ps_write_solid_fseq($path, $red['rgb'], PS_SOLID_CHANNELS, PS_SOLID_FRAMES, PS_SOLID_STEP_MS, 1.0);
$h = fseq_read($path);
ok($h['magic'] === 'PSEQ', 'magic is PSEQ');
ok($h['major'] === 2, 'version 2');
ok($h['comp'] === 2, 'zlib compression (what FPP 5.4+ inflates natively)');
ok($h['channels'] === PS_SOLID_CHANNELS, 'header channel count matches the constant');
ok($h['blocks'] === PS_SOLID_FRAMES, 'one compression block per frame (caps decode RAM)');
ok($h['chOffset'] === 32 + $h['blocks'] * 8, 'data offset = 32 + blocks*8');
ok($h['frames'] * $h['stepMs'] === 10000, 'exactly 10 seconds (' . $h['frames'] . ' x ' . $h['stepMs'] . 'ms)');
ok(is_string($h['frame0']) && strlen($h['frame0']) === PS_SOLID_CHANNELS,
   'a frame inflates to the full channel count');

section('every pixel on every one of the 48 ports is lit');
$frame = $h['frame0'];
$want = chr($red['rgb'][0]) . chr($red['rgb'][1]) . chr($red['rgb'][2]);
$wrong = 0; $checked = 0;
for ($px = 0; $px < WL_PORTS * WL_PX_PER_PORT; $px++) {
    if (substr($frame, $px * 3, 3) !== $want) $wrong++;
    $checked++;
}
ok($wrong === 0, "all $checked pixels across 48 ports carry the colour ($wrong wrong)");
for ($port = 1; $port <= WL_PORTS; $port += 47) {
    $last = ($port * WL_PX_PER_PORT - 1) * 3;
    ok(substr($frame, $last, 3) === $want,
       "port $port final pixel (ch " . ($last + 1) . ') is the colour');
}
ok(strlen($frame) > WL_NEED_RGB, 'and there is headroom past port 48');

section('unique ids - all twelve sharing one id was a real on-device bug');
$seen = array(); $dupes = 0;
foreach ($pal as $p) {
    $f = $tmp . '/' . ps_solid_filename($p['label']);
    ps_write_solid_fseq($f, $p['rgb'], 3072, 4, 250, 1.0);
    $id = fseq_read($f)['id'];
    if (isset($seen[$id])) $dupes++;
    $seen[$id] = true;
}
ok($dupes === 0, count($pal) . ' colours produced ' . count($seen) . ' distinct ids');

section('size on an SD card');
$full = 0;
foreach ($pal as $p) {
    $f = $tmp . '/full-' . $p['id'] . '.fseq';
    ps_write_solid_fseq($f, $p['rgb'], PS_SOLID_CHANNELS, PS_SOLID_FRAMES, PS_SOLID_STEP_MS, 1.0);
    $full += filesize($f);
    @unlink($f);
}
printf("  whole palette at full size: %.0f KB (%.1f KB per colour)\n", $full / 1024, $full / 1024 / count($pal));
ok($full < 4 * 1024 * 1024, 'the whole palette stays under 4 MB');

foreach (glob($tmp . '/*') as $g) @unlink($g);
@rmdir($tmp);
echo "\n";
if ($GLOBALS['ps_fail'] === 0) { echo "ALL PASS (" . $GLOBALS['ps_pass'] . " checks)\n"; exit(0); }
echo $GLOBALS['ps_fail'] . " FAILURE(S) of " . ($GLOBALS['ps_pass'] + $GLOBALS['ps_fail']) . "\n";
exit(1);
