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
        'config'    => ps_cfg_read(),
        'designs'   => ps_designs_read(),
    ));
}

if ($action === 'save') {
    $cfg = ps_cfg_read();
    $bools = array('enabled', 'virtual_enable', 'enable_active_low', 'next_active_low',
                   'repeat', 'wrap', 'resume_last', 'keep_playing', 'takeover', 'hand_back');
    $ints  = array('debounce_ms' => array(1, 1000), 'long_press_ms' => array(0, 10000),
                   'virtual_set' => array(0, 31));
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

if ($action === 'sets') {
    $list = json_decode(isset($_POST['sets']) ? $_POST['sets'] : '', true);
    if (!is_array($list) || !count($list)) ps_out(false, array('error' => 'Need at least one switch'));
    if (count($list) > 32) ps_out(false, array('error' => 'Too many switches (32 max)'));

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
