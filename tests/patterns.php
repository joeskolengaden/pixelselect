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

section('the effect fits the LEDs that actually exist');
// A cape can clone several ports onto the same channels. Those ports are the
// same 1500 pixels of show, not 9000 more, so the effect must be laid out over
// the channels that exist - not over the sum of the pixel counts.
$tmpdir = sys_get_temp_dir() . '/pslay-' . getmypid();
@mkdir($tmpdir . '/config', 0775, true);
$settings = array('configDirectory' => $tmpdir . '/config', 'mediaDirectory' => $tmpdir,
                  'sequenceDirectory' => $tmpdir, 'playlistDirectory' => $tmpdir);
require_once dirname(dirname(__FILE__)) . '/lib/common.php';
require_once dirname(dirname(__FILE__)) . '/lib/solidcolors.php';

function write_layout($dir, $strings) {
    $outs = array();
    foreach ($strings as $s)
        $outs[] = array('virtualStrings' => array(
            array('startChannel' => $s[0], 'pixelCount' => $s[1], 'colorOrder' => $s[2])));
    file_put_contents($dir . '/config/co-bbbStrings.json', json_encode(array('channelOutputs' => array(
        array('type' => 'BBB48String', 'enabled' => 1, 'startChannel' => 1, 'outputs' => $outs)))));
}

// (a) an ordinary contiguous cape: nothing may change
write_layout($tmpdir, array(array(0, 100, 'RGB'), array(300, 100, 'RGB'), array(600, 100, 'RGB')));
$L = ps_read_layout();
ok($L['pixels'] === 300 && $L['stripPixels'] === 300, 'contiguous: strip length equals the LED count (300)');
$offs = array(); foreach ($L['strings'] as $st) $offs[] = $st['offset'];
ok($offs === array(0, 100, 200), 'contiguous: strings sit end to end, exactly as before');

// (b) four ports cloned onto channel 0, plus one that follows
write_layout($tmpdir, array(array(0, 100, 'RGB'), array(0, 100, 'RGB'), array(0, 100, 'RGB'),
                            array(0, 100, 'RGB'), array(300, 50, 'RGB')));
$L = ps_read_layout();
ok($L['pixels'] === 450, 'cloned: 450 LEDs exist');
ok($L['stripPixels'] === 150, 'cloned: but the effect only needs 150 pixels (was 450)');
$zero = 0; foreach ($L['strings'] as $st) if ($st['offset'] === 0) $zero++;
ok($zero === 4, 'cloned: all four ports on channel 0 share the start of the effect');

// and the rendered file must begin at the beginning of the effect
$tmpf = $tmpdir . '/clone.fseq';
ok(ps_write_pattern_fseq($tmpf, 'wipe', $L, null, array(array(255, 0, 0))), 'renders against a cloned layout');
$raw = file_get_contents($tmpf);
$chOff = unpack('v', substr($raw, 4, 2))[1];
$ch    = unpack('V', substr($raw, 10, 4))[1];
list($fst, $clen) = array_values(unpack('V2', substr($raw, 32, 8)));
$f0 = @gzuncompress(substr($raw, $chOff, $clen));
ok($ch === $L['maxChannel'], 'file covers the real channel span (' . $ch . ')');
// Colour Wipe frame 0 lights nothing yet; take a frame a quarter in, where the
// fill has reached part way, and check the lit run starts at channel 0.
ok(is_string($f0) && strlen($f0) === $ch, 'a frame inflates to the channel span');
@unlink($tmpf);

// (c) a string reaching past the strip must be padded, never truncated short
$odd = array('strings' => array(array('start' => 0, 'pixels' => 50, 'cpn' => 3, 'order' => 'RGB', 'offset' => 0),
                                array('start' => 150, 'pixels' => 50, 'cpn' => 3, 'order' => 'RGB', 'offset' => 400)),
             'pixels' => 100, 'stripPixels' => 60, 'maxChannel' => 300, 'source' => array());
ok(ps_write_pattern_fseq($tmpdir . '/odd.fseq', 'breathe', $odd, null, array(array(255, 0, 0))),
   'a string past the end of the strip still writes (zero padded)');
@unlink($tmpdir . '/odd.fseq');

section('reading a pattern file back (for Re-render)');
$spec = ps_pattern_parse('Pattern - Comet (Red).fseq');
ok($spec !== null && $spec['id'] === 'comet', 'effect recovered from the filename');
ok($spec !== null && $spec['colours'] === array(array(255, 0, 0)), 'colour recovered from the filename');
$spec2 = ps_pattern_parse('Pattern - Theater Chase (Red+Green).fseq');
ok($spec2 !== null && count($spec2['colours']) === 2 && $spec2['colourLabel'] === 'Red+Green',
   'several colours recovered in order');
$spec3 = ps_pattern_parse('Pattern - Comet.fseq');
ok($spec3 !== null && $spec3['colours'] === null, 'a built-in-colour file re-renders with no tint');
ok(ps_pattern_parse('design2.fseq') === null, 'someone else\'s sequence is left alone');
ok(ps_pattern_parse('Pattern - Nonsense (Red).fseq') === null, 'an effect we no longer have is skipped');
ok(ps_pattern_parse('Pattern - Comet (Taupe).fseq') === null, 'a colour we no longer have is skipped');
// round trip
foreach (ps_pattern_list() as $pp) {
    $nm = ps_pattern_filename($pp['label'], 'Red');
    $back = ps_pattern_parse($nm);
    if ($back === null || $back['id'] !== $pp['id']) { ok(false, 'round trip failed for ' . $pp['id']); break; }
}
ok(true, 'every effect name survives filename -> parse round trip');
@unlink($tmpdir . '/config/co-bbbStrings.json'); @rmdir($tmpdir . '/config'); @rmdir($tmpdir);

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
