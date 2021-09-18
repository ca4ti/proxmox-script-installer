# /etc/network/interfaces

### generated using Proxmox-Setup Tool {tmpl_var name="version"} from schaal @it UG
### https://schaal-it.com/script-to-install-proxmox-5-x-and-6-x-on-a-dedicated-hetzner-server/
###
### Network-Type {tmpl_var name="network_type"}

# loopback device
auto lo
iface lo inet loopback
<tmpl_if name='network_type' op='==' value='routed'>
iface lo inet6 loopback

# network device - main ip
auto {tmpl_var name="nic"}
iface {tmpl_var name="nic"} inet static
	address		{tmpl_var name="server_ip"}
	netmask		255.255.255.255
	pointopoint	{tmpl_var name="gateway"}
	gateway		{tmpl_var name="gateway"}
	up		sysctl -p
	post-up		ip address add {tmpl_var name="server_ipv6"}/128 dev {tmpl_var name="nic"}
	post-up		ip route add default via fe80::1 dev {tmpl_var name="nic"}

</tmpl_if>
<tmpl_if name='network_type' op='==' value='bridged'>

auto vmbr0
iface vmbr0 inet static
	address		{tmpl_var name="server_ip"}
	netmask		255.255.255.255
	pointopoint	{tmpl_var name="gateway"}
	gateway		{tmpl_var name="gateway"}
	bridge_ports	{tmpl_var name="nic"}
	bridge_stp	off
	bridge_fd	1
	bridge_hello	2
	bridge_maxage	12

</tmpl_if>
<tmpl_loop name="vlan_devices">
# vlan raw device
auto {tmpl_var name="nic"}.{tmpl_var name="vlan_id"}
iface {tmpl_var name="nic"}.{tmpl_var name="vlan_id"} inet static
	vlan-raw-device	{tmpl_var name="nic"}
	mtu		1400
	address		0.0.0.0
	netmask		0.0.0.0
# vlan
auto vmbr{tmpl_var name="vlan_id"}
iface vmbr{tmpl_var name="vlan_id"} inet static
	address		{tmpl_var name="ip"}
	netmask		{tmpl_var name="mask"}
	bridge_ports	{tmpl_var name="nic"}.{tmpl_var name="vlan_id"}
	bridge_stp	off
	bridge_fd	0

</tmpl_loop>
<tmpl_loop name='local_ips'>
<tmpl_if name='ip'>
# server-ips - additional ips / subnets
auto vmbr{tmpl_var name="vmbr_id"}
iface vmbr{tmpl_var name="vmbr_id"} inet static
	address		{tmpl_var name="ip"}
	netmask		{tmpl_var name="mask"}
	bridge_ports	none
	bridge_stp	off
	bridge_fd	0
<tmpl_loop name='single_ips'>
	up ip route add	{tmpl_var name="single_ip"}/32 dev vmbr{tmpl_var name="vmbr_id"}
</tmpl_loop>

<tmpl_if name='ip_v6'>
iface vmbr{tmpl_var name="vmbr_id"} inet6 static
	address		{tmpl_var name="ip_v6"}
	netmask		{tmpl_var name="mask_v6"}

</tmpl_if>	
</tmpl_if>	
</tmpl_loop>

