<?php
/*
 * WLED-style animated patterns, rendered to .fseq.
 *
 * Unlike a solid colour, a pattern has to know where the pixels are: "chase down
 * the string" is meaningless without a layout. So these are rendered against the
 * controller's real output config (see layout.php) and must be regenerated if
 * the pixel configuration changes.
 *
 * Performance matters here - this runs in PHP on a 1 GHz BeagleBone, and a naive
 * per-pixel-per-frame loop would be 3M+ operations. So every effect is built as
 * a one-dimensional strip and animated with whole-string operations (rotation
 * via substr, fills via str_repeat, sparkles via substr_replace), and gamma is
 * applied with a 256-byte strtr() map rather than per byte.
 */
require_once(dirname(__FILE__) . '/layout.php');
require_once(dirname(__FILE__) . '/fseq.php');

define('PS_PAT_PREFIX', 'Pattern - ');
define('PS_PAT_FRAMES', 200);     // 200 x 50ms = exactly 10 seconds
define('PS_PAT_STEP_MS', 50);     // 20fps, smooth enough for chases and fades
define('PS_PAT_GAMMA', 2.2);

function ps_pattern_list() {
    return array(
        array('id' => 'rainbow',      'label' => 'Rainbow'),
        array('id' => 'rainbowcycle', 'label' => 'Rainbow Cycle'),
        array('id' => 'breathe',      'label' => 'Breathe'),
        array('id' => 'wipe',         'label' => 'Colour Wipe'),
        array('id' => 'chase',        'label' => 'Theater Chase'),
        array('id' => 'running',      'label' => 'Running Lights'),
        array('id' => 'comet',        'label' => 'Comet'),
        array('id' => 'scan',         'label' => 'Larson Scanner'),
        array('id' => 'twinkle',      'label' => 'Twinkle'),
        array('id' => 'fire',         'label' => 'Fire Flicker'),
    );
}

/* 'Pattern - Comet.fseq', or 'Pattern - Comet (Red).fseq' when colours were chosen. */
function ps_pattern_filename($label, $colourLabel = '') {
    $suffix = ($colourLabel !== '') ? ' (' . $colourLabel . ')' : '';
    return PS_PAT_PREFIX . $label . $suffix . '.fseq';
}
function ps_is_pattern($name) { return strpos($name, PS_PAT_PREFIX) === 0; }

/* hue 0..1 -> rgb bytes, full saturation and value */
function ps_hsv($h) {
    $h = $h - floor($h);
    $i = (int)floor($h * 6); $f = $h * 6 - $i;
    $q = (int)round(255 * (1 - $f)); $t = (int)round(255 * $f);
    switch ($i % 6) {
        case 0: return array(255, $t, 0);
        case 1: return array($q, 255, 0);
        case 2: return array(0, 255, $t);
        case 3: return array(0, $q, 255);
        case 4: return array($t, 0, 255);
        default: return array(255, 0, $q);
    }
}
function ps_px($rgb, $scale = 1.0) {
    return chr((int)round($rgb[0] * $scale)) . chr((int)round($rgb[1] * $scale)) . chr((int)round($rgb[2] * $scale));
}

/*
 * Colour control.
 *
 * Every effect can be rendered in chosen colours instead of its built-in look.
 * $colours is a list of RGB triples; null keeps the original WLED-ish palette,
 * byte for byte, so designs already on a controller are unaffected.
 *
 *   one colour   -> the effect uses that colour ONLY. Effects whose whole point
 *                   is a ramp (the rainbows) become a brightness gradient of it,
 *                   which is the 1-colour + ramp look rather than a flat wash.
 *   two or more  -> the effect cycles or gradients through them, in order.
 */

/* Cyclic interpolation across the chosen colours. A single colour is returned
   unchanged - "in this colour only" means exactly that. */
function ps_blend($colours, $t) {
    $k = count($colours);
    if ($k === 1) return $colours[0];
    $t = $t - floor($t);
    $x = $t * $k;
    $i = ((int)floor($x)) % $k;
    $f = $x - floor($x);
    $a = $colours[$i];
    $b = $colours[($i + 1) % $k];
    return array((int)round($a[0] + ($b[0] - $a[0]) * $f),
                 (int)round($a[1] + ($b[1] - $a[1]) * $f),
                 (int)round($a[2] + ($b[2] - $a[2]) * $f));
}

/* Like ps_blend, but a lone colour becomes a brightness ramp so a gradient
   effect still reads as a gradient instead of one flat colour. */
function ps_grad($colours, $t) {
    if (count($colours) > 1) return ps_blend($colours, $t);
    $s = 0.08 + 0.92 * (0.5 - 0.5 * cos(2 * M_PI * ($t - floor($t))));
    $c = $colours[0];
    return array((int)round($c[0] * $s), (int)round($c[1] * $s), (int)round($c[2] * $s));
}

function ps_pick($colours, $i) {
    $k = count($colours);
    return $colours[(($i % $k) + $k) % $k];
}

function ps_scale_rgb($c, $s) {
    return array((int)round($c[0] * $s), (int)round($c[1] * $s), (int)round($c[2] * $s));
}

/*
 * Build every frame of an effect as a flat strip of $n RGB pixels.
 * Returns an array of PS_PAT_FRAMES binary strings, each $n*3 bytes.
 *
 * $colours: null for the built-in look, or a list of RGB triples to use instead.
 */
function ps_pattern_strip_frames($id, $n, $frames = PS_PAT_FRAMES, $colours = null) {
    $out = array();
    $black = str_repeat("\0", $n * 3);
    $col = is_array($colours) && count($colours) ? array_values($colours) : null;
    $tinted = ($col !== null);

    // A base strip plus a doubled copy lets a whole frame be one substr().
    $rot = function ($base, $off) use ($n) {
        $off = (($off % $n) + $n) % $n;
        return substr($base . $base, $off * 3, $n * 3);
    };

    switch ($id) {
    case 'rainbow':                       // whole strip one hue, cycling
        for ($f = 0; $f < $frames; $f++) {
            $c = $tinted ? ps_grad($col, $f / $frames) : ps_hsv($f / $frames);
            $out[] = str_repeat(ps_px($c), $n);
        }
        return $out;

    case 'rainbowcycle':                  // hue spread along the strip, scrolling
        $base = '';
        for ($i = 0; $i < $n; $i++) {
            $t = $i / max(1, $n);
            $base .= ps_px($tinted ? ps_grad($col, $t) : ps_hsv($t));
        }
        for ($f = 0; $f < $frames; $f++) $out[] = $rot($base, (int)round($f * $n / $frames));
        return $out;

    case 'breathe':                       // one colour, sine fade
        for ($f = 0; $f < $frames; $f++) {
            $s = 0.08 + 0.92 * (0.5 - 0.5 * cos(2 * M_PI * $f / $frames));
            $c = $tinted ? ps_blend($col, $f / $frames) : array(255, 150, 40);
            $out[] = str_repeat(ps_px($c, $s), $n);
        }
        return $out;

    case 'wipe':                          // fills, then clears, then repeats
        $fill = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $tinted ? ps_blend($col, count($col) > 1 ? $i / max(1, $n) : 0)
                         : array(0, 120, 255);
            $fill .= ps_px($c);
        }
        for ($f = 0; $f < $frames; $f++) {
            $p = ($f * 2.0 / $frames);
            if ($p < 1.0) { $k = (int)round($p * $n);          $out[] = substr($fill, 0, $k * 3) . substr($black, 0, ($n - $k) * 3); }
            else          { $k = (int)round(($p - 1.0) * $n);  $out[] = substr($black, 0, $k * 3) . substr($fill, $k * 3); }
        }
        return $out;

    case 'chase':                         // theater chase: every third pixel
        $base = '';
        for ($i = 0; $i < $n; $i++) {
            if ($i % 3 !== 0) { $base .= "\0\0\0"; continue; }
            $base .= ps_px($tinted ? ps_pick($col, (int)($i / 3)) : array(255, 255, 255));
        }
        for ($f = 0; $f < $frames; $f++) $out[] = $rot($base, $f);
        return $out;

    case 'running':                       // sine wave travelling along the strip
        $base = '';
        for ($i = 0; $i < $n; $i++) {
            $s = 0.5 - 0.5 * cos(2 * M_PI * ($i / max(1, $n)) * 8);
            $c = $tinted ? ps_blend($col, $i / max(1, $n)) : array(255, 40, 120);
            $base .= ps_px($c, $s);
        }
        for ($f = 0; $f < $frames; $f++) $out[] = $rot($base, (int)round($f * $n / $frames));
        return $out;

    case 'comet':                         // bright head with a fading tail
        $tail = max(6, (int)($n / 12));
        $base = '';
        for ($i = 0; $i < $n; $i++) {
            $d = $i < $tail ? (1.0 - $i / $tail) : 0.0;
            $c = $tinted ? ps_blend($col, count($col) > 1 ? $i / $tail : 0)
                         : array(120, 255, 200);
            $base .= ps_px($c, $d * $d);
        }
        for ($f = 0; $f < $frames; $f++) $out[] = $rot($base, -(int)round($f * $n / $frames));
        return $out;

    case 'scan':                          // Larson scanner, bounces end to end
        $w = max(3, (int)($n / 24));
        $blob = '';
        for ($i = 0; $i < $w; $i++) {
            $s = 0.5 - 0.5 * cos(2 * M_PI * ($i + 0.5) / $w);
            $c = $tinted ? ps_blend($col, count($col) > 1 ? $i / $w : 0) : array(255, 0, 0);
            $blob .= ps_px($c, $s);
        }
        for ($f = 0; $f < $frames; $f++) {
            $t = $f / $frames * 2.0;
            $pos = $t < 1.0 ? $t : (2.0 - $t);
            $k = (int)round($pos * max(0, $n - $w));
            $out[] = substr($black, 0, $k * 3) . $blob . substr($black, 0, ($n - $w - $k) * 3);
        }
        return $out;

    case 'twinkle':                       // sparkles over a dim base
        $bg = str_repeat(ps_px($tinted ? ps_scale_rgb($col[0], 0.15) : array(0, 0, 40)), $n);
        $count = max(4, (int)($n / 40));
        mt_srand(12345);                  // deterministic, so regenerating is stable
        for ($f = 0; $f < $frames; $f++) {
            $fr = $bg;
            for ($k = 0; $k < $count; $k++) {
                $p = mt_rand(0, $n - 1);
                if ($tinted) $px = ps_px(ps_pick($col, mt_rand(0, 9999)), mt_rand(40, 100) / 100);
                else         $px = ps_px(array(255, 255, 220), mt_rand(40, 100) / 100);
                $fr = substr_replace($fr, $px, $p * 3, 3);
            }
            $out[] = $fr;
        }
        return $out;

    case 'fire':                          // warm flicker
        mt_srand(999);
        $count = max(6, (int)($n / 12));
        $bg = str_repeat(ps_px($tinted ? $col[0] : array(150, 40, 0), 0.45), $n);
        for ($f = 0; $f < $frames; $f++) {
            $fr = $bg;
            for ($k = 0; $k < $count; $k++) {
                $p = mt_rand(0, $n - 1);
                if ($tinted) $px = ps_px(ps_pick($col, mt_rand(0, 9999)), mt_rand(50, 100) / 100);
                else         $px = ps_px(array(255, mt_rand(60, 150), 0), mt_rand(50, 100) / 100);
                $fr = substr_replace($fr, $px, $p * 3, 3);
            }
            $out[] = $fr;
        }
        return $out;
    }
    return null;
}

/* 256-byte translation table so gamma costs one strtr() per frame, not per byte. */
function ps_gamma_map($g) {
    $from = ''; $to = '';
    for ($i = 0; $i < 256; $i++) {
        $from .= chr($i);
        $to   .= chr($g > 1.0 ? (int)round(255.0 * pow($i / 255.0, $g)) : $i);
    }
    return array($from, $to);
}

/*
 * Render one pattern across the real layout and write it out.
 * Each string takes the next slice of the virtual strip, placed at its own
 * start channel; RGBW strings get a zero white channel per pixel.
 */
function ps_write_pattern_fseq($path, $id, $layout, $gamma = null, $colours = null) {
    if (!count($layout['strings'])) return false;
    $n = $layout['pixels'];
    $strips = ps_pattern_strip_frames($id, $n, PS_PAT_FRAMES, $colours);
    if (!$strips) return false;

    $useGamma = ($gamma !== null && $gamma > 1.0);
    if ($useGamma) list($gFrom, $gTo) = ps_gamma_map($gamma);

    $chans = $layout['maxChannel'];
    $blank = str_repeat("\0", $chans);
    $blobs = array();
    foreach ($strips as $strip) {
        if ($useGamma) $strip = strtr($strip, $gFrom, $gTo);
        $frame = $blank;
        $take = 0;
        foreach ($layout['strings'] as $s) {
            $slice = substr($strip, $take * 3, $s['pixels'] * 3);
            $take += $s['pixels'];
            if ($s['cpn'] === 4) {                       // insert a zero white channel
                $wide = '';
                for ($p = 0; $p < $s['pixels']; $p++) $wide .= substr($slice, $p * 3, 3) . "\0";
                $slice = $wide;
            } elseif ($s['cpn'] === 1) {                 // single-channel string: use green
                $one = '';
                for ($p = 0; $p < $s['pixels']; $p++) $one .= $slice[$p * 3 + 1];
                $slice = $one;
            }
            $frame = substr_replace($frame, $slice, $s['start'], strlen($slice));
        }
        $blobs[] = $frame;
    }
    return ps_fseq_write($path, $blobs, $chans, PS_PAT_STEP_MS, crc32($id));
}
