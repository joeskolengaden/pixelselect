<?php
/*
 * Shared helpers for the pixelselect plugin UI.
 *
 * Two files are written from here and read by libpixelselect.so:
 *   config/plugin.pixelselect        key = "value" settings (FPP's plugin format)
 *   config/pixelselect_designs.tsv   the ordered design list
 *
 * The design list is a TSV rather than JSON on purpose: the C++ side then needs
 * no jsoncpp linkage of its own, which keeps it building on every FPP from 5.4
 * to 9.x without extra dev packages on the device.
 */

function ps_dirs() {
    global $settings;
    $media = isset($settings['mediaDirectory']) ? $settings['mediaDirectory'] : '/home/fpp/media';
    return array(
        'config'    => isset($settings['configDirectory'])   ? $settings['configDirectory']   : $media . '/config',
        'sequences' => isset($settings['sequenceDirectory']) ? $settings['sequenceDirectory'] : $media . '/sequences',
        'playlists' => isset($settings['playlistDirectory']) ? $settings['playlistDirectory'] : $media . '/playlists',
    );
}

function ps_cfg_path()     { $d = ps_dirs(); return $d['config'] . '/plugin.pixelselect'; }
function ps_designs_path() { $d = ps_dirs(); return $d['config'] . '/pixelselect_designs.tsv'; }
function ps_sets_path()    { $d = ps_dirs(); return $d['config'] . '/pixelselect_sets.tsv'; }
// The plugin publishes its live state in /dev/shm - RAM, no SD-card wear, and
// readable by Apache (a /tmp file would land in Apache's PrivateTmp namespace).
// The fallback only ever fires off-device, where /dev/shm does not exist.
function ps_shm_dir() {
    return is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
}
function ps_status_path()  { return ps_shm_dir() . '/pixelselect_status.json'; }
function ps_pins_path()    { return ps_shm_dir() . '/pixelselect_pins.json'; }
function ps_cmd_path()     { return ps_shm_dir() . '/pixelselect_cmd'; }

function ps_defaults() {
    return array(
        'enabled'           => '0',
        'virtual_enable'    => '0',
        'enable_pin'        => '',
        'enable_active_low' => '1',
        'enable_pull'       => 'gpio_pu',
        'next_pin'          => '',
        'next_active_low'   => '1',
        'next_pull'         => 'gpio_pu',
        'debounce_ms'       => '30',
        'long_press_ms'     => '1200',
        'long_press_action' => 'none',
        'repeat'            => '1',
        'wrap'              => '1',
        'stop_mode'         => 'now',
        'resume_last'       => '1',
        'keep_playing'      => '1',
        'takeover'          => '1',
        'hand_back'         => '1',
        'virtual_set'       => '0',
    );
}

function ps_cfg_read() {
    $c = @parse_ini_file(ps_cfg_path());
    if (!is_array($c)) $c = array();
    return array_merge(ps_defaults(), $c);
}

function ps_cfg_write($cfg) {
    $out = '';
    foreach ($cfg as $k => $v) {
        // The C++ side trims surrounding quotes, so a literal quote in a value
        // would corrupt the line - strip them (no setting here needs one).
        $v = str_replace(array('"', "\n", "\r"), '', (string)$v);
        $out .= $k . ' = "' . $v . "\"\n";
    }
    return @file_put_contents(ps_cfg_path(), $out) !== false;
}

/* ------------------------------------------------------------------ designs */

// $withMissing flags entries whose sequence/playlist is no longer on the device,
// so the UI can badge them instead of leaving a button press that does nothing.
function ps_designs_read($withMissing = true) {
    $list = array();
    $raw = @file(ps_designs_path(), FILE_IGNORE_NEW_LINES);
    if (!is_array($raw)) return $list;
    $seqs = $withMissing ? ps_available_sequences() : array();
    $pls  = $withMissing ? ps_available_playlists() : array();
    foreach ($raw as $line) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || $line[0] === '#') continue;
        $f = explode("\t", $line);
        if (count($f) < 2) continue;
        $name = trim($f[1]);
        if ($name === '') continue;
        $label = count($f) > 2 ? trim($f[2]) : '';
        $type = (trim($f[0]) === 'playlist') ? 'playlist' : 'sequence';
        $list[] = array(
            'type'    => $type,
            'name'    => $name,
            'label'   => $label !== '' ? $label : $name,
            'enabled' => count($f) > 3 ? (trim($f[3]) === '1') : true,
            'set'     => count($f) > 4 ? max(0, (int)trim($f[4])) : 0,
            'missing' => $withMissing &&
                         !in_array($name, $type === 'playlist' ? $pls : $seqs, true),
        );
    }
    return $list;
}

function ps_designs_write($list) {
    $out = "# pixelselect design list - written by the plugin UI\n";
    $out .= "# type\tname\tlabel\tenabled\tset\n";
    foreach ($list as $d) {
        $type  = ($d['type'] === 'playlist') ? 'playlist' : 'sequence';
        $name  = ps_clean($d['name']);
        $label = ps_clean(isset($d['label']) ? $d['label'] : '');
        if ($name === '') continue;
        if ($label === '') $label = $name;
        $en    = !empty($d['enabled']) ? '1' : '0';
        $set   = isset($d['set']) ? max(0, (int)$d['set']) : 0;
        $out .= $type . "\t" . $name . "\t" . $label . "\t" . $en . "\t" . $set . "\n";
    }
    return @file_put_contents(ps_designs_path(), $out) !== false;
}

// Tabs and newlines are the record separators, so they can never appear in a field.
function ps_clean($s) {
    return trim(str_replace(array("\t", "\n", "\r"), ' ', (string)$s));
}

/* --------------------------------------------------------------------- sets */
/*
 * Each set is one toggle switch and the designs it selects. Stored as
 * name <TAB> pin <TAB> active_low <TAB> pull, one line per set, in switch order.
 *
 * With no file yet there is a single set built from the original single-switch
 * settings, so an install that predates sets keeps working and simply shows one.
 */
function ps_sets_read() {
    $list = array();
    $raw = @file(ps_sets_path(), FILE_IGNORE_NEW_LINES);
    if (is_array($raw)) {
        foreach ($raw as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || $line[0] === '#') continue;
            $f = explode("\t", $line);
            $name = ps_clean($f[0]);
            if ($name === '') $name = 'Set ' . (count($list) + 1);
            $list[] = array(
                'name'       => $name,
                'pin'        => count($f) > 1 ? ps_clean($f[1]) : '',
                'active_low' => count($f) > 2 ? (trim($f[2]) === '1') : true,
                'pull'       => count($f) > 3 ? ps_clean($f[3]) : 'gpio_pu',
            );
        }
    }
    if (!count($list)) {
        $cfg = ps_cfg_read();
        $list[] = array(
            'name'       => 'Designs',
            'pin'        => $cfg['enable_pin'],
            'active_low' => $cfg['enable_active_low'] === '1',
            'pull'       => $cfg['enable_pull'],
        );
    }
    return $list;
}

function ps_sets_write($sets) {
    $out = "# pixelselect switch sets - written by the plugin UI\n";
    $out .= "# name\tpin\tactive_low\tpull\n";
    $pulls = array('gpio', 'gpio_pu', 'gpio_pd');
    foreach ($sets as $i => $s) {
        $name = ps_clean(isset($s['name']) ? $s['name'] : '');
        if ($name === '') $name = 'Set ' . ($i + 1);
        $pin  = ps_clean(isset($s['pin']) ? $s['pin'] : '');
        $pull = (isset($s['pull']) && in_array($s['pull'], $pulls, true)) ? $s['pull'] : 'gpio_pu';
        $al   = !empty($s['active_low']) ? '1' : '0';
        $out .= $name . "\t" . $pin . "\t" . $al . "\t" . $pull . "\n";
    }
    if (@file_put_contents(ps_sets_path(), $out) === false) return false;

    // Mirror the first set into the documented single-switch settings so the
    // published settingsSchema (and anything driving it over REST) stays true.
    if (count($sets)) {
        $cfg = ps_cfg_read();
        $cfg['enable_pin']        = ps_clean($sets[0]['pin']);
        $cfg['enable_active_low'] = !empty($sets[0]['active_low']) ? '1' : '0';
        $cfg['enable_pull']       = (isset($sets[0]['pull']) && in_array($sets[0]['pull'], $pulls, true))
                                  ? $sets[0]['pull'] : 'gpio_pu';
        ps_cfg_write($cfg);
    }
    return true;
}

/* -------------------------------------------------- what's available to pick */

function ps_available_sequences() {
    $d = ps_dirs();
    $out = array();
    $files = @scandir($d['sequences']);
    if (is_array($files)) {
        foreach ($files as $f) {
            if (substr($f, -5) === '.fseq') $out[] = $f;
        }
    }
    natcasesort($out);
    return array_values($out);
}

function ps_available_playlists() {
    $d = ps_dirs();
    $out = array();
    $files = @scandir($d['playlists']);
    if (is_array($files)) {
        foreach ($files as $f) {
            if (substr($f, -5) === '.json') $out[] = substr($f, 0, -5);
        }
    }
    natcasesort($out);
    return array_values($out);
}

/* ------------------------------------------------------------------- status */

function ps_status() {
    $s = @file_get_contents(ps_status_path());
    $j = $s ? json_decode($s, true) : null;
    if (!is_array($j)) {
        // fppd is not running, or the plugin .so was never loaded.
        $j = array('live' => false, 'pluginEnabled' => false, 'active' => false,
                   'index' => -1, 'count' => count(ps_designs_read()),
                   'label' => '', 'name' => '', 'type' => '', 'playing' => false,
                   'switchOn' => false, 'buttonDown' => false, 'pinError' => '');
    }
    return $j;
}

// Pin names come from the plugin itself (it asks FPP's PinCapabilities, so the
// names match FPP's own GPIO Inputs page). Fall back to fppd's /gpio endpoint if
// the plugin has not run yet, so the pickers still work before the first restart.
function ps_pin_list() {
    $s = @file_get_contents(ps_pins_path());
    $j = $s ? json_decode($s, true) : null;
    if (is_array($j) && count($j)) return $j;

    $ctx = stream_context_create(array('http' => array('timeout' => 2)));
    $s = @file_get_contents('http://127.0.0.1:32322/gpio', false, $ctx);
    $j = $s ? json_decode($s, true) : null;
    $out = array();
    if (is_array($j)) {
        foreach ($j as $p) {
            if (!isset($p['pin'])) continue;
            $out[] = array(
                'pin' => $p['pin'],
                'pu'  => !empty($p['supportsPullUp']),
                'pd'  => !empty($p['supportsPullDown']),
            );
        }
    }
    return $out;
}

// One-shot command for the running plugin (virtual button / select from the UI).
function ps_send_cmd($line) {
    return @file_put_contents(ps_cmd_path(), $line . "\n") !== false;
}
