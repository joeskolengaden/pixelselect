<?php
/*
 * Write endpoint for the pixelselect settings page. Everything the UI changes
 * goes through here so the plugin only ever has to read two files.
 *
 * POST actions:
 *   save     any subset of the settings keys      -> config/plugin.pixelselect
 *   designs  designs=<json array>                 -> config/pixelselect_designs.tsv
 *   cmd      cmd=next|prev|restart|stop|select N  -> virtual button for the plugin
 *   lists    (GET ok) available sequences/playlists/pins + current config+designs
 */
@header('Content-Type: application/json');
require_once(dirname(__FILE__) . '/lib/common.php');
require_once(dirname(__FILE__) . '/lib/solidcolors.php');

function ps_out($ok, $extra = array()) {
    echo json_encode(array_merge(array('ok' => $ok), $extra));
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

if ($action === 'lists') {
    ps_out(true, array(
        'sequences' => ps_available_sequences(),
        'playlists' => ps_available_playlists(),
        'pins'      => ps_pin_list(),
        'sets'      => ps_sets_read(),
        'palette'   => ps_palette(),
        'config'    => ps_cfg_read(),
        'designs'   => ps_designs_read(),
    ));
}

if ($action === 'save') {
    $cfg = ps_cfg_read();
    $bools = array('enabled', 'virtual_enable', 'enable_active_low', 'next_active_low',
                   'repeat', 'wrap', 'resume_last', 'keep_playing', 'takeover', 'hand_back');
    // virtual_set must land on a switch that exists, not just inside the
    // schema's 0-31 - a stored 31 with two switches is meaningless.
    $maxSet = max(0, count(ps_sets_read()) - 1);
    $ints  = array('debounce_ms' => array(1, 1000), 'long_press_ms' => array(0, 10000),
                   'virtual_set' => array(0, $maxSet));
    $enums = array(
        'enable_pull'       => array('gpio', 'gpio_pu', 'gpio_pd'),
        'next_pull'         => array('gpio', 'gpio_pu', 'gpio_pd'),
        'stop_mode'         => array('now', 'graceful', 'afterloop'),
        'long_press_action' => array('none', 'first', 'prev', 'restart'),
    );

    foreach ($bools as $k) {
        if (!isset($_POST[$k])) continue;
        $v = $_POST[$k];
        $cfg[$k] = ($v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
    }
    foreach ($ints as $k => $range) {
        if (!isset($_POST[$k])) continue;
        $cfg[$k] = (string)max($range[0], min($range[1], (int)$_POST[$k]));
    }
    foreach ($enums as $k => $allowed) {
        if (!isset($_POST[$k])) continue;
        if (in_array($_POST[$k], $allowed, true)) $cfg[$k] = $_POST[$k];
    }
    foreach (array('enable_pin', 'next_pin') as $k) {
        if (!isset($_POST[$k])) continue;
        // Pin names look like "P9-15" / "P1-11" / "GPIO18" - keep it to that shape.
        $v = trim($_POST[$k]);
        if ($v !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $v))
            ps_out(false, array('error' => 'Invalid pin name'));
        $cfg[$k] = $v;
    }
    if ($cfg['next_pin'] !== '') {
        foreach (ps_sets_read() as $st) {
            if ($st['pin'] !== '' && $st['pin'] === $cfg['next_pin'])
                ps_out(false, array('error' => 'The pushbutton cannot share a pin with the "' . $st['name'] . '" switch'));
        }
    }

    if (!ps_cfg_write($cfg)) ps_out(false, array('error' => 'Could not write the settings file'));
    ps_out(true, array('config' => $cfg));
}

if ($action === 'solidcolors') {
    // Generate (or delete) the built-in solid-colour sequences. They land in
    // media/sequences like any other .fseq, so they simply appear in the design
    // picker - nothing else in the plugin needs to know they are special.
    require_once(dirname(__FILE__) . '/lib/solidcolors.php');
    $op = isset($_POST['op']) && $_POST['op'] === 'remove' ? 'remove' : 'add';
    $d = ps_dirs();
    if (!is_dir($d['sequences']))
        ps_out(false, array('error' => 'No sequences directory at ' . $d['sequences']));
    if ($op === 'add' && !is_writable($d['sequences']))
        ps_out(false, array('error' => 'Cannot write to ' . $d['sequences']));

    $done = array(); $failed = array();
    foreach (ps_palette() as $c) {
        $path = $d['sequences'] . '/' . ps_solid_filename($c['label']);
        if ($op === 'remove') {
            if (!file_exists($path) || @unlink($path)) $done[] = $c['label'];
            else $failed[] = $c['label'];
        } else {
            if (ps_write_solid_fseq($path, $c['rgb'])) $done[] = $c['label'];
            else $failed[] = $c['label'];
        }
    }
    if ($op === 'remove') {
        // Drop any designs that pointed at a colour we just deleted.
        $designs = ps_designs_read(false);
        $keep = array();
        foreach ($designs as $dd)
            if (!($dd['type'] === 'sequence' && ps_is_solid($dd['name']))) $keep[] = $dd;
        if (count($keep) !== count($designs)) ps_designs_write($keep);
    }
    ps_out(count($failed) === 0, array(
        'op' => $op, 'done' => $done, 'failed' => $failed,
        'compressed' => function_exists('gzcompress'),
        'error' => count($failed) ? ('Could not ' . $op . ': ' . implode(', ', $failed)) : null,
    ));
}

if ($action === 'sets') {
    $list = json_decode(isset($_POST['sets']) ? $_POST['sets'] : '', true);
    if (!is_array($list) || !count($list)) ps_out(false, array('error' => 'Need at least one switch'));
    if (count($list) > 32) ps_out(false, array('error' => 'Too many switches (32 max)'));

    // A switch with no pin can never fire. One is tolerated (that is the state a
    // fresh install starts in); several means something has gone wrong.
    $pinless = 0;
    foreach ($list as $s) { if (trim(isset($s['pin']) ? $s['pin'] : '') === '') $pinless++; }
    if ($pinless > 1 || ($pinless === 1 && count($list) > 1))
        ps_out(false, array('error' => 'Every switch past the first needs its own pin'));

    $seen = array();
    foreach ($list as $s) {
        $pin = isset($s['pin']) ? trim($s['pin']) : '';
        if ($pin === '') continue;
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $pin))
            ps_out(false, array('error' => 'Invalid pin name'));
        if (isset($seen[$pin])) ps_out(false, array('error' => 'Two switches cannot share pin ' . $pin));
        $seen[$pin] = true;
    }
    $cfg = ps_cfg_read();
    if ($cfg['next_pin'] !== '' && isset($seen[$cfg['next_pin']]))
        ps_out(false, array('error' => 'A switch cannot use the pushbutton pin (' . $cfg['next_pin'] . ')'));

    if (!ps_sets_write($list)) ps_out(false, array('error' => 'Could not write the switch list'));

    // Designs pointing at a set that no longer exists fall back to the first.
    $designs = ps_designs_read(false);
    $changed = false;
    foreach ($designs as &$d) {
        if ($d['set'] >= count($list)) { $d['set'] = 0; $changed = true; }
    }
    unset($d);
    if ($changed) ps_designs_write($designs);

    ps_out(true, array('sets' => ps_sets_read(), 'designs' => ps_designs_read()));
}

if ($action === 'designs') {
    $json = isset($_POST['designs']) ? $_POST['designs'] : '';
    $list = json_decode($json, true);
    if (!is_array($list)) ps_out(false, array('error' => 'Malformed design list'));
    if (count($list) > 200) ps_out(false, array('error' => 'Too many designs (200 max)'));

    $seqs = ps_available_sequences();
    $pls  = ps_available_playlists();
    $nsets = count(ps_sets_read());
    $clean = array();
    foreach ($list as $d) {
        if (!is_array($d) || !isset($d['name'])) continue;
        $type = (isset($d['type']) && $d['type'] === 'playlist') ? 'playlist' : 'sequence';
        $name = ps_clean($d['name']);
        if ($name === '') continue;
        // Reject anything that is not actually on this device - a stale name would
        // just make the button appear to do nothing.
        $known = ($type === 'playlist') ? in_array($name, $pls, true) : in_array($name, $seqs, true);
        $set = isset($d['set']) ? (int)$d['set'] : 0;
        if ($set < 0 || $set >= $nsets) $set = 0;
        $clean[] = array(
            'type'    => $type,
            'name'    => $name,
            'label'   => ps_clean(isset($d['label']) ? $d['label'] : $name),
            'enabled' => !empty($d['enabled']),
            'set'     => $set,
            'missing' => !$known,
        );
    }
    if (!ps_designs_write($clean)) ps_out(false, array('error' => 'Could not write the design list'));
    ps_out(true, array('designs' => $clean));
}

if ($action === 'cmd') {
    $cmd = isset($_POST['cmd']) ? trim($_POST['cmd']) : '';
    if (!preg_match('/^(next|prev|restart|stop|select [0-9]{1,3})$/', $cmd))
        ps_out(false, array('error' => 'Unknown command'));
    if (!ps_send_cmd($cmd)) ps_out(false, array('error' => 'Could not reach the plugin'));
    ps_out(true);
}

ps_out(false, array('error' => 'Unknown action'));
