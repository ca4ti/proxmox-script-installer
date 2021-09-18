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

class installer_proxmox extends installer_base {
	
	public function install_base() {
		global $install;
		
		$this->swriteln('Installing some packages');
		// update
		system('apt-get update && apt-get -y dist-upgrade');
		// packages
		system('apt-get -y install git htop iotop mc ntp pigz sshfs smartmontools software-properties-common tcpdump vim-nox');
		// postfix
		system("echo postfix postfix/mailname string $install[host] | debconf-set-selections");
		system("echo postfix postfix/main_mailer_type string '$install[postfix_type]' | debconf-set-selections");
		system('apt-get -y install postfix');
	}

	public function create_thinpool() {
		global $install;

		$this->swriteln('Creating Thin-Pool');
		system('lvcreate -n '.$install['proxmox_lv'].' -l 99%FREE '.$install['proxmox_vg']);
		system('lvconvert --type thin-pool '.$install['proxmox_vg'].'/'.$install['proxmox_lv']);
	}

	public function install_proxmox() {
		global $install;
		
		$this->swriteln('Installing Proxmox '.$install['proxmox_version']);
		# install proxmox
		file_put_contents('/etc/apt/sources.list', 'deb http://download.proxmox.com/debian '.strtolower($install['distname'])." pve-no-subscription\n", FILE_APPEND);
		system('wget -q http://download.proxmox.com/debian/proxmox-ve-release-'.$install['proxmox_version'].'.gpg -O /etc/apt/trusted.gpg.d/proxmox-ve-release-'.$install['proxmox_version'].'.gpg');
		system('aptitude -q -y purge firmware-bnx2x firmware-realtek firmware-linux firmware-linux-free firmware-linux-nonfree');
		if(strtolower($install['distname'] == 'buster')) system("echo samba-common samba-common/dhcp boolean false| debconf-set-selections");
		if(strtolower($install['distname'] == 'bullseye')) system("wget -q https://enterprise.proxmox.com/debian/proxmox-release-bullseye.gpg -O /etc/apt/trusted.gpg.d/proxmox-release-bullseye.gpg");
		system('apt-get update && apt -y install proxmox-ve');
	}
	
	public function le() {
		global $install;

		$this->swriteln("Installing Let's Encrypt", 'info');
		$this->_exec("cd /tmp && wget 'https://github.com/Neilpang/acme.sh/archive/master.zip' && unzip master.zip && rm master.zip && mv acme.sh-master/acme.sh /root");
		$this->_exec('mkdir -p /root/.le');
		system('/root/acme.sh --install --accountconf /root/.le/account.conf --accountkey /root/.le/account.key --accountemail '.$install['email']);
		system('/root/acme.sh --issue --standalone --keypath /etc/pve/local/pveproxy-ssl.key --fullchainpath /etc/pve/local/pveproxy-ssl.pem --reloadcmd "systemctl restart pveproxy" -d '.$install['host']);
	}

}
?>
