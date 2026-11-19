<?php
/*
 * Off-device test for the pattern generator's colour control.
 *
 * The point of the feature is "render this effect in MY colour", so the test
 * that matters is a channel audit: with red chosen, no pixel in any frame of
 * any effect may carry green or blue. That catches a hard-coded colour left
 * behind in one branch, which reading the code easily misses.
 *
 *   php tests/patterns.php
 */

$GLOBALS['ps_fail'] = 0; $GLOBALS['ps_pass'] = 0;
function ok($cond, $what) {
    if ($cond) { $GLOBALS['ps_pass']++; echo "  PASS  $what\n"; }
    else       { $GLOBALS['ps_fail']++; echo "  FAIL  $what\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

require_once dirname(dirname(__FILE__)) . '/lib/patterns.php';

define('T_N', 300);     // pixels
define('T_F', 40);      // frames - enough to walk every branch, quick enough to run

/* Count pixels that light at all, and pixels that break the colour rule. */
function audit($id, $cols, $rule) {
    $frames = ps_pattern_strip_frames($id, T_N, T_F, $cols);
    if (!is_array($frames)) return array(-1, -1);
    $lit = 0; $bad = 0;
    foreach ($frames as $f) {
        for ($i = 0; $i < T_N; $i++) {
            $r = ord($f[$i * 3]); $g = ord($f[$i * 3 + 1]); $b = ord($f[$i * 3 + 2]);
            if ($r || $g || $b) $lit++;
            if (!$rule($r, $g, $b)) $bad++;
        }
    }
    return array($lit, $bad);
}

section('colour helpers');
ok(ps_blend(array(array(10, 20, 30)), 0.37) === array(10, 20, 30),
   'one colour is returned untouched by ps_blend, at any position');
$mid = ps_blend(array(array(0, 0, 0), array(100, 200, 40)), 0.25);
ok($mid === array(50, 100, 20), 'two colours interpolate linearly (got ' . implode(',', $mid) . ')');
$g1 = ps_grad(array(array(255, 0, 0)), 0.0);
$g2 = ps_grad(array(array(255, 0, 0)), 0.5);
ok($g1[1] === 0 && $g1[2] === 0 && $g2[1] === 0 && $g2[2] === 0,
   'a lone colour stays on its own hue through ps_grad');
ok($g2[0] > $g1[0], 'ps_grad really ramps brightness (' . $g1[0] . ' -> ' . $g2[0] . ')');

section('every effect renders in ONE chosen colour only');
$cases = array(
    'Red'   => array(array(array(255, 0, 0)),  function ($r, $g, $b) { return $g === 0 && $b === 0; }),
    'Blue'  => array(array(array(0, 0, 255)),  function ($r, $g, $b) { return $r === 0 && $g === 0; }),
    'Green' => array(array(array(0, 255, 0)),  function ($r, $g, $b) { return $r === 0 && $b === 0; }),
);
foreach ($cases as $name => $c) {
    $worst = 0; $dark = array();
    foreach (ps_pattern_list() as $p) {
        list($lit, $bad) = audit($p['id'], $c[0], $c[1]);
        $worst += max(0, $bad);
        if ($lit <= 0) $dark[] = $p['id'];
    }
    ok($worst === 0, $name . ': no foreign colour in any of the ' . count(ps_pattern_list()) . ' effects');
    ok(!count($dark), $name . ': every effect actually lights pixels' . (count($dark) ? ' (dark: ' . implode(',', $dark) . ')' : ''));
}

section('two colours stay inside the two chosen');
$cols = array(array(255, 0, 0), array(0, 255, 0));
$bad = 0;
foreach (ps_pattern_list() as $p) {
    list($lit, $b) = audit($p['id'], $cols, function ($r, $g, $bl) { return $bl === 0; });
    $bad += max(0, $b);
}
ok($bad === 0, 'Red+Green never emits blue');

section('the built-in look is untouched when no colour is chosen');
// Each of these effects was built around a colour that is NOT a pure primary,
// so if the tinting path ever leaked into the default path these would change.
list($lit, $bad) = audit('wipe', null, function ($r, $g, $b) { return !($r || $g || $b) || ($r === 0 && $g === 120 && $b === 255); });
ok($bad === 0 && $lit > 0, 'Colour Wipe still fills in its original 0,120,255');
list($lit, $bad) = audit('chase', null, function ($r, $g, $b) { return !($r || $g || $b) || ($r === 255 && $g === 255 && $b === 255); });
ok($bad === 0 && $lit > 0, 'Theater Chase is still white');
$rb = ps_pattern_strip_frames('rainbow', 3, T_F, null);
$hues = array();
foreach ($rb as $f) $hues[substr($f, 0, 3)] = true;
ok(count($hues) > 5, 'Rainbow still cycles through many hues (' . count($hues) . ')');

section('30 second files at the original speed');
ok(PS_PAT_FRAMES * PS_PAT_STEP_MS === 30000,
   'a pattern is exactly 30 s (' . PS_PAT_FRAMES . ' x ' . PS_PAT_STEP_MS . 'ms)');
ok(PS_PAT_FRAMES % PS_PAT_CYCLES === 0,
   'the frame count divides evenly into ' . PS_PAT_CYCLES . ' laps');

// The whole point of PS_PAT_CYCLES: three times longer must NOT mean three times
// slower. One lap of the long file has to equal a single-lap render exactly.
$lap = PS_PAT_FRAMES / PS_PAT_CYCLES;
$slow = 0;
foreach (ps_pattern_list() as $p) {
    if ($p['id'] === 'chase') continue;                 // advances 1px/frame, so it never loops
    $long = ps_pattern_strip_frames($p['id'], 240, PS_PAT_FRAMES, null, PS_PAT_CYCLES);
    $one  = ps_pattern_strip_frames($p['id'], 240, $lap, null, 1);
    if (implode('', array_slice($long, 0, $lap)) !== implode('', $one)) $slow++;
}
ok($slow === 0, 'lap one of the 30 s file is identical to a 10 s render - same speed, not slower');

$chase = ps_pattern_strip_frames('chase', 240, PS_PAT_FRAMES, null, PS_PAT_CYCLES);
ok(count($chase) === PS_PAT_FRAMES, 'a full render returns all ' . PS_PAT_FRAMES . ' frames');

// 600 blocks does not fit in the one-byte field, so the writer has to use the
// 12-bit form (high nibble of byte 20). Worth asserting: patterns never needed it
// before this change.
$layout = array('strings' => array(array('start' => 0, 'pixels' => 100, 'cpn' => 3, 'order' => 'RGB')),
                'pixels' => 100, 'maxChannel' => 300, 'source' => array());
$tmp = sys_get_temp_dir() . '/pspat-' . getmypid() . '.fseq';
ok(ps_write_pattern_fseq($tmp, 'comet', $layout, null, array(array(255, 0, 0))), 'writes a 30 s pattern file');
$raw = file_get_contents($tmp);
$chOff  = unpack('v', substr($raw, 4, 2))[1];
$frames = unpack('V', substr($raw, 14, 4))[1];
$step   = ord($raw[18]);
$blocks = ((ord($raw[20]) >> 4) & 0x0F) * 256 + ord($raw[21]);
ok($frames === PS_PAT_FRAMES, 'header says ' . $frames . ' frames');
ok($frames * $step === 30000, 'header works out to 30 s');
ok($blocks === PS_PAT_FRAMES, 'all ' . $blocks . ' blocks survive the 12-bit block count (>255)');
ok($chOff === 32 + $blocks * 8, 'data offset accounts for the longer block index');
@unlink($tmp);

section('filenames');
ok(ps_pattern_filename('Comet') === 'Pattern - Comet.fseq', 'no colour: the original name');
ok(ps_pattern_filename('Comet', 'Red') === 'Pattern - Comet (Red).fseq', 'one colour is named in brackets');
ok(ps_pattern_filename('Comet', 'Red+Green') === 'Pattern - Comet (Red+Green).fseq', 'several colours are joined with +');
ok(ps_is_pattern(ps_pattern_filename('Comet', 'Red')), 'a colour variant is still recognised as a pattern');

echo "\n";
if ($GLOBALS['ps_fail'] === 0) { echo "ALL PASS (" . $GLOBALS['ps_pass'] . " checks)\n"; exit(0); }
echo $GLOBALS['ps_fail'] . " FAILURE(S) of " . ($GLOBALS['ps_pass'] + $GLOBALS['ps_fail']) . "\n";
exit(1);
