<?php

/**
 * Copyright (c) 2019 schaal @it UG - info@schaal-24.de
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
*/

require('lib/installer_base.inc.php');
require('lib/installer_proxmox.inc.php');
require('lib/hetzner_network.inc.php');

$inst = new installer_base;

$install = array();
$install['host'] = gethostname();
$install['ip'] = gethostbyname($install['host']);
$install['nic'] = '';
$install['network'] = 'routed';

$robot_account = array('robot_url' => 'https://robot-ws.your-server.de', 'robot_user' => '', 'robot_password' => '');

$inst->disclaimer('Proxmox-Network', '1.0');
$inst->swriteln();
$inst->swriteln('The script generates a network-config.', 'info');
$inst->swriteln();

$install['ip'] = $inst->free_query('Generate the config for the Server with the IP', $install['ip'], 'ip');
do $install['nic'] = $inst->free_query('Use NIC', $install['nic'], 'nic'); while (!$inst->validate($install['nic'], 'not_empty', 'NIC'));

if(file_exists('robot.conf.php')) {
	include('robot.conf.php');
	if(isset($robot_url)) $robot_account['robot_url'] = $robot_url;
	if(isset($robot_user)) $robot_account['robot_user'] = $robot_user;
	if(isset($robot_password)) $robot_account['robot_password'] = $robot_password;
	$inst->swriteln('Using robot.conf.php for your API-Login', 'info');
} 

if($robot_account['robot_url'] == '' || $robot_account['robot_user'] == '' || $robot_account['robot_password'] == '') $inst->api_credentials();

$network = new hetzner_network($install, $robot_account, true);
	
$check = $network->connect();
if($check !== true) {
	$inst->swriteln('Hetzner-API .'.$check);
	$validate = false;
	do {
		$inst->api_credentials();
		$network->reset_account($robot_account);
		$check = $network->connect();
		if($check === true) $validate = true;
	} while (!$validate);
}

$network->network($install['network']);

?>
