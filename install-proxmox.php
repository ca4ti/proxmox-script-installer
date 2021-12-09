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

define('PROXMOX_ROOT', realpath(dirname(__FILE__).'/../proxmox'));
define('VERSION', '1.4');

require 'lib/installer_base.inc.php';
require 'lib/installer_proxmox.inc.php';
require 'lib/hetzner_network.inc.php';
require_once 'lib/tpl.inc.php';

$inst = new installer_base;

// defaults
$install = array();
$install['postfix_type'] = "'Internet Site'";
$install['host'] = gethostname();
$install['nic'] = '';
$install['ip'] = gethostbyname($install['host']);
$install['proxmox_vg'] = '';
$install['proxmox_lv'] = '';
$install['email'] = '';
$install['ssh_port'] = '22';
$install['ssh_rootlogin'] = 'yes';
$install['le'] = 'y';
$install['network'] = '';
$install['update_os'] = 'y';
$install['distname'] = '';
$install['proxmox_version'] = '';

$robot_account = array('robot_url' => 'https://robot-ws.your-server.de', 'robot_user' => '', 'robot_password' => '');
$le_available = false;

$inst->disclaimer('Proxmox-Setup', VERSION);
$inst->get_distname();

$inst->swriteln('Detected OS: Debian '.$install['distname'], 'info');
$inst->swriteln('Install Proxmox-Version: '.$install['proxmox_version'], 'info');
$inst->swriteln();

$inst->get_nic();

$install['host'] = $inst->free_query('Full qualified hostname (FQDN) of the server', $install['host'], 'host');
$install['ip'] = $inst->free_query('IP of the server', $install['ip'], 'ip');
if($install['nic'] != '') {
	$install['nic'] = $inst->free_query('Network Card', $install['nic'], 'nic');
} else {
	$inst->get_nic(true);
}
$inst->swriteln();
$inst->swriteln('The script can configure your network-setup for proxmox. You need API-Credentials for the Hetzner-API.', 'info');
$temp = $inst->simple_query('Do you want to autoconfigure the network?', array('y','n'), 'y');
if($temp == 'y') {
	if(file_exists(PROXMOX_ROOT . '/robot.conf.php')) {
		include(PROXMOX_ROOT . '/robot.conf.php');
		if(isset($robot_url)) $robot_account['robot_url'] = $robot_url;
		if(isset($robot_user)) $robot_account['robot_user'] = $robot_user;
		if(isset($robot_password)) $robot_account['robot_password'] = $robot_password;
		$inst->swriteln('Using robot.conf.php for your API-Login', 'details');
		$inst->swriteln();
	} 
	if($robot_account['robot_url'] == '' || $robot_account['robot_user'] == '' || $robot_account['robot_password'] == '') $inst->api_credentials();
	
	$network = new hetzner_network($install, $robot_account);
	$check = $network->connect();
	if($check !== true) {
		$inst->swriteln('Hetzner-API .'.$check);
		$validate = false;
		do {
			$inst->api_credentials();
			$network ->reset_account($robot_account);
			$check = $network->connect();
			if($check === true) $validate = true;
		} while (!$validate);
	}
	$inst->swriteln('Logged in into the API', 'detail');
	$install['network'] = 'routed';
}

$inst->swriteln();
$inst->swriteln('Set some defaults:', 'info');
$temp = $inst->simple_query('Enabled Thin-Pool for Proxmox?', array('y','n'), 'n');
if($temp == 'y') {
	$vg=array();
	$temp = $inst->_exec('vgdisplay -s');
	if(is_array($temp) && !empty($temp)) {
		foreach($temp as $_vg) {
			$t = explode('"', $_vg);
			$vg[] = $t[1];
			unset($t);
		}
		if(count($vg) !== 1) {
			$check = false;
			do {
				$install['proxmox_vg'] = $inst->simple_query('Use VG for Proxmox Thin-Pool', $vg, $vg[0], 'proxmox_vg');
				if(in_array($proxmox_vg, $temp)) {
					$check = true;
				} 
			} while (!$check);
		} else {
			$inst->swriteln("\tOnly one LV found - using ".$vg[0], 'info');
			$install['proxmox_vg'] = $vg[0];
		}
		$temp = $inst->free_query("Use LV Name for Proxmox Thin-Pool - 'none' to skip", 'data', 'proxmox_lv');
		$install['proxmox_lv'] = @($temp != 'none')?$temp:'';
	} else {
		$inst->swriteln('No LV found', 'warn');
	}
}
unset($temp);
$install['ssh_port'] = $inst->free_query('SSH Port', $install['ssh_port'], 'ssh_port');
$install['ssh_rootlogin'] = $inst->free_query('SSH PermitRootLogin', 'yes', 'ssh_rootlogin');

$install['le'] = $inst->simple_query("Use Let's Encrypt for the Interface", array('y','n'), 'y');
if($install['le'] == 'y') {
	$install['email'] = $inst->free_query("Email to use with Let's Encrypt and in scripts", $install['email'], 'email');
}
if($install['email'] == '') {
	$inst->swriteln("We will not add Let's Encrypt to the interface without an email-address", 'warn');
	$install['le'] = 'n';
}

$inst->swriteln();
$temp = $inst->simple_query('Start Proxmox Install?', array('y','n'), 'y');
if($temp == 'y') {
	$proxmox = new installer_proxmox;
	$proxmox->install_base();
	if($install['update_os'] == 'y') {
		$inst->swriteln("\nUpdating the system\n");
		if($install['ssh_port'] != '22' || $install['ssh_rootlogin'] != 'yes') {
			$inst->swriteln('Updating sshd', 'info');
			$search = array(0 => '^PermitRootLogin yes', 1 => '^#Port 22');
			$replace = array(0 => 'PermitRootLogin '.$install['ssh_rootlogin'], 1 => 'Port '.$install['ssh_port']);
			$content = file_get_contents('/etc/ssh/sshd_config');
			$content = implode($search, $replace);
			$new_content = preg_replace($search, $replace, $content, 1);
			file_put_contents('/etc/ssh/sshd_config', implode("\n", $new_content));
			$inst->_exec('service ssh restart');
		}
	}
	$inst->swriteln("\nInstall Proxmox\n", 'info');
	if ($install['proxmox_vg'] != '' && $install['proxmox_lv'] != '') $proxmox->create_thinpool();
	$proxmox->install_proxmox();
	if($install['le'] == 'y') $proxmox->le();
	if($install['network'] != '') $network->network($install['network']);
}

// process custom-dir
$file = PROXMOX_ROOT . '/custom/etc/aliases'; 
if(file_exists($file)) {
	$inst->swriteln('Updating /etc/aliases', 'info');
	file_put_contents('/etc/aliases', $inst->replace_in_file($file), FILE_APPEND);
	$inst->_exec('newaliases');
	$inst->_exec('service postfix restart');
}

$file = PROXMOX_ROOT . '/custom/ssh/authorized_keys';
if(file_exists($file)) {
	$inst->swriteln('Adding your authorized_keys', 'info');
	if(!is_dir('/root/.ssh')) {
		$inst->_exec('mkdir -p /root/.ssh');
		$inst->_exec('chown root.root /root/.ssh');
		$inst->_exec('chmod 700 /root/.ssh');
	}
	file_put_contents('/root/.ssh/authorized_keys', file_get_contents($file), FILE_APPEND);
	$inst->_exec('chmod 600 /root/.ssh/authorized_keys');
}

$custom_dirs=array('/custom/etc/cron.d', '/custom/etc/sysctl.d', '/custom/root');
foreach ($custom_dirs as $dir) {
	if (is_dir(PROXMOX_ROOT . $dir)) {
		$dir = PROXMOX_ROOT . $dir;
		$inst->swriteln('Try to copy files from '.$dir, 'info');
		$files = $inst->scan_files($dir);
		foreach ($files as $file) {
			$_dir = str_replace(PROXMOX_ROOT . '/custom', '', $file);
			if($inst->mkdir_custom($_dir)) {
				$new = str_replace(PROXMOX_ROOT . '/custom', '', $file);
				file_put_contents($new, $inst->replace_in_file($file));
				$inst->swriteln("\tCopy ".$file, 'detail');
			} else {
				$inst->swriteln("\tCreating target-dir failed - skip ".$file, 'warn');
			}
		}
	}
}

$inst->_exec('echo "syslog errno 1" >> /usr/share/lxc/config/common.seccomp');
$inst->swriteln();
$inst->swriteln('Install finished. You can reboot the server now', 'info');

if($install['proxmox_version'] == '7.x' && $install['le'] == 'y') {
	$inst->swriteln("To active Let's Encrypt run the script /root/add_le.sh after the reboot", 'info');
}
?>
