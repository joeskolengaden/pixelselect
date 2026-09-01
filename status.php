<?php
/* Live status for the pixelselect settings page (polled every couple of seconds). */
@header('Content-Type: application/json');
require_once(dirname(__FILE__) . '/lib/common.php');

$st = ps_status();
$st['designs'] = count(ps_designs_read(false));
echo json_encode($st);
