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

class hetzner_network extends installer_base {

	protected $install = array();
	protected $robot_account = array();
	protected $robot;
	protected $manual = false;
	protected $vlan_networks = array();

	public function __construct($install, $robot_account, $manual = false) {
		require 'RobotRestClient.class.php';
		require 'RobotClientException.class.php';
		require 'RobotClient.class.php';
		$this->install = $install;
		$this->robot_account = $robot_account;
		$this->manual = $manual;
	}

	public function connect() {
		$this->robot = new RobotClient($this->robot_account['robot_url'], $this->robot_account['robot_user'], $this->robot_account['robot_password']);
		$error = '';
		try {
			$check = @$this->robot->serverGetAll();
		} catch (RobotClientException $e) {
			return($e->getMessage());
		}
		return true;
	}

	public function reset_account($robot_account) {
		$this->robot_account = $robot_account;
	}

	public function network($type) {
		global $install;

		$network_setup = array();

		$results = $this->robot->serverGet($install['ip']);

		// routed or bridged
		$network_setup['type'] = $type;

		// main-ip
		$network_setup['server_ip'] = $results->server->server_ip;

		// gateway for main-ip
		$temp = $this->robot->ipGet($network_setup['server_ip']);
		$network_setup['gateway'] = $temp->ip->gateway;


		$this->vswitch_config();

		// add single-ip(s)
		$single_ip = array();
		foreach($results->server->ip as $t) if($t != $network_setup['server_ip']) $network_setup['single_ip'][] = $t;
		
		// add subnets and set main-ipv6
		$ipv6_subnet = array();
		$ipv4_subnet = array();
		$server_ipv6 = '';
		foreach($results->server->subnet as $t) {
			if(filter_var($t->ip.'2', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				$ipv6_subnet[] = array('address' => $t->ip.'1', 'netmask' => $t->mask);
				if($server_ipv6 == '') $server_ipv6 = $t->ip.'2';
			}
			if(filter_var($t->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				$ipv4_subnet[] = array('address' => $t->ip, 'netmask' => $t->mask);
			}
		}

		$network_setup['ipv6_subnet'] = $ipv6_subnet;
		$network_setup['ipv4_subnet'] = $ipv4_subnet;
		$network_setup['server_ipv6'] = $server_ipv6;

		switch($type) {
			case 'routed':
				$this->network_routed($network_setup);
				break;
			default:
				die('Unknnown network-type');
		}
	}

	private function network_routed($network_setup) {
		global $install;

		$vmbr = array();
		$count = 0;
		// ipv4-subnets
		$ipv4_subnet = $network_setup['ipv4_subnet'];
		if(is_array($ipv4_subnet) && !empty($ipv4_subnet)) {
			foreach($ipv4_subnet as $ip) {
				$vmbr[$count]['ipv4_subnet'] = $ip;
					$count++;
			}
			$max = $count;
		}
		if(is_array($ipv4_subnet) && !empty($ipv4_subnet)) $count = $max; else $count = 0;

		// single ip(s)
		$single_ip = @$network_setup['single_ip'];
		if(is_array($single_ip) && !empty($single_ip)) {
			foreach($single_ip as $ip) {
				if(isset($vmbr[$count]['ipv4']))
				$vmbr[$count]['ipv4'] = $vmbr[$count]['ipv4'].','.$ip; else $vmbr[$count]['ipv4'] = $ip;
			}
		}

		// ipv6-subnet
		if(
			(is_array($single_ip) && !empty($single_ip)) ||
			(is_array($ipv4_subnet) && !empty($ipv4_subnet)) 
		) {

			$ipv6_subnet = $network_setup['ipv6_subnet'];
			$count = 0;
			if(is_array($ipv6_subnet) && !empty($ipv6_subnet)) {
				foreach($ipv6_subnet as $ip) {
					$vmbr[$count]['ipv6'] = $ip;
					$count++;
				}
			}
		}
		$nic = $install['nic'];
		$server_ip = $network_setup['server_ip'];
		$gateway = $network_setup['gateway'];
		$server_ipv6 = $network_setup['server_ipv6'];

		// write config
		$out = array();
		$out = $this->network_base($out, $network_setup);
		$out = $this->network_vlan($out);
		foreach($vmbr as $id=>$rec) {
			$out[] ='auto vmbr'.$id;
			foreach($rec as $type=>$ip) {
				switch($type) {
				case 'ipv4':
					$out [] ='iface vmbr'.$id.' inet static';;
					$out [] ="\taddress\t\t".$server_ip;
					$out [] ="\tnetmask\t\t255.255.255.255";
					$out [] ="\tbridge_ports\tnone";
					$out [] ="\tbridge_stp\toff";
					$out [] ="\tbridge_fd\t0";
					$temp=explode(',', $ip);
					foreach($temp as $t) {
						$out [] ="\tup ip route add ".$t.'/32 dev vmbr'.$id;
					}
					$out[] = '';
					break;
				case 'ipv4_subnet':
					$out [] ='iface vmbr'.$id.' inet static';
					$out [] ="\taddress\t\t".$ip['address'];
					$out [] ="\tnetmask\t\t".$ip['netmask'];
					$out [] ="\tbridge_ports\tnone";
					$out [] ="\tbridge_stp\toff";
					$out [] ="\tbridge_fd\t0";
					$out[] = '';
					break;
				case 'ipv6':
					$out [] ='iface vmbr'.$id.' inet6 static';
					$out[]="\taddress\t\t".$ip['address'];
					$out[]="\tnetmask\t\t".$ip['netmask'];
					$out[] = '';
					break;
				}
			}
		}
		$this->write_network_config($out);
	}

	private function network_base($out, $network_setup) {
		global $install;

		$routed = @($network_setup['type'] == 'routed')?true:false;

		$nic = $install['nic'];
		$server_ip = $network_setup['server_ip'];
		$gateway = $network_setup['gateway'];
		$server_ipv6 = $network_setup['server_ipv6'];

		$out[] = '# /etc/network/interfaces';
		$out[] = '';
		$out[] = '### generated using Proxmox-Setup Tool from schaal @it UG';
		$out[] = '### https://URL';
		$out[] = '###';
		$out[] = '### Network-Type '.$network_setup['type'];
		$out[] = '';
		$out[] = '# loopback device';
		$out[] = 'auto lo';
		$out[] = 'iface lo inet loopback';
		if($routed) {
			$out[] = 'iface lo inet6 loopback';
			$out[] = '';
			$out[] = '# network device';
			$out[] = 'auto '.$nic;
			$out[] = 'iface '.$nic.' inet static';
			$out[] = "\taddress\t\t".$server_ip;
			$out[] = "\tnetmask\t\t255.255.255.255";
			$out[] = "\tgateway\t\t".$gateway;
			$out[] = "\tpointopoint\t".$gateway;
			$out[] = '';
			$out[] = 'iface '.$nic.' inet6 static';
			$out[] = "\taddress\t\t".$server_ipv6;
			$out[] = "\tnetmask\t\t128";
			$out[] = "\tgateway\t\tfe80::1";
			$out[] = "\tup sysctl -p";
		}
		$out[] = '';

		return $out;
	}

	private function network_vlan($out) {
		global $install;


		$vlan = false;
		$vlan_extern = false;

		$todo = array();

		if(isset($install['vlan']) && !empty($install['vlan'])) {
			$vlan = true;
			$all = json_decode($install['vlan'], true);
			foreach($all as $vlan_id=>$value) {
				$data = explode(',', $value);
				if(is_array($data) && !empty($data)) {
					$todo[$vlan_id][] = array('type' => 'private', 'ip' => $data[0], 'mask' => $data[1], 'mtu' => $data[2]); 
				}
			}
		}

		if(isset($install['vlan_extern']) && !empty($install['vlan_extern'])) {
			$vlan_extern = true;
			$all_extern = json_decode($install['vlan_extern'], true); 
			foreach($all_extern as $vlan_id=>$value) {
				foreach($value as $val) {
					$data = explode(',', $val);
					if(is_array($data) && !empty($data)) {
						$todo[$vlan_id][] = array('type' => 'public', 'ip' => $data[0], 'mask' => $data[1], 'gw' => $data[2]); 
					}
				}
			}	
		}
		if(is_array($todo) && !empty($todo)) {
			$nic = $install['nic'];
			foreach($todo as $vlan_id=>$value) {
				$out[] = '# vlan raw device';
				$out[] = 'auto '.$nic.'.'.$vlan_id;
				$out[] = 'iface '.$nic.'.'.$vlan_id.' inet static';
				$out[] = "\tvlan-raw-device\t".$nic;
				$out[] = "\tmtu\t\t1400";;
				$out[] = '';
				$out[] = '# vlan';
				$out[] = 'auto vmbr'.$vlan_id;
				$out[] = 'iface vmbr'.$vlan_id.' inet static';
				$out[] = "\tbridge_ports\t".$nic.'.'.$vlan_id;
				$out[] = "\tbridge_stp\toff";
				$out[] = "\tbridge_fd\t0";
				foreach($value as $val) {
					$out[] = "\taddress\t\t".$val['ip'];
					$out[] = "\tnetmask\t\t".$val['mask'];
					$out[] = '';
				}
			}
		}

		return $out;
	}

	private function vswitch_get() {
		global $install;
		
		$out=array();
		$results = $this->robot->vswitchGetAll();
		if(is_array($results) && !empty($results)) {
			foreach ($results as $i) {
				$check = $this->robot->vswitchGet($i->id);
				if($check->cancelled == '') {
					foreach($check->subnet as $_subnet) {
						$this->vlan_networks[$check->id][] = array('ip' => $_subnet->ip, 'mask' => $_subnet->mask);
					}
					foreach($check->server as $server) {
						if($server->server_ip == $install['ip']) {
							$out[$check->id] = $check->vlan;
						}

					}
				}
			}
		}

		return $out;
	}

	private function vswitch_config() {
		global $install;

		$vswitch = $this->vswitch_get();

		$s=array();
		$s_a=array();
		if(is_array($vswitch) && !empty($vswitch)) {
			foreach ($vswitch as $id=>$vlan) {
				$this->swriteln();
				$this->swriteln('This server is connected to a vswitch.');
				$this->swrite("\tID ".$id, 'detail');
				$this->swriteln(' (VLAN ID '.$vlan.')');
				if(@is_array($this->vlan_networks[$id]) && @!empty($this->vlan_networks[$id])) {
					$additional_subnet = true;
					$this->swriteln("\tThe following subnets are assigned to this vSwitch:", 'info');
					foreach($this->vlan_networks[$id] as $networks) $this->swriteln("\t".$networks['ip'].' /'.$networks['mask'], 'info');
				} else {
					$additional_subnet = false;
					$this->swriteln("\tNo public IPs assigned to this vSwitch.", 'info');
				}
				if ($this->simple_query('Add this vSwitch to the network-config?', array('y','n'), 'y') == 'y') { 
					$out = @($additional_subnet === true)?$this->private_network(32):$this->private_network(24);
					if(!empty($out['ip']) && !empty($out['netmask']) && !empty($out['mtu'])) $s[$vlan] = implode(',', $out);
				}
			}
		}	

		if(!empty($s)) $install['vlan'] = json_encode($s);
		if(!empty($s_a)) $install['vlan_extern'] = json_encode($s_a);
	}

	private function private_network($netmask = 24) {
		$out = array('ip' => '', 'netmask' => $netmask, 'mtu' => '1400');
		do $out['ip'] = $this->free_query("Use Private IP", ''); while (!$this->validate($out['ip'], 'is_ipv4', 'IP'));
		if($netmask != 32) { // netmask 32 = subnet for vSwitch
			do $out['netmask'] = $this->free_query("Netmask", $netmask); while (!$this->validate($out['netmask'], 'netmask', 'Netmask'));
		}
		return $out;
	}

	private function additional_subnet($networks) {

		$network_type = false;

		$out = array('ip' => '', 'netmask' => '');
		$out = array();
		if(filter_var($networks['ip'].'2', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$network_type = 'ipv6';
		}
		elseif(filter_var($networks['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$network_type = 'ipv4';
			$temp = $this->cidr2range($networks['ip'].'/'.$networks['mask']);
			for($ip_dec=$temp[0];$ip_dec<=$temp[1];$ip_dec++) $list[]=long2ip($ip_dec);

			$first_ip = $list[0];
			$gw_ip = $list[1];
			array_shift($list);array_shift($list);array_pop($list);

			if(is_array($list) && !empty($list)) {
				$t=array();
				$t[]=0;
				$this->swriteln();
				$this->swriteln("\tYou can use the following IPs from this subnet:");
				foreach($list as $idx=>$val) {
					$count = ++$idx;
					$this->swriteln("\t ($count) $val");
					$t[] = $count;
				}
				$add_ip = $this->simple_query("\tList of IDs to add IPs (separate by , 0 for all)", $t, '');
				$temp = str_replace(' ', '', $add_ip);
				if(intval($temp) == 0) {
					$networks['gw'] = $gw_ip; 
					$out[] = $networks;
				} else {
					$add = explode(',', $temp);
					foreach($add as $i=>$k) {
						if($k != 0) {
							$count = --$k;
							$out[] = array('ip' => $list[$count], 'mask' => '29', 'gw' => $gw_ip);
						}
					}
				}
			}
		}
		if($network_type !== false) return $out;
		return $out;
	}

	private function cidr2range($ipv4){
		if ($ip = strpos($ipv4,'/')) {
			$n_ip = (1<<(32-substr($ipv4,1+$ip)))-1;
			$ip_dec = ip2long(substr($ipv4,0,$ip)); 
		} else {
			$n_ip = 0;
			$ip_dec = ip2long($ipv4);
		}
		$ip_min = $ip_dec&~$n_ip;
		$ip_max = $ip_min+$n_ip;
		return [$ip_min,$ip_max];
	}

	private function write_network_config($out) {

		if($this->manual === false) {
			file_put_contents("/interfaces", implode("\n", $out));

			$this->swriteln('copy /etc/network/interfaces to /root/interfaces.save', 'info');
			system('cp /etc/network/interfaces /root/interfaces.save');

			$this->swriteln('writing new /etc/network/interfaces', 'info');
			system('mv /interfaces /etc/network/interfaces');

			$this->swriteln("\nCheck the network-confg and reboot your server", 'note');
		} else {
			file_put_contents("/root/interfaces.generated", implode("\n", $out));
			$this->swriteln("\nFind the generated config in /root/interfaces.generated", 'info');
		}
	}
}
?>
