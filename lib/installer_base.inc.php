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

class installer_base {
	
	public function _exec($cmd) {

		$descriptorspec = array( 0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		flush();
		$process = proc_open(exec($cmd, $ret, $val), $descriptorspec, $pipes, realpath('./'), array());
		if (is_resource($process)) {
			while ($s = fgets($pipes[1])) {
				print $s;
				flush();
			}
		}
		if($val != 0) die($cmd.' failed');
		return $ret;
	}

	public function free_query($query, $default, $name = '') {
		global $autoinstall;

		if($name != '' && $autoinstall[$name] != '') $input = $autoinstall[$name];
		else {
			$this->swrite($query.' ['.$default.']: ');
			$input = $this->sread();
		}
		$answer = @($input == '')?$default:$input;

		return $answer;
	}

	public function simple_query($query, $answers, $default, $name = '') {
		global $autoinstall;
	
		$finished = false;
		do {
			if($name != '' && $autoinstall[$name] != '') $input = $autoinstall[$name];
			else {
				$answers_str = implode(',', $answers);
				$this->swrite($query.' ('.$answers_str.') ['.$default.']: ');
				$input = $this->sread();
			}
			//* Select the default
			if($input == '') {
				$answer = $default;
				$finished = true;
			}
			//* Set answer id valid
			if(in_array($input, $answers)) {
				$answer = $input;
				$finished = true;
			}
		} while ($finished == false);
		
		return $answer;
	}

	public function sread() {
		$input = fgets(STDIN);
		return rtrim($input);
	}

	public function swrite($text = '', $mode = 'normal') {
		switch($mode) {
			case 'crit':
				echo $text.' - aborting';
				echo "\033[0;31";
				break;
			case 'warn':
				echo "\033[1;31m";
				break;
			case 'detail':
				echo "\033[1;32m";
				break;
			case 'info':
				echo "\033[0;32m";
				break;
			case 'note':
				echo "\033[0;34m";
				break;
			default:
				echo "\033[0m";
				 break;
		}
		echo $text;
		echo "\033[0m";
		if($mode == 'crit') exit;
	}

	public function swriteln($text = '', $mode = 'normal') {
		$this->swrite($text."\n", $mode);
	}

	public function validate($data, $type, $error) {
		switch($type) {
			case 'not_empty':
				if(trim($data) == '') {
					$this->swriteln($error . ' must not be empty!', 'warn');
					return false;
				}
				break;
			case 'is_ipv4':
				if (!filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					$this->swriteln($error . ' '.$data.' is not a valid IPv4 address!', 'warn');
					return false;
				}
				break;
			case 'netmask':
				if (!filter_var($data, FILTER_VALIDATE_INT, array('options' => array('min_range' => 8, 'max_range' => 29 )))) {
					$this->swriteln($error . ' '.$data.' is not a Subnet-Mask (8 - 29)', 'warn');
					return false;
				}
				break;
		}

		return true;
	}

	public function get_nic($retry=false) {
		global $inst, $install;

		if($install['nic'] == '') {
			$ret = $inst->_exec('ls /sys/class/net|grep -v lo');
			//	if($val !== 0) die('Unable to find network-card. Aborting');
			if(count($ret) !== 1) {
				if(!$ret) {
					$install['nic'] == '';
					return;
				}
				$check=false;
				do {
		 			$install['nic'] = $inst->simple_query('Multiple NICs found - choose your primary NIC', $ret, $ret[0], $install['nic']);
					if(in_array($install['nic'], $ret)) {
						$check = true;
					} 
				} while (!$check);
			}
			$install['nic'] = $ret[0];
		}
	}

	public function get_distname() {
		global $inst, $install;

		$distver = '';

		if(file_exists('/etc/debian_version')) {
			// Check if this is Ubuntu and not Debian
			if (strstr(trim(file_get_contents('/etc/issue')), 'Ubuntu') || (is_file('/etc/os-release') && stristr(file_get_contents('/etc/os-release'), 'Ubuntu'))) {
				$distver = 'unknown';
			} elseif(substr(trim(file_get_contents('/etc/debian_version')),0,1) == '9') {
				$distver = 'Stretch';
			} elseif(substr(trim(file_get_contents('/etc/debian_version')),0,2) == '10') {
				$distver = 'Buster';
			} elseif(strstr(trim(file_get_contents('/etc/debian_version')), '/sid')) {
				$distver = 'Buster';
			} else {
				$distver = 'unknown';
			}
		} else {
			$distver = 'unknown';
		}
	
		if($distver == 'unknown') {
			$inst->swriteln("Unknown Operating System\n", 'warn');
			$check=false;
			do {
		 		$distver = $inst->simple_query('Enter your Operating System', array('Stretch', 'Buster'), '');
				if($distver == 'Stretch' || $distver == 'Buster') $check = true;
			} while (!$check);
		}
		if ($distver == 'Stretch') $install['proxmox_version'] = '5.x';
		if ($distver == 'Buster') $install['proxmox_version'] = '6.x';
		$install['distname'] = $distver;	
	}
	
	public function api_credentials() {
		global $inst, $robot_account;

		$inst->swriteln('Enter your credentials for the Hetzner-API');
		foreach($robot_account as $t=>$i) {
			$check=false;
			do {
				$robot_account[$t] = $inst->free_query($t, $i);
				if($robot_account[$t] != '') $check = true;
			} while (!$check);
		}
		return $robot_account;
	}

	public function scan_files($dir, $only=array()) {
		$skip=array('.example');
		$files = array();

		$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($rii as $file) {
			if ($file->isDir()) continue;
			if(!empty($only)) {
				foreach($only as $extension) {
					if($this->endsWith($file->getPathname(), $extension) === true && $ignore === false) {
						$files[] = $file->getPathname();
						continue;
					}
				}
			} else {
				if (!$this->endsWith($file->getPathname(), $skip)) $files[] = $file->getPathname();
			}
		}

		return $files;
	}

	public function endsWith($haystack, $needle) {

		$found = false;
		foreach($needle as $i) {
			$length = strlen($i);
			if( (substr($haystack, -$length) === $i)) return true;
		}

		return false;
	}

	public function replace_in_file($file) {
		global $install;
	
		if(file_exists('replace.conf.php')) {
			require('replace.conf.php');
		} else {
			$search = array('_EMAIL_');
			$replace = array ('email');
		}

		foreach($replace as $idx=>$val) {
			if(isset($install[$val])) $replace[$idx] = $install[$val];
		}

		$content = file_get_contents($file);
		$new_content = preg_replace($search, $replace, $content);
		return $new_content;
	}

	public function mkdir_custom($file) {
		$t = explode('/', $file);
		if (count($t) > 1) {
			array_pop($t);
			$new = implode('/', $t);
			exec(('mkdir -p '.$new), $ret, $val);
			if($ret == 0) return true; else return false;
		}
		return true;
	}

	public function disclaimer($title, $version) {
		$this->swriteln("Welcome to the $title Tool $version from schaal@it UG", 'info');
		$this->swriteln();
		$this->swriteln('You need to have some prerequisites set up to use this tool:');
		$this->swriteln(' * Credentials for the Hetzner-API');
		$this->swriteln();
		$this->swriteln('*** Disclaimer of Warranties ***', 'detail');
		$this->swriteln('schaal @it UG disclaims to the fullest extent authorized by law any and all other warranties, whether express or implied,');
		$this->swriteln('including, without limitation, any implied warranties of title, non-infringement, integration, merchantability or');
		$this->swriteln('fitness for a particular purpose.');
		$this->swriteln('By continuing to use this software, you agree to this.');
		$this->swriteln();
		$latest = @dns_get_record('hetzner-proxmox.schaal-it.net', DNS_TXT)[0]['entries'][0];
		if($latest !== false && version_compare($latest, $version, '>')) { 
			$this->swriteln();
			$this->swriteln('This version '.$version.' is outdated - the latest version is '.$latest, 'warn');
			$this->swriteln('You can get the latest version from https://download.schaal-it.net/hetzner-proxmox.tgz');
			$this->swriteln();
			$temp = $this->simple_query('Continue without updating?', array('y','n'), 'n');
			if($temp == 'n') die('aborted');

		}
	}

}
?>
