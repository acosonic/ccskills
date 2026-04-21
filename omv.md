# OMV Server Management

Manage a QNAP TS-251+ OpenMediaVault server via SSH.

## Arguments
- `$ARGUMENTS` — action to perform (see actions below)
  - Example: `status`, `scan`, `scan color`, `print test`, `reboot`, `shutdown`, `raid mount`, `raid stop`, `wifi check`, `jellyfin`, `deluge`, `sonarr`, `radarr`, `bazarr`, `clone`
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

**RTL8812BU driver bug**: after router reboot, the 88x2bu driver stays wedged — wpa_supplicant restart alone is NOT enough. `/usr/local/bin/wifi-reconnect.sh` handles this: first tries normal reconnect, if that fails does `modprobe -r 88x2bu && modprobe 88x2bu` and retries. Without this fallback, user has to physically unplug+replug the USB WiFi stick. Permanent fix is Ethernet cable to `enp3s0` (Intel I210, always reliable).

### `wol` (Wake-on-LAN via Ethernet)
Both onboard Intel I210 ports support WOL. Service `wol-enp3s0.service` persists the setting across reboots:
```bash
systemctl status wol-enp3s0.service
ethtool enp3s0 | grep -i wake   # should show "Wake-on: g"
```
From another host: `wakeonlan 24:5e:be:3b:e4:d5` (enp3s0 MAC — check current with `ip link show enp3s0`). **Does NOT work over USB WiFi** — USB loses power during sleep.

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
Jellyfin at http://OMV_IP:8096. Config: `/opt/jellyfin/config`, cache: `/opt/jellyfin/cache`. **VAAPI hardware acceleration enabled** (`HardwareAccelerationType=vaapi` in encoding.xml, `/dev/dri/renderD128` + `card0` devices). Verify with `docker exec jellyfin /usr/lib/jellyfin-ffmpeg/vainfo`.

**Critical: FUSE + Docker bind propagation.** Rclone mount is FUSE, so Docker bind mounts default `rprivate` propagation won't see submount contents — containers see empty directories. Always use `--mount type=bind,source=...,target=...,bind-propagation=slave` (and ensure `/mnt/seedhost` is `shared` on host: `mount --make-shared /mnt/seedhost`). If Jellyfin container shows empty `/media/tv` but host shows files, this is the cause — restart the container with slave propagation.

FFmpeg error 254 ("No such file or directory") with non-ASCII filenames (ć, š, etc.) is NOT an encoding issue — it's the FUSE propagation bug above.

### `deluge`
```bash
docker ps | grep deluge
docker logs deluge --tail 20
```
Deluge web UI at http://OMV_IP:8112 (default password `deluge`). Config: `/opt/deluge/config`. Downloads to `/downloads` (on system SSD). `.torrent` files live in `/opt/deluge/config/state/`. Stop the container before wiping `/downloads` to prevent auto re-download.

### `sonarr` / `radarr` / `bazarr`
Web UIs:
- Radarr: http://OMV_IP:7878 (movies, root folders `/movies`, `/marvel`, `/domaci_filmovi`, `/deciji_filmovi`, `/crtani_filmovi`)
- Sonarr: http://OMV_IP:8989 (series, root folders `/tv`, `/decije_serije`)
- Bazarr: http://OMV_IP:6767 (subtitles, integrates with Sonarr+Radarr)

API keys in `/opt/{sonarr,radarr}/config/config.xml` (`<ApiKey>` tag). Bazarr API key in `/opt/bazarr/config/config/config.yaml` under `auth.apikey`.

Bazarr **requires Sonarr/Radarr** — it doesn't scan filesystem directly. OpenSubtitles.com provider needs username (NOT email) + password + API key.

**Bulk library import**: use Radarr `/movie/lookup?term=...` or `/movie/lookup/imdb?imdbId=...` then POST `/movie`. Sonarr uses `/series/lookup`. Needed because no auto-scan of pre-existing folders. See `/tmp/import_movies.py` style scripts as reference.

### `clone` (bootable USB backup)
Plug in spare USB (≥32GB), check `lsblk` for new `/dev/sdX`. Procedure:
1. `docker stop $(docker ps -q)` — clean database snapshot
2. Partition MBR (system is BIOS mode, not UEFI):
   ```bash
   parted -s /dev/sdX mklabel msdos
   parted -s /dev/sdX mkpart primary ext4 1MiB 54GiB
   parted -s /dev/sdX mkpart primary linux-swap 54GiB 100%
   parted -s /dev/sdX set 1 boot on
   mkfs.ext4 -F -L OMV-CLONE /dev/sdX1
   mkswap -L OMV-SWAP /dev/sdX2
   ```
3. `rsync -aAXH / /mnt/clone/` excluding `/proc`, `/sys`, `/dev`, `/run`, `/tmp`, `/mnt`, `/media`, `/srv/dev-disk-by-uuid-*`, `/lost+found`, `/var/lib/docker/overlay2/*`, `/var/log/journal/*`, `/var/cache/apt/archives/*`
4. Update fstab on clone with new UUIDs (root + swap)
5. `chroot` into clone with bind `/dev /dev/pts /proc /sys /run`, then `grub-install --target=i386-pc /dev/sdX && update-grub`
6. Unmount, test by physically swapping disks (BIOS mode: only one USB SSD boots at a time — system is rooted on USB Intenso Portable SSD).

System disk is ~15GB without `/downloads`. USB speed matters — cheap sticks write 2 MB/s and clone takes hours; good SSD takes minutes.

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
| docker | enabled | Jellyfin, Deluge, Sonarr, Radarr, Bazarr |
| wifi-reconnect | cron hourly | Pings gateway; reloads 88x2bu driver if wpa_supplicant restart fails |
| wol-enp3s0 | enabled | Sets `ethtool wol g` on boot for WOL |
| md0-standby | enabled | Stops RAID if not mounted on boot |
| avahi-daemon | enabled | mDNS/printer discovery |

## Docker containers
| Name | Port | Config | Notes |
|------|------|--------|-------|
| jellyfin | 8096 | `/opt/jellyfin/config` | VAAPI, media via seedhost mounts with `bind-propagation=slave` |
| deluge | 8112 | `/opt/deluge/config` | Downloads to `/downloads` (SSD) |
| radarr | 7878 | `/opt/radarr/config` | Movie roots: `/movies`, `/marvel`, `/domaci_filmovi`, `/deciji_filmovi`, `/crtani_filmovi` |
| sonarr | 8989 | `/opt/sonarr/config` | Series roots: `/tv`, `/decije_serije` |
| bazarr | 6767 | `/opt/bazarr/config` | Needs Sonarr+Radarr; OpenSubtitles.com provider |
