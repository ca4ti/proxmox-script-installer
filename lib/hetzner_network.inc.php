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
	protected $version = '1.2';
	protected $support_url = 'https://schaal-it.com/script-to-install-proxmox-5-x-and-6-x-on-a-dedicated-hetzner-server/';

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

	public function get_traffic_ip_hour($ip) {
		
		$start_date = date("Y-m-d", time() - 7200);
		$stop_date = date("Y-m-d", time() - 3600);
		$start_hour = date("H", time() - 7200);
		$stop_hour = date("H", time() - 3600);

		$from_date = $start_date.'T'.$start_hour;
		$to_date = $stop_date.'T'.$stop_hour;

		$traffic_hour = $this->robot->trafficGetForIp($ip, 'day', $from_date, $to_date);
		$traffic_today = $this->get_traffic_ip_today($ip);


		$today = $value = json_decode(json_encode($traffic_today->traffic), true);
		$hour = $value = json_decode(json_encode($traffic_hour->traffic), true);

return(array('today' => $today, 'hour' => $hour));
//return $hour;


	}
	
	public function get_traffic_ip_today($ip) {
		$start_date = date("Y-m-d", time());
		$stop_date = date("Y-m-d", time());
		$from_date = $start_date.'T00';
		$stop_hour = date("H", time());
		$to_date = $stop_date.'T'.$stop_hour;
		return $this->robot->trafficGetForIp($ip, 'day', $from_date, $to_date);
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

		// connected vswitches
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
				$ipv6_subnet[] = array('address' => $t->ip.'2', 'netmask' => $t->mask);
				if($server_ipv6 == '') $server_ipv6 = $t->ip.'2';
			}
			if(filter_var($t->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				$ipv4_subnet[] = array('address' => $t->ip, 'netmask' => $t->mask);
			}
		}

		$network_setup['ipv6_subnet'] = $ipv6_subnet;
		$network_setup['ipv4_subnet'] = $ipv4_subnet;
		$network_setup['server_ipv6'] = $server_ipv6;

		$this->write_network_config($network_setup);

	}

	private function write_network_config($network_setup) {
		global $install;

		//* write config
		$tpl = new tpl();
		$tpl->newTemplate('network.tpl');
		$tpl->setVar('version', VERSION);
		$tpl->setVar('network_type', $network_setup['type']);
		$tpl->setVar('nic', $install['nic']);
		$tpl->setVar('server_ip', $network_setup['server_ip']);
		$tpl->setVar('gateway', $network_setup['gateway']);
		$tpl->setVar('server_ipv6', $network_setup['server_ipv6']);

		//* vswitch-ips
		$out = $this->network_vlan();
		if(is_array($out) && !empty($out)) {
			$tpl_rec = array();
			foreach($out as $idx=>$data) {
				foreach($data as $val) {
					$rec = $val;
					$rec['nic'] = $install['nic'];
					$rec['vlan_id'] = $idx;
					$tpl_rec[] = $rec;
				}
			}
			$tpl->setLoop('vlan_devices', $tpl_rec);
		}

		//* server-ips
		$single_ips = @$network_setup['single_ip'];

		//* build subnet-array
		$subnets = array();
		$id = 0;
		foreach($network_setup['ipv4_subnet'] as $subnet) {
			$subnets[$id]['ipv4'] = $subnet;
			$id++;
		}
		$id = 0;
		foreach($network_setup['ipv6_subnet'] as $subnet) {
			$subnets[$id]['ipv6'] = $subnet;
			$id++;
		}

		$vmbr_id = ($network_setup['type'] == 'routed') ? 0 : 1;

		if(is_array($subnets) && !empty($subnets)) {
			$tpl_rec = array();
			$count = 0;
			foreach($subnets as $subnet) {
				$tpl_rec = array();
				$rec = array();
				$rec['vmbr_id'] = $vmbr_id;
				$has_ipv4 = (isset($subnet['ipv4']) && !empty($subnet['ipv4'])) ? true :false;
				$has_ipv6 = (isset($subnet['ipv6']) && !empty($subnet['ipv6'])) ? true :false;
				if($has_ipv4 === true) {
					$rec['ip'] = $subnet['ipv4']['address'];
					$rec['mask'] = $subnet['ipv4']['netmask'];
				}
				if($has_ipv6 === true) {
					$rec['ip_v6'] = $subnet['ipv6']['address'];
					$rec['mask_v6'] = $subnet['ipv6']['netmask'];
				}
				if(is_array($single_ips) && !empty($single_ips) && $vmbr_id == 0) {
					$count = 0;
					$temp = array();
					$rec['vmbr_id'] = $vmbr_id;
					$rec['ip'] = $network_setup['server_ip'];
					$rec['mask'] = 32;
					foreach($single_ips as $single_ip) {
						$temp[$count]['single_ip'] = $single_ip;
						$temp[$count]['vmbr_id'] = $vmbr_id;
						$count++;
					}
					$rec['single_ips'] = $temp;
				}
				$tpl_rec[] = $rec;
				$vmbr_id++;
			}
			$tpl->setLoop('local_ips', $tpl_rec);
		}

		if($this->manual === false) {
			$this->swriteln('copy /etc/network/interfaces to /root/interfaces.save', 'info');
			system('cp /etc/network/interfaces /root/interfaces.save');

			$this->swriteln('writing new /etc/network/interfaces', 'info');
			$this->wf("/etc/network/interfaces", $tpl->grab());

			$this->swriteln("\nCheck the network-confg and reboot your server", 'note');
		} else {
			$this->wf("/root/interfaces.generated", $tpl->grab());
			$this->swriteln("\nFind the generated config in /root/interfaces.generated.", 'info');
			$this->swriteln("\nFor Debian Buster do not forget to install bridge-utils.", 'info');
		}
	}

	private function network_vlan() {
		global $install;

		$vlan = false;
		$vlan_extern = false;

		$out = array();

		if(isset($install['vlan']) && !empty($install['vlan'])) {
			$vlan = true;
			$all = json_decode($install['vlan'], true);
			foreach($all as $vlan_id=>$value) {
				$data = explode(',', $value);
				if(is_array($data) && !empty($data)) {
					$out[$vlan_id][] = array('type' => 'private', 'ip' => $data[0], 'mask' => $data[1], 'mtu' => $data[2]); 
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
						$out[$vlan_id][] = array('type' => 'public', 'ip' => $data[0], 'mask' => $data[1], 'gw' => $data[2]); 
					}
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

}
?>
