<?php
/*
 * Generic FSEQ v2 writer, for content that changes frame to frame.
 *
 * Deliberately separate from the solid-colour writer in solidcolors.php, which
 * is in service and stays untouched: that one exploits every frame being
 * identical (it compresses one frame and reuses the blob), which is useless here.
 *
 * Same on-disk layout, so the same notes apply: step time is a single byte,
 * the block index is 8 bytes per block right after the 32-byte header, and one
 * frame per block keeps the decode buffer to a single frame.
 */

function ps_fseq_write($path, $frameBlobs, $channels, $stepMs, $uidSeed = 0) {
    $frames = count($frameBlobs);
    if ($frames < 1) return false;
    $channels = max(3, (int)$channels);
    $stepMs = max(1, min(255, (int)$stepMs));

    $useZlib = function_exists('gzcompress');
    $payloads = array();
    if ($useZlib) {
        foreach ($frameBlobs as $b) {
            $z = gzcompress($b, 6);
            if ($z === false) { $useZlib = false; $payloads = array(); break; }
            $payloads[] = $z;
        }
    }
    $numBlocks  = $useZlib ? $frames : 0;
    $dataOffset = 32 + $numBlocks * 8;
    $compType   = $useZlib ? 2 : 0;
    if ($numBlocks > 4095) return false;

    $h = str_repeat("\0", 32);
    $put = function (&$s, $off, $bytes) { for ($i = 0; $i < strlen($bytes); $i++) $s[$off + $i] = $bytes[$i]; };
    $put($h, 0,  'PSEQ');
    $put($h, 4,  pack('v', $dataOffset));
    $put($h, 6,  chr(0) . chr(2));
    $put($h, 8,  pack('v', 32));
    $put($h, 10, pack('V', $channels));
    $put($h, 14, pack('V', $frames));
    $put($h, 18, chr($stepMs));
    $put($h, 19, chr(0));
    $put($h, 20, chr(($compType & 0x0F) | ((($numBlocks >> 8) & 0x0F) << 4)));
    $put($h, 21, chr($numBlocks & 0xFF));
    $put($h, 22, chr(0));
    $put($h, 23, chr(0));
    $put($h, 24, pack('V', $uidSeed & 0xFFFFFFFF) . pack('V', time() & 0xFFFFFFFF));

    $tmp = $path . '.tmp';
    $f = @fopen($tmp, 'wb');
    if (!$f) return false;
    fwrite($f, $h);
    if ($numBlocks) {
        foreach ($payloads as $i => $z) fwrite($f, pack('V', $i) . pack('V', strlen($z)));
        foreach ($payloads as $z) fwrite($f, $z);
    } else {
        foreach ($frameBlobs as $b) fwrite($f, $b);
    }
    fclose($f);
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
}
