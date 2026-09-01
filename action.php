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
        'config'    => ps_cfg_read(),
        'designs'   => ps_designs_read(),
    ));
}

if ($action === 'save') {
    $cfg = ps_cfg_read();
    $bools = array('enabled', 'virtual_enable', 'enable_active_low', 'next_active_low',
                   'repeat', 'wrap', 'resume_last', 'keep_playing', 'takeover');
    $ints  = array('debounce_ms' => array(1, 1000), 'long_press_ms' => array(0, 10000));
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
    if ($cfg['enable_pin'] !== '' && $cfg['enable_pin'] === $cfg['next_pin'])
        ps_out(false, array('error' => 'The switch and the button must use different pins'));

    if (!ps_cfg_write($cfg)) ps_out(false, array('error' => 'Could not write the settings file'));
    ps_out(true, array('config' => $cfg));
}

if ($action === 'designs') {
    $json = isset($_POST['designs']) ? $_POST['designs'] : '';
    $list = json_decode($json, true);
    if (!is_array($list)) ps_out(false, array('error' => 'Malformed design list'));
    if (count($list) > 200) ps_out(false, array('error' => 'Too many designs (200 max)'));

    $seqs = ps_available_sequences();
    $pls  = ps_available_playlists();
    $clean = array();
    foreach ($list as $d) {
        if (!is_array($d) || !isset($d['name'])) continue;
        $type = (isset($d['type']) && $d['type'] === 'playlist') ? 'playlist' : 'sequence';
        $name = ps_clean($d['name']);
        if ($name === '') continue;
        // Reject anything that is not actually on this device - a stale name would
        // just make the button appear to do nothing.
        $known = ($type === 'playlist') ? in_array($name, $pls, true) : in_array($name, $seqs, true);
        $clean[] = array(
            'type'    => $type,
            'name'    => $name,
            'label'   => ps_clean(isset($d['label']) ? $d['label'] : $name),
            'enabled' => !empty($d['enabled']),
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
