<?php
/*
 * Off-device test for design groups.
 *
 * A group is stored as a real FPP playlist, which means this code writes into
 * the same folder as the user's own playlists. Most of what is worth testing is
 * therefore about restraint: never overwrite, never delete, never rename
 * something the plugin did not create.
 *
 *   php tests/groups.php
 */

$GLOBALS['ps_fail'] = 0; $GLOBALS['ps_pass'] = 0;
function ok($cond, $what) {
    if ($cond) { $GLOBALS['ps_pass']++; echo "  PASS  $what\n"; }
    else       { $GLOBALS['ps_fail']++; echo "  FAIL  $what\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

$tmp = sys_get_temp_dir() . '/psgrp-' . getmypid();
foreach (array('/config', '/sequences', '/playlists') as $sub) @mkdir($tmp . $sub, 0775, true);
$settings = array('configDirectory' => $tmp . '/config', 'sequenceDirectory' => $tmp . '/sequences',
                  'playlistDirectory' => $tmp . '/playlists', 'mediaDirectory' => $tmp);
$root = dirname(dirname(__FILE__));
require_once $root . '/lib/common.php';
require_once $root . '/lib/solidcolors.php';
require_once $root . '/lib/groups.php';

// three real sequences to put in groups
foreach (array('Red' => array(255,0,0), 'Green' => array(0,255,0), 'Blue' => array(0,0,255)) as $lab => $rgb)
    ps_write_solid_fseq($tmp . '/sequences/demo ' . $lab . '.fseq', $rgb, 300, 40, 250, 1.0);

section('names that must be refused');
foreach (array('' => 'empty', '   ' => 'all spaces', 'a/b' => 'a forward slash',
               'a\\b' => 'a backslash', '..' => 'dot dot', '.' => 'a single dot') as $bad => $why)
    ok(ps_group_clean_name($bad) === '', 'rejects ' . $why);
ok(ps_group_clean_name(str_repeat('x', 81)) === '', 'rejects a name over 80 characters');
ok(ps_group_clean_name('  Winter mix  ') === 'Winter mix', 'trims a good name');

section('duration comes from the sequence header');
$d = ps_fseq_duration($tmp . '/sequences/demo Red.fseq');
ok($d === 10.0, '40 frames x 250ms reads back as 10 s (got ' . var_export($d, true) . ')');
ok(ps_fseq_duration($tmp . '/sequences/nope.fseq') === null, 'a missing file has no duration');
file_put_contents($tmp . '/sequences/junk.fseq', 'not an fseq at all');
ok(ps_fseq_duration($tmp . '/sequences/junk.fseq') === null, 'a file that is not an fseq has no duration');
@unlink($tmp . '/sequences/junk.fseq');

section('writing a group');
$r = ps_group_write('Winter mix', array('demo Red.fseq', 'demo Blue.fseq'));
ok($r === true, 'writes a group');
$j = json_decode(file_get_contents($tmp . '/playlists/Winter mix.json'), true);
ok(is_array($j), 'the file is JSON');
ok($j['name'] === 'Winter mix' && $j['version'] === 3, 'it is a v3 FPP playlist named after the group');
ok(count($j['mainPlaylist']) === 2, 'with both items');
ok($j['mainPlaylist'][0]['sequenceName'] === 'demo Red.fseq'
   && $j['mainPlaylist'][1]['sequenceName'] === 'demo Blue.fseq', 'in the order given');
// JSON has no int/float distinction: 10.0 comes back as int 10, which is what
// FPP writes too, so compare numerically rather than by type.
ok(abs($j['mainPlaylist'][0]['duration'] - 10.0) < 0.001, 'each item carries its real duration ('
   . var_export($j['mainPlaylist'][0]['duration'], true) . ')');
ok($j['mainPlaylist'][0]['type'] === 'sequence' && $j['mainPlaylist'][0]['enabled'] === 1
   && $j['mainPlaylist'][0]['playOnce'] === 0, 'items have the fields FPP expects');
// Looping is the plugin's "Start Playlist <name> <repeat>" flag, not the file's.
ok($j['repeat'] === 0, 'the file itself does not force repeat - the plugin passes that when starting');

section('reading it back');
$g = ps_group_read('Winter mix');
ok($g !== null && $g['items'] === array('demo Red.fseq', 'demo Blue.fseq'), 'round trips through ps_group_read');
ok(ps_is_group('Winter mix'), 'recognised as a group');
ok(count(ps_group_list()) === 1, 'ps_group_list finds it');

section('restraint: a playlist the user made is never touched');
$mine = array('name' => 'My Show', 'version' => 3, 'repeat' => 1, 'desc' => '',
              'mainPlaylist' => array(array('type' => 'sequence', 'enabled' => 1, 'playOnce' => 0,
                                            'sequenceName' => 'demo Green.fseq', 'duration' => 10)));
file_put_contents($tmp . '/playlists/My Show.json', json_encode($mine));
ok(ps_group_read('My Show') === null, 'someone else\'s playlist is not read as a group');
ok(!ps_is_group('My Show'), 'and is not reported as one');
ok(count(ps_group_list()) === 1, 'ps_group_list still lists only ours');
$r = ps_group_write('My Show', array('demo Red.fseq'));
ok(is_string($r), 'writing over it is refused: ' . (is_string($r) ? 'yes' : 'NO'));
$still = json_decode(file_get_contents($tmp . '/playlists/My Show.json'), true);
ok($still['mainPlaylist'][0]['sequenceName'] === 'demo Green.fseq' && $still['repeat'] === 1,
   'and the file is byte-for-byte what it was');
$r = ps_group_delete('My Show');
ok(is_string($r), 'deleting it is refused');
ok(file_exists($tmp . '/playlists/My Show.json'), 'and it is still there');

section('bad input');
ok(is_string(ps_group_write('Empty', array())), 'an empty group is refused');
ok(is_string(ps_group_write('../evil', array('demo Red.fseq'))), 'a path separator in the name is refused');
ok(!file_exists(dirname($tmp . '/playlists') . '/evil.json'), 'and nothing was written outside the folder');
ok(is_string(ps_group_write('Huge', array_fill(0, 101, 'demo Red.fseq'))), 'over 100 items is refused');

section('editing and deleting our own');
ok(ps_group_write('Winter mix', array('demo Blue.fseq', 'demo Green.fseq', 'demo Red.fseq')) === true,
   'a group can be rewritten with new members');
$g = ps_group_read('Winter mix');
ok($g['items'] === array('demo Blue.fseq', 'demo Green.fseq', 'demo Red.fseq'), 'the new order sticks');
ok(ps_group_delete('Winter mix') === true, 'our own group deletes');
ok(!file_exists($tmp . '/playlists/Winter mix.json'), 'the file is gone');
ok(count(array_diff(scandir($tmp . '/sequences'), array('.', '..'))) === 3,
   'and the sequences inside it are untouched');
ok(ps_group_delete('Winter mix') === true, 'deleting a group that is already gone is not an error');

foreach (glob($tmp . '/playlists/*') as $f) @unlink($f);
foreach (glob($tmp . '/sequences/*') as $f) @unlink($f);
foreach (glob($tmp . '/config/*') as $f) @unlink($f);
foreach (array('/config', '/sequences', '/playlists') as $sub) @rmdir($tmp . $sub);
@rmdir($tmp);

echo "\n";
if ($GLOBALS['ps_fail'] === 0) { echo "ALL PASS (" . $GLOBALS['ps_pass'] . " checks)\n"; exit(0); }
echo $GLOBALS['ps_fail'] . " FAILURE(S) of " . ($GLOBALS['ps_pass'] + $GLOBALS['ps_fail']) . "\n";
exit(1);
