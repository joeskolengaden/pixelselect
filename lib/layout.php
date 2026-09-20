<?php
/*
 * Where the pixels actually are.
 *
 * A solid colour does not care about layout - every channel gets the same byte.
 * An animated pattern does: "chase down the string" is meaningless unless we
 * know where each string starts and how many pixels it has. So patterns are
 * rendered against the controller's real output config rather than a generic
 * channel count.
 *
 * Reads the string-output config files FPP uses (BeagleBone and Pi). Notes that
 * cost time to rediscover:
 *  - A port carries up to FOUR virtual-string groups: virtualStrings plus
 *    virtualStringsB/C/D. Walking only the first silently drops pixels.
 *  - channelsPerNode is NOT a field in the JSON; FPP computes it. The rule
 *    (PixelString.cpp) is: 1 for a single-channel/smart-receiver string,
 *    4 when colorOrder is four characters (it carries a W), otherwise 3.
 *  - startChannel on a virtual string is a 0-based offset into the sequence data.
 */

function ps_layout_files() {
    return array('co-bbbStrings.json', 'co-pixelStrings.json');
}

// FPP's rule, mirrored exactly.
function ps_channels_per_node($colorOrder) {
    $o = strtoupper(trim((string)$colorOrder));
    if ($o === '' ) return 3;
    if (strlen($o) === 1) return 1;      // single channel / white only
    if (strlen($o) === 4) return 4;      // RGBW in some order
    return 3;
}

/*
 * Returns:
 *   array(
 *     'strings'    => array(array('start'=>int, 'pixels'=>int, 'cpn'=>int, 'order'=>string), ...),
 *     'pixels'     => total pixel count,
 *     'maxChannel' => highest channel index touched (0-based, exclusive),
 *     'source'     => which files contributed,
 *   )
 * An empty 'strings' means we could not determine a layout - callers must not
 * guess one, because a pattern rendered against the wrong layout looks broken.
 */
function ps_read_layout() {
    $d = ps_dirs();
    $strings = array(); $src = array();
    foreach (ps_layout_files() as $f) {
        $raw = @file_get_contents($d['config'] . '/' . $f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['channelOutputs'])) continue;
        $before = count($strings);
        foreach ($j['channelOutputs'] as $co) {
            if (empty($co['enabled'])) continue;
            foreach ((isset($co['outputs']) ? $co['outputs'] : array()) as $o) {
                foreach (array('virtualStrings', 'virtualStringsB', 'virtualStringsC', 'virtualStringsD') as $key) {
                    if (!isset($o[$key]) || !is_array($o[$key])) continue;
                    foreach ($o[$key] as $v) {
                        $n = isset($v['pixelCount']) ? (int)$v['pixelCount'] : 0;
                        if ($n <= 0) continue;
                        $order = isset($v['colorOrder']) ? $v['colorOrder'] : 'RGB';
                        $strings[] = array(
                            'start'  => isset($v['startChannel']) ? (int)$v['startChannel'] : 0,
                            'pixels' => $n,
                            'cpn'    => ps_channels_per_node($order),
                            'order'  => strtoupper(trim((string)$order)),
                        );
                    }
                }
            }
        }
        if (count($strings) > $before) $src[] = $f;
    }

    usort($strings, function ($a, $b) { return $a['start'] - $b['start']; });
    $pixels = 0; $max = 0;
    foreach ($strings as $s) {
        $pixels += $s['pixels'];
        $end = $s['start'] + $s['pixels'] * $s['cpn'];
        if ($end > $max) $max = $end;
    }
    return array('strings' => $strings, 'pixels' => $pixels,
                 'maxChannel' => $max, 'source' => $src);
}

function ps_layout_summary($L = null) {
    if ($L === null) $L = ps_read_layout();
    if (!count($L['strings'])) return 'no pixel strings configured';
    $orders = array();
    foreach ($L['strings'] as $s) $orders[$s['order']] = true;
    return sprintf('%d strings, %s pixels, %d channels (%s)',
                   count($L['strings']), number_format($L['pixels']),
                   $L['maxChannel'], implode('/', array_keys($orders)));
}
