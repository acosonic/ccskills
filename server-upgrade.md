# Server Upgrade & Firewall Hardening (virtualmin on ubuntu 20->24)

A [Claude Code](https://docs.anthropic.com/en/docs/claude-code) slash command skill that upgrades Ubuntu servers to the latest LTS release, disables unnecessary services, and hardens the firewall. Handles the full upgrade chain (e.g. 20.04 -> 22.04 -> 24.04).

## Installation

Copy this file to `~/.claude/commands/server-upgrade.md`, then invoke with:

```
/server-upgrade user@your-server-ip
```

## Arguments
- `$ARGUMENTS` — space-separated: `<user@host> [user@host2] ...`
  - Example: `ubuntu@203.0.113.10 ubuntu@203.0.113.20`
  - Process servers sequentially (one at a time).

## Pre-flight checks

SSH into the server and gather:
```bash
lsb_release -a                              # Current OS version
uname -r                                    # Current kernel
df -h /                                     # Disk space (need >5GB free)
ls /etc/apt/sources.list.d/                 # Third-party repos
cat /var/run/reboot-required 2>/dev/null    # Pending reboot
```

## Step 1: Free disk space

Disk space is often tight on old servers. Clean up in this order:

### Check large dirs
```bash
sudo du -sh /var/log/* 2>/dev/null | sort -rh | head -10
```

### Remove old kernels (keep current + one previous)
```bash
CURRENT=$(uname -r | sed 's/-generic//')
dpkg --list | grep linux-image | grep -v $CURRENT | grep -v linux-image-generic | awk '{print $2}'
# Purge them:
dpkg --list | grep 'linux-image-' | grep -v $CURRENT | grep -v 'linux-image-generic' | awk '{print $2}' | xargs sudo DEBIAN_FRONTEND=noninteractive apt-get -y purge
```

### Clean logs
```bash
sudo journalctl --vacuum-size=100M
# Truncate massive log files (e.g. proftpd, btmp)
sudo truncate -s 0 /var/log/btmp.1 /var/log/btmp 2>/dev/null
```

### Clean apt cache
```bash
sudo apt-get -y clean
sudo apt-get -y autoremove
```

Target: at least 5GB free before starting upgrade.

## Step 2: Disable third-party repos

Move problematic repos out of the way (expired GPG keys cause failures):
```bash
# Disable any third-party repos that fail apt update
for f in /etc/apt/sources.list.d/*.list; do
  sudo mv "$f" "$f.disabled" 2>/dev/null
done
```

## Step 3: Disable unnecessary services

### Email services (disable before upgrade to avoid config prompts)
```bash
sudo systemctl disable --now dovecot postfix opendkim postgrey milter-greylist lookup-domain spamassassin 2>&1
```

### MySQL/MariaDB (only if no user databases)
Check first:
```bash
# Try debian-sys-maint credentials from /etc/mysql/debian.cnf
sudo mysql -u debian-sys-maint -p$(sudo grep password /etc/mysql/debian.cnf | head -1 | awk '{print $3}') -e 'SHOW DATABASES;'
```
If only system databases (information_schema, mysql, performance_schema, sys), disable:
```bash
sudo systemctl disable --now mysql
```

### ProFTPd (commonly breaks during upgrade)
```bash
sudo DEBIAN_FRONTEND=noninteractive apt-get -y remove --purge proftpd-basic proftpd-core proftpd-mod-crypto proftpd-mod-wrap 2>/dev/null
```

## Step 4: Update current release
```bash
sudo DEBIAN_FRONTEND=noninteractive apt-get -y update
sudo DEBIAN_FRONTEND=noninteractive apt-get -y dist-upgrade
sudo apt-get -y install update-manager-core
```

## Step 5: Reboot if required
```bash
cat /var/run/reboot-required 2>/dev/null
# If reboot required:
sudo reboot
# Wait ~40 seconds, then reconnect
```

## Step 6: Run release upgrade
```bash
sudo DEBIAN_FRONTEND=noninteractive do-release-upgrade -f DistUpgradeViewNonInteractive
```

### Handling config file prompts
The non-interactive mode sometimes still blocks on dpkg config prompts. If the upgrade appears stuck:
1. SSH may move to port 1022 during upgrade — try both ports
2. Find the dpkg process: `ps aux | grep 'dpkg.*pending' | grep -v grep | awk '{print $2}'`
3. Send newline to accept default: `echo '' | sudo tee /proc/<PID>/fd/0`

### After upgrade completes
```bash
lsb_release -a                              # Verify new version
sudo DEBIAN_FRONTEND=noninteractive dpkg --configure -a
sudo DEBIAN_FRONTEND=noninteractive apt-get -y -f install
sudo DEBIAN_FRONTEND=noninteractive apt-get -y autoremove
```

## Step 7: Repeat for next LTS

If not yet on 24.04, reboot into new kernel and repeat Steps 4-6 for the next hop:
- 20.04 -> 22.04 -> 24.04

After each hop:
1. Fix broken packages (dpkg --configure -a, apt -f install)
2. Remove proftpd if it broke again
3. Reboot and verify kernel and OS version

## Step 8: Regenerate initramfs (important for LVM systems)

After all kernel changes, ensure initramfs is correct (prevents kernel panic on LVM root):
```bash
sudo update-initramfs -u -k all
sudo update-grub
```

## Step 9: Firewall hardening (firewalld + nftables on 24.04)

### Check current state
```bash
sudo systemctl status firewalld
sudo firewall-cmd --get-active-zones
sudo firewall-cmd --list-all
```

### Set up trusted zone for SSH-only access
Add trusted IPs (the IPs that should have full access including SSH):
```bash
# Replace with your actual management/office IPs
sudo firewall-cmd --zone=trusted --add-source=203.0.113.10 --permanent    # Example: office IP
sudo firewall-cmd --zone=trusted --add-source=198.51.100.0/24 --permanent # Example: VPN range
# Repeat for each trusted IP/CIDR
```

### Remove SSH from public zone
**CRITICAL: Verify your current IP is in the trusted zone BEFORE removing SSH from public!**
```bash
# Check your connection source:
ss -tnp | grep ':22 '
# Confirm that IP is in trusted zone, then:
sudo firewall-cmd --zone=public --remove-service=ssh --permanent
```

### Remove email ports from public zone (if email disabled)
```bash
sudo firewall-cmd --zone=public --remove-service=imap --permanent
sudo firewall-cmd --zone=public --remove-service=imaps --permanent
sudo firewall-cmd --zone=public --remove-service=pop3 --permanent
sudo firewall-cmd --zone=public --remove-service=pop3s --permanent
sudo firewall-cmd --zone=public --remove-service=smtp --permanent
sudo firewall-cmd --zone=public --remove-service=smtps --permanent
sudo firewall-cmd --zone=public --remove-port=587/tcp --permanent
```

### Block admin panels (if not needed publicly)
```bash
sudo firewall-cmd --zone=public --add-rich-rule='rule family="ipv4" port port="10000" protocol="tcp" reject' --permanent
sudo firewall-cmd --zone=public --add-rich-rule='rule family="ipv4" port port="20000" protocol="tcp" reject' --permanent
```

### Open only what you need in the public zone
Typical web server setup:
```bash
# These are usually already present — verify and add if missing:
sudo firewall-cmd --zone=public --add-service=http --permanent
sudo firewall-cmd --zone=public --add-service=https --permanent
```

### Apply and verify
```bash
sudo firewall-cmd --reload
sudo firewall-cmd --zone=public --list-all
sudo firewall-cmd --zone=trusted --list-all
```

Expected public zone result: `services: dhcpv6-client http https` (no ssh, no mail)
Expected trusted zone result: your management IPs with full access

## Step 10: Final cleanup and reboot
```bash
# Ensure all email/unnecessary services stay disabled after upgrade
sudo systemctl disable --now dovecot postfix opendkim postgrey milter-greylist mysql spamassassin lookup-domain 2>&1
# Final reboot
sudo reboot
```

## Post-reboot verification
```bash
lsb_release -a                              # Ubuntu 24.04.x LTS
uname -r                                    # 6.8.x kernel
uptime                                      # Just booted
df -h /                                     # Disk usage
sudo firewall-cmd --list-all                # Public zone (no ssh)
sudo firewall-cmd --zone=trusted --list-all # Trusted zone (with IPs)
systemctl list-units --type=service --state=running | grep -iE 'postfix|dovecot|mysql|mail|opendkim|postgrey'  # Should be empty
```

## Common issues
- **Kernel panic: VFS cannot open root device "mapper/..."** — Initramfs missing LVM modules. Boot older kernel from GRUB Advanced Options, then run `sudo update-initramfs -u -k all && sudo update-grub`
- **ProFTPd breaks every upgrade hop** — Just purge it before upgrading: `apt-get -y remove --purge proftpd-basic proftpd-core proftpd-mod-crypto proftpd-mod-wrap`
- **SSH unreachable during upgrade** — Try port 1022 (upgrade spawns backup sshd there)
- **dpkg stuck on config prompt** — Find PID and send newline: `echo '' | sudo tee /proc/<PID>/fd/0`
- **Disk too full for upgrade** — Purge old kernels, clean /var/log, `apt-get clean`
- **UFW vs firewalld** — Ubuntu 24.04 has both. firewalld takes precedence. Use firewall-cmd, not ufw
- **Locked out of SSH** — Always verify your IP is in trusted zone before removing SSH from public
