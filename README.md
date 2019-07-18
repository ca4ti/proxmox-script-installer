# Script to install Proxmox 5.x and 6.x on a Dedicated Hetzner Server

The Proxmox-Version depends on your OS:
Proxmox 5.x on Debian Jessie and Proxmox 6.x on Debian Buster

- Install Proxmox on your server 
- Let's Encrypt Certificate for the Proxmox-Interface
- Option to use Thin-Pool Storage
- Read the Server-IPs (Single-IP and Subnet) from the Hetzner-Robot
- Write the Network-Config 
- Option to create private IPs if you use a vSwitch

## Notes
You can put your Robot-Credentials in the file robot.conf.php so the script will not ask your for the Robot-Login.

If you just want to generate the network config (even for a different server), see chapter **Network-Setup** at the end of this page

## Installation
Boot your server into the Rescue-Mode, use `installimage` and Choose the minimal Debian-Strech or Debian-Buster Version.

Set the **HOSTNAME** to a FQDN

If you want to use Thin-Pool, use something lik:
```
PART lvm pve all
LV   pve root / ext4 10G
```

Reboot the server and run the following commands to download the script:
```
apt -y update
apt -y install php-cli php-curl wget
cd /root
wget https://download.schaal-it.net/hetzner-proxmox.tgz
tar xfz hetzner-proxmox.tgz
cd proxmox
```

## Install Proxmox

To install Proxmox, please read the following notes before running the script.

The directory custom contains several files that are used during the installation. 

In the **custom directory** you will find:
*  etc/aliases
*  etc/cron.d/trim.example
*  etc/sysctl.d/pve.conf
*  root/trim.sh.example
*  root/update-lxc.sh.example
*  ssh (empty)

If you want to install your ssh-key, just put your public-key into **ssh/authorized_keys**. The installer will copy this file to **/root/.ssh/authorized_keys**

The files from etc/cron.d calls the responding scripts from root. If you want to use them, rename the file in etc/cron.d and root. 

You can also put your own files into the custom-dir and / or change the files. For more informations see the file **custom/README.txt**.

To finally install proxmox, just run

```
php install-proxmox.php
```

**The script shows you the detected OS and the Proxmox-Version, that will be installed:**
```
Detected OS: Debian Buster
Install Proxmox-Version: 6.x
```

**You will be asked the following questions:**

```
Full qualified hostname (FQDN) of the server [server]:
```
Add the full name here (i.e. server.example.com). Otherwise you can not use Let's Encrypt.

```
IP of the server [100.150.0.100]:
```
Make sure that the recognized ip is also the one from your server

```
Network Card [enp0s31f6]:
```
Usually, you don't have to change the detected value.

```
Do you want to autoconfigure the network? (y,n) [y]:
```
Choose **y** to let the script generate the network-config.

```
Enter your credentials for the Hetzner-API
robot_url [https://robot-ws.your-server.de]:
robot_user []: 
robot_password []:
```

Enter your robot-credentials if you did not already stored them in robot.conf.php.

```
Enabled Thin-Pool for Proxmox? (y,n) [n]:
```

With **y** the installer will generate a Thin-Pool:

```
Only one LV found - using pve
Use LV Name for Proxmox Thin-Pool - 'none' to skip [data]:
```

```
SSH Port [22]:
SSH PremitRootLogin [yes]:
```

You you should use the defaults for a Cluster-Setup.

```
Email to use with Let's Encrypt and in scripts [admin@local]:
```

```
Use Let's Encrypt for the Interface (y,n) [y]:
```

Choose **y** if you want a free ssl-cert from Let's Encrypt for the Backend.


```
Start Proxmox Install? (y,n) [y]:
```

Finally run the setup.


If your server is connected to a vSwicth:
```
This server is connected to the vswitch with the ID 4868 [4001]
Add the vswitch to the network-config? (y,n) [y]:
Use Private IP []:
Use Private IP []: 
Netmask [24]:
```
Choose a private IP like 10.0.0.1 for this server and set the netmask.

```
copy /etc/network/interfaces to /root/interfaces.save
writing new /etc/network/interfaces

Check the network-confg and reboot your server
Updating /etc/aliases
Adding your authorized_keys
```

Install finished. You can reboot the server now.

## Network-Setup
You can also use network-manual.php to generate a network-config on an existing server. 

*This will not overwrite your current setup.*

Run

```
php network-manual.php
```

and answer the questions. You find the generated config in /root/interfaces.generated


## Contributing
Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## Bugtracker
Visit our [issue tracker](https://git.schaal-it.com/florian/proxmox/issues).
