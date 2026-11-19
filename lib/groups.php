<?php
/*
 * Design groups.
 *
 * A design is normally one sequence that loops until the button is pressed. A
 * GROUP is one entry in the same list that plays several sequences in turn and
 * loops round - "these five, over and over, until you press next".
 *
 * A group is stored as a real FPP playlist in media/playlists. That is on
 * purpose: FPP's own playlist engine already walks items, honours durations,
 * picks up a sequence's media file and repeats seamlessly, and the plugin
 * already knows how to start and own a playlist design (type "playlist"). So a
 * group needs no new playback code at all - not a line of C++ - and it behaves
 * identically on 5.4 and 9.x.
 *
 * Looping is NOT baked into the file: the plugin starts a design with
 * "Start Playlist <name> <repeat>", and that repeat flag is what loops the
 * group. Leaving the file's own repeat at 0 means the same group still behaves
 * sanely if a person starts it by hand from FPP's playlist page.
 *
 * Ours are marked by the description field so the plugin can tell a group it
 * created from a playlist the user built themselves - and never overwrite or
 * delete the latter.
 */

define('PS_GROUP_TAG', 'pixelselect group');

function ps_group_path($name) {
    $d = ps_dirs();
    return $d['playlists'] . '/' . $name . '.json';
}

/* A group name becomes a filename, so it has to be boring. Returns '' if bad. */
function ps_group_clean_name($name) {
    $name = ps_clean($name);
    if ($name === '') return '';
    if (preg_match('#[/\\\\]#', $name)) return '';
    if (strpos($name, "\0") !== false) return '';
    if ($name === '.' || $name === '..') return '';
    if (strlen($name) > 80) return '';
    return $name;
}

/*
 * How long a sequence runs, from its own header: frames x step.
 * FPP stores a duration per playlist item and shows it in its UI; getting it
 * from the file beats asking FPP, which would mean a request per sequence.
 */
function ps_fseq_duration($path) {
    $f = @fopen($path, 'rb');
    if (!$f) return null;
    $h = @fread($f, 32);
    @fclose($f);
    if ($h === false || strlen($h) < 32 || substr($h, 0, 4) !== 'PSEQ') return null;
    $frames = unpack('V', substr($h, 14, 4));
    $frames = $frames[1];
    $step   = ord($h[18]);
    if ($frames <= 0 || $step <= 0) return null;
    return round($frames * $step / 1000.0, 3);
}

/* Read a playlist back. Returns null unless it is a group WE made. */
function ps_group_read($name) {
    $name = ps_group_clean_name($name);
    if ($name === '') return null;
    $raw = @file_get_contents(ps_group_path($name));
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    if (!isset($j['desc']) || $j['desc'] !== PS_GROUP_TAG) return null;
    $items = array();
    if (isset($j['mainPlaylist']) && is_array($j['mainPlaylist'])) {
        foreach ($j['mainPlaylist'] as $it) {
            if (is_array($it) && isset($it['sequenceName']) && $it['sequenceName'] !== '')
                $items[] = $it['sequenceName'];
        }
    }
    return array('name' => $name, 'items' => $items);
}

function ps_is_group($name) { return ps_group_read($name) !== null; }

/* Every group on the device, newest-looking order left to natcasesort. */
function ps_group_list() {
    $out = array();
    foreach (ps_available_playlists() as $pl) {
        $g = ps_group_read($pl);
        if ($g !== null) $out[] = $g;
    }
    return $out;
}

/*
 * Write a group. $items is a list of .fseq names already known to be present.
 * Refuses to touch a playlist that exists but is not one of ours, so a group
 * can never eat the user's own "New PlayList".
 *
 * Returns true, or a string explaining why not.
 */
function ps_group_write($name, $items) {
    $name = ps_group_clean_name($name);
    if ($name === '') return 'That group name cannot be used. Leave out slashes and keep it under 80 characters.';
    if (!is_array($items) || !count($items)) return 'A group needs at least one design in it.';
    if (count($items) > 100) return 'A group can hold at most 100 designs.';

    $d = ps_dirs();
    if (!is_dir($d['playlists'])) return 'No playlists directory at ' . $d['playlists'];
    $path = ps_group_path($name);
    if (file_exists($path) && !ps_is_group($name))
        return 'There is already a playlist called "' . $name . '" that this plugin did not create. Pick another name - it will not be overwritten.';
    if (!is_writable($d['playlists'])) return 'Cannot write to ' . $d['playlists'];

    $main = array();
    foreach ($items as $seq) {
        $seq = ps_clean($seq);
        if ($seq === '' || preg_match('#[/\\\\]#', $seq)) continue;
        $dur = ps_fseq_duration($d['sequences'] . '/' . $seq);
        $main[] = array(
            'type'         => 'sequence',
            'enabled'      => 1,
            'playOnce'     => 0,
            'sequenceName' => $seq,
            'duration'     => $dur === null ? 0 : $dur,
        );
    }
    if (!count($main)) return 'None of those designs could be used.';

    // repeat stays 0 here on purpose - see the note at the top of this file.
    $pl = array(
        'name'         => $name,
        'version'      => 3,
        'repeat'       => 0,
        'loopCount'    => 0,
        'empty'        => false,
        'desc'         => PS_GROUP_TAG,
        'random'       => 0,
        'leadIn'       => array(),
        'mainPlaylist' => $main,
        'leadOut'      => array(),
    );
    $text = json_encode($pl);
    if ($text === false) return 'Could not encode the group.';

    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $text) !== strlen($text)) { @unlink($tmp); return 'Could not write the group file.'; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return 'Could not save the group file.'; }
    return true;
}

/* Delete a group. Never touches a playlist the plugin did not create. */
function ps_group_delete($name) {
    $name = ps_group_clean_name($name);
    if ($name === '') return 'Bad group name.';
    if (!file_exists(ps_group_path($name))) return true;      // already gone
    if (!ps_is_group($name))
        return 'There is a playlist called "' . $name . '" that this plugin did not create, so it was left alone.';
    return @unlink(ps_group_path($name)) ? true : 'Could not delete the group file.';
}
