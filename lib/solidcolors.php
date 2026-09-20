<?php
/*
 * Built-in solid-colour sequences.
 *
 * Writes one .fseq per colour into media/sequences so a customer can pick a
 * plain colour from the design list without ever opening xLights.
 *
 * Why these files are tiny and channel-count-proof
 * ------------------------------------------------
 * A solid colour is the same bytes in every frame, which zlib crushes to almost
 * nothing. FPP 5.4+ reads zlib-compressed FSEQ v2 (compression type 2), so
 * rather than trying to guess each customer's channel count - FPP has no single
 * API that reports it, and guessing low would leave pixels dark - we simply
 * cover a generously large range. Channels past the end of a show are ignored
 * by FPP; channels short of it are not, so erring large is the safe direction.
 *
 * One frame per compression block keeps the decompression buffer to a single
 * frame, so RAM and CPU stay trivial on a BeagleBone no matter how large the
 * covered range is.
 *
 * Format reference: fpp/docs/FSEQ_Sequence_File_Format.txt and
 * fpp/src/fseq/FSEQFile.cpp (V2FSEQFile header parse).
 */

// Covers 174,762 RGB pixels. Well past any BBB/Pi build, and only ~512 KB per
// frame before compression.
define('PS_SOLID_CHANNELS', 524286);        // a multiple of 3
define('PS_SOLID_STEP_MS',  250);           // byte-wide field in FSEQ, max 255
define('PS_SOLID_FRAMES',   40);            // 40 x 250ms = exactly 10 seconds
define('PS_SOLID_PREFIX',   'Colour - ');
// Perceptual encoding applied to the file ONLY when FPP is not already doing it
// at the output. sRGB-ish; WLED uses 2.8, which is heavier handed.
define('PS_SOLID_GAMMA',    2.2);

function ps_palette() {
    return array(
        array('id' => 'red',        'label' => 'Red',        'rgb' => array(255,   0,   0)),
        array('id' => 'orange',     'label' => 'Orange',     'rgb' => array(255,  70,   0)),
        array('id' => 'amber',      'label' => 'Amber',      'rgb' => array(255, 130,   0)),
        array('id' => 'yellow',     'label' => 'Yellow',     'rgb' => array(255, 220,   0)),
        array('id' => 'green',      'label' => 'Green',      'rgb' => array(  0, 255,   0)),
        array('id' => 'teal',       'label' => 'Teal',       'rgb' => array(  0, 200, 120)),
        array('id' => 'cyan',       'label' => 'Cyan',       'rgb' => array(  0, 255, 255)),
        array('id' => 'blue',       'label' => 'Blue',       'rgb' => array(  0,   0, 255)),
        array('id' => 'purple',     'label' => 'Purple',     'rgb' => array(130,   0, 255)),
        array('id' => 'magenta',    'label' => 'Magenta',    'rgb' => array(255,   0, 200)),
        array('id' => 'warmwhite',  'label' => 'Warm White', 'rgb' => array(255, 150,  70)),
        array('id' => 'coolwhite',  'label' => 'Cool White', 'rgb' => array(255, 255, 255)),
        // Second wave. Existing entries above are untouched so designs that
        // already point at those files keep working.
        array('id' => 'pink',       'label' => 'Pink',       'rgb' => array(255,  60, 140)),
        array('id' => 'rose',       'label' => 'Rose',       'rgb' => array(255,  20,  70)),
        array('id' => 'coral',      'label' => 'Coral',      'rgb' => array(255,  90,  80)),
        array('id' => 'lime',       'label' => 'Lime',       'rgb' => array(140, 255,   0)),
        array('id' => 'mint',       'label' => 'Mint',       'rgb' => array(  0, 255, 160)),
        array('id' => 'skyblue',    'label' => 'Sky Blue',   'rgb' => array(  0, 140, 255)),
        array('id' => 'indigo',     'label' => 'Indigo',     'rgb' => array( 60,   0, 255)),
        array('id' => 'lavender',   'label' => 'Lavender',   'rgb' => array(170, 120, 255)),
        array('id' => 'icewhite',   'label' => 'Ice White',  'rgb' => array(200, 230, 255)),
        array('id' => 'cream',      'label' => 'Cream',      'rgb' => array(255, 220, 160)),
        array('id' => 'deepred',    'label' => 'Deep Red',   'rgb' => array(140,   0,   0)),
        array('id' => 'forest',     'label' => 'Forest',     'rgb' => array(  0, 120,  40)),
    );
}

function ps_solid_filename($label) { return PS_SOLID_PREFIX . $label . '.fseq'; }

/*
 * Should we encode gamma into the file?
 *
 * FPP corrects at the output with a per-string LUT, f = maxB * pow(f/255, gamma),
 * but only if that string's gamma is set. Baking correction in as well would
 * double-correct, so we only do it when every configured string is at gamma 1.0
 * (i.e. FPP is passing our bytes straight through). Anything we cannot read
 * confidently means we leave the data linear and let FPP own the correction.
 *
 * Note gamma is a STRING in the config and FPP parses it with atof(), where a
 * missing/empty value becomes 0 and is then clamped to 0.01 - so "absent" is not
 * the same as 1.0, and we must not assume it is.
 */
function ps_output_gamma_is_unity() {
    $d = ps_dirs();
    $seen = array();
    foreach (array('co-bbbStrings.json', 'co-pixelStrings.json') as $f) {
        $raw = @file_get_contents($d['config'] . '/' . $f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (!is_array($j)) continue;
        foreach ($j['channelOutputs'] as $co) {
            if (empty($co['enabled'])) continue;
            foreach ((isset($co['outputs']) ? $co['outputs'] : array()) as $o) {
                foreach ((isset($o['virtualStrings']) ? $o['virtualStrings'] : array()) as $v) {
                    if (empty($v['pixelCount'])) continue;
                    if (!isset($v['gamma']) || trim((string)$v['gamma']) === '') return false;  // unreadable
                    $seen[(string)(float)$v['gamma']] = true;
                }
            }
        }
    }
    if (!count($seen)) return false;                 // nothing to go on
    return count($seen) === 1 && isset($seen['1']);  // every string at exactly 1.0
}

// Encode one 0-255 channel perceptually.
function ps_gamma_encode($v, $g) {
    if ($g <= 1.0) return $v;
    return (int)round(255.0 * pow(max(0, min(255, $v)) / 255.0, $g));
}

// True when a sequence name is one of ours, so the UI can tell them apart.
function ps_is_solid($name) { return strpos($name, PS_SOLID_PREFIX) === 0; }

/*
 * Write one solid-colour FSEQ v2 file. Returns true on success.
 *
 * Layout (all little-endian):
 *   0-3   "PSEQ"
 *   4-5   offset to channel data = 32 + blocks*8
 *   6,7   minor 0, major 2
 *   8-9   fixed header length (32)
 *   10-13 channels per frame
 *   14-17 number of frames
 *   18    step time in ms (single byte)
 *   19    flags
 *   20    low nibble = compression type, high nibble = block count bits 8-11
 *   21    block count bits 0-7
 *   22    sparse range count (0)
 *   23    reserved
 *   24-31 unique id
 *   then  blocks*8 bytes of {uint32 firstFrame, uint32 compressedLength}
 *   then  the block payloads, back to back
 */
function ps_write_solid_fseq($path, $rgb, $channels = PS_SOLID_CHANNELS,
                             $frames = PS_SOLID_FRAMES, $stepMs = PS_SOLID_STEP_MS,
                             $gamma = null) {
    $channels = max(3, (int)$channels);
    $channels -= $channels % 3;                       // whole RGB nodes only
    $frames   = max(1, (int)$frames);
    $stepMs   = max(1, min(255, (int)$stepMs));       // the field is one byte

    // One frame: the RGB triple repeated across every channel. FPP's output
    // driver applies each string's colour order, so this stays canonical RGB.
    // $gamma encodes it perceptually when FPP is not correcting at the output.
    $out = $rgb;
    if ($gamma !== null && $gamma > 1.0)
        for ($i = 0; $i < 3; $i++) $out[$i] = ps_gamma_encode($rgb[$i], $gamma);
    $frame = str_repeat(chr($out[0]) . chr($out[1]) . chr($out[2]), intdiv($channels, 3));

    $useZlib = function_exists('gzcompress');
    if ($useZlib) {
        // One frame per block: the decompression buffer is then a single frame.
        $payloads = array();
        $blob = gzcompress($frame, 6);                // zlib-wrapped, matches inflateInit()
        if ($blob === false) {
            $useZlib = false;
        } else {
            for ($i = 0; $i < $frames; $i++) $payloads[] = $blob;   // identical frames
        }
    }

    if ($useZlib) {
        $numBlocks = $frames;
        $dataOffset = 32 + $numBlocks * 8;
        $compType = 2;
    } else {
        // No zlib in this PHP: fall back to uncompressed, which means the file
        // is channels*frames bytes, so keep the covered range modest.
        $numBlocks = 0;
        $dataOffset = 32;
        $compType = 0;
    }
    if ($numBlocks > 4095) return false;              // 12-bit field

    $h = str_repeat("\0", 32);
    $put = function (&$s, $off, $bytes) { for ($i = 0; $i < strlen($bytes); $i++) $s[$off + $i] = $bytes[$i]; };
    $put($h, 0,  'PSEQ');
    $put($h, 4,  pack('v', $dataOffset));
    $put($h, 6,  chr(0) . chr(2));                    // minor 0, major 2
    $put($h, 8,  pack('v', 32));
    $put($h, 10, pack('V', $channels));
    $put($h, 14, pack('V', $frames));
    $put($h, 18, chr($stepMs));
    $put($h, 19, chr(0));
    $put($h, 20, chr(($compType & 0x0F) | ((($numBlocks >> 8) & 0x0F) << 4)));
    $put($h, 21, chr($numBlocks & 0xFF));
    $put($h, 22, chr(0));                             // no sparse ranges
    $put($h, 23, chr(0));
    // The unique id must differ per file: all twelve are written in the same
    // second, so seeding it from the clock alone gave every colour an identical
    // id, which FPP and xLights use to tell sequences apart.
    $put($h, 24, pack('V', crc32(implode(',', $rgb) . '|' . $channels . '|' . $frames))
               . pack('V', time() & 0xFFFFFFFF));

    $tmp = $path . '.tmp';
    $f = @fopen($tmp, 'wb');
    if (!$f) return false;
    fwrite($f, $h);
    if ($numBlocks) {
        foreach ($payloads as $i => $blob) fwrite($f, pack('V', $i) . pack('V', strlen($blob)));
        foreach ($payloads as $blob) fwrite($f, $blob);
    } else {
        for ($i = 0; $i < $frames; $i++) fwrite($f, $frame);
    }
    fclose($f);
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
}
