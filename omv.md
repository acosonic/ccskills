# OMV Server Management

Manage a QNAP TS-251+ OpenMediaVault server via SSH.

## Arguments
- `$ARGUMENTS` — action to perform (see actions below)
  - Example: `status`, `scan`, `scan color`, `print test`, `reboot`, `shutdown`, `raid mount`, `raid stop`, `wifi check`
  - If no arguments given, run a full status check

## Connection
```bash
sshpass -p 'YOUR_PASSWORD' ssh -o StrictHostKeyChecking=no root@OMV_IP
```
Always use `sshpass` for non-interactive SSH. Server connects via WiFi (TP-Link Archer T3U Plus, rtl88x2bu driver).

## CRITICAL RULES
- **NEVER run `apt upgrade` or `apt dist-upgrade`** — OMV manages packages, upgrades break it
- **NEVER upgrade the kernel** — pinned kernel version, DKMS drivers depend on it
- **Updates are disabled** — do not re-enable automatic updates
- **Kill hung processes** — `scanimage` and `hp-setup` can eat 100% CPU, always check with `ps aux | grep -E "scanimage|hp-setup"` and kill if stuck
- **USB printer reset** when scanner is stuck: `echo "1-2" > /sys/bus/usb/drivers/usb/unbind; sleep 3; echo "1-2" > /sys/bus/usb/drivers/usb/bind`

## Actions

### `status` (default)
Run all of these in parallel:
```bash
uptime
free -h | head -2
df -h / /mnt/seedhost 2>/dev/null
docker ps
ip addr show WIFI_INTERFACE | grep inet
lpstat -v 2>/dev/null
cat /proc/mdstat | head -5
hdparm -C /dev/sda /dev/sdb 2>/dev/null
systemctl is-active fancontrol-custom cups saned.socket rclone-seedhost docker
```

### `scan [color]`
Scan using hp-scan (NOT scanimage for color mode).

**Gray scan (default, reliable):**
```bash
su - scanuser -c "PYTHONPATH=/usr/share/hplip hp-scan -d hpaio:/usb/HP_LaserJet_MFP_M139-M142?serial=PRINTER_SERIAL -o /tmp/scan_$(date +%Y%m%d_%H%M%S).png"
```

**Color scan (use hp-scan only, scanimage hangs on color):**
```bash
su - scanuser -c "PYTHONPATH=/usr/share/hplip hp-scan -d hpaio:/usb/HP_LaserJet_MFP_M139-M142?serial=PRINTER_SERIAL -m color -o /tmp/scan_$(date +%Y%m%d_%H%M%S).png"
```

**From client PC over network:**
```bash
hp-scan -d net:OMV_IP:hpaio:/usb/HP_LaserJet_MFP_M139-M142?serial=PRINTER_SERIAL -o ~/Desktop/scan.png
```

After scanning, copy file to user's desktop with `scp` and display the image.
- hp-scan must run as `scanuser` (not root) on OMV
- Must set `PYTHONPATH=/usr/share/hplip`
- If scan fails with "Device busy", reset USB (see critical rules) or power cycle printer
- hp-scan has a patched bug (line 1673) — `size` variable undefined. Fix: `if im and page_size:` instead of `if im:`

### `scan network`
Scan from client PC directly using network SANE:
```bash
hp-scan -d net:OMV_IP:hpaio:/usb/HP_LaserJet_MFP_M139-M142?serial=PRINTER_SERIAL -o ~/Desktop/scan_$(date +%Y%m%d_%H%M%S).png
```
Note: local `/usr/bin/hp-scan` may need patching with `sys.path.insert(0, "/usr/share/hplip")` on line 2 and the `size` bug fix.

### `print <file>`
```bash
lp -h OMV_IP:631 -d HP_LaserJet_MFP <file>
```
Or from OMV: `lp -d HP_LaserJet_MFP <file>`

### `raid mount`
Mount from OMV GUI preferred (Storage > File Systems). CLI:
```bash
mdadm --assemble /dev/md0 2>/dev/null
mount /dev/md0 /srv/data
```

### `raid stop`
Stop array and spin down drives:
```bash
umount /dev/md0 2>/dev/null
mdadm --stop /dev/md0
hdparm -y /dev/sda
hdparm -y /dev/sdb
```

### `wifi check`
```bash
wpa_cli -i WIFI_INTERFACE status | grep -E "ssid|wpa_state"
ip addr show WIFI_INTERFACE | grep inet
ping -c 3 GATEWAY_IP
```
WiFi reconnect cron runs hourly. Config: `/etc/wpa_supplicant/wpa_supplicant-WIFI_INTERFACE.conf`.

### `reboot`
```bash
sshpass -p 'YOUR_PASSWORD' ssh -o StrictHostKeyChecking=no root@OMV_IP 'reboot'
```
Wait 90 seconds then verify with status check.

### `shutdown`
```bash
sshpass -p 'YOUR_PASSWORD' ssh -o StrictHostKeyChecking=no root@OMV_IP 'init 0'
```

### `fan`
Check fan status:
```bash
sensors | grep -E "fan|temp|Core"
cat /sys/devices/platform/f71882fg.656/pwm1
cat /sys/devices/platform/f71882fg.656/fan1_input
```
Custom fan control service: `fancontrol-custom.service`. Script: `/usr/local/bin/fancontrol-custom.sh`.

### `seedhost`
Check rclone mount:
```bash
df -h /mnt/seedhost
ls /mnt/seedhost/
systemctl status rclone-seedhost --no-pager | head -5
```
Config: rclone SFTP mount at `/mnt/seedhost`, auto-starts via systemd.

### `jellyfin`
```bash
docker ps | grep jellyfin
docker logs jellyfin --tail 20
```
Jellyfin at http://OMV_IP:8096. Media from seedhost mounted read-only. 4GB memory limit. Intel Quick Sync (QSV) enabled via /dev/dri.

## Hardware Reference
- **CPU**: Intel Celeron J1900 (Bay Trail, 4 cores)
- **RAM**: 8GB
- **Boot**: Portable SSD (USB, ~465G root + 4G swap)
- **Storage**: 2x 14TB RAID1 (md0)
- **WiFi**: TP-Link Archer T3U Plus, ASUS USB-N13 (backup)
- **Ethernet**: 2x Intel I210 (DHCP when plugged in)
- **Fan**: Fintek F71869A, custom fan control service
- **Printer**: HP LaserJet MFP M139-M142 (USB), HPLIP compiled from source
- **eUSB DOM**: Disabled in BIOS, not accessible

## Services
| Service | Status | Notes |
|---------|--------|-------|
| fancontrol-custom | enabled | Custom fan curve |
| cups | enabled | HP printer shared, AirPrint |
| saned.socket | enabled | Network scanning on port 6566 |
| rclone-seedhost | enabled | SFTP mount from seedbox |
| docker | enabled | Jellyfin container |
| wifi-reconnect | cron hourly | Reconnects if AP is off |
| md0-standby | enabled | Stops RAID if not mounted on boot |
| avahi-daemon | enabled | mDNS/printer discovery |
