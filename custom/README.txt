This script will add some files / dirs inside the custom-dir:

ssh/authorized_keys		add 		to /root/.ssh/authorized_keys
etc/aliaseses			add 		to /etc/aliases
root/*				copy 		to /root
etc/cron.d/*			copy 		to /etc/cron.d
etc/sysctl.d/*.conf		copy/add 	to /etc/sysctl.d (if the target-file exists, the source-content will be added)

In all files _EMAIL_ will be replaced with the data you entered during the install

You can find examples inside the directories. To use the examples, just rename them from "a.example" to "a"

If you need more placeholders (currently it's just _EMAIL_), copy replace.conf.php.example to replace.conf.php and ADD your search & replace values.
