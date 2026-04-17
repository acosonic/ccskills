# VMware ESXi Management

Manage a VMware ESXi host over SSH. Covers VM lifecycle (power/clone/migrate), datastore management, disk health, ghettoVCB backups, and recovery from common failures (NVMe disconnect, VMFS lock race, guest cloud-init hang).

## Arguments
- `$ARGUMENTS` — action to perform (see actions below)
  - Examples: `status`, `vms`, `poweroff all`, `poweron 36`, `backup`, `check-backup`, `disk-health`, `vm-info 36`, `fix-cloudinit 36`
  - If no arguments given, run `status`

## Connection

Set host details from environment, a secrets manager, or prompt the user. Never commit credentials to the repo:
```bash
ESXI_HOST=<esxi-host-or-ip>
ESXI_USER=root
ESXI_PASS=<stored-securely>    # e.g. $(pass show esxi/root) or read -s
```

Always use sshpass for non-interactive SSH (or prefer SSH keys where possible):
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $ESXI_USER@$ESXI_HOST "<command>"
```

## CRITICAL RULES

- **Never use the ESXi GUI for maintenance-mode or shutdown if it's timing out** — go straight to SSH. GUI shutdowns silently queue against failed I/O and leave operations stuck.
- **Hard `power.off` when datastore is offline** — graceful `power.shutdown` hangs forever if guest can't do disk I/O. See the disk failure playbook below.
- **Never reboot the host without verifying the boot partition is on a healthy disk.** On OVH servers with 2 NVMe and no RAID, both ESXi system partitions AND datastore often live on the same disk. Check `esxcli storage core device list` and `partedUtil getptbl` before rebooting — see the reboot-safety check.
- **Backup via `run-backup.sh` wrapper, NOT `ghettoVCB.sh` with full VM list.** Parallel snapshot creation causes a VMFS lock race — every other VM fails. The wrapper backs up VMs one at a time with a 15s pause.
- **Do not trust `ADAPTER_FORMAT=buslogic`** in ghettoVCB.conf — most modern VMs use `lsilogic`. Wrong adapter format makes snapshot creation fail on some VMs with `Extra arguments at the end of the command line`.
- **Cron on ESXi resets on reboot.** Any cron entry must also be added to `/etc/rc.local.d/` via a persistence script, AND `auto-backup.sh` must be run to commit changes to the bootbank.

## Context to gather first

When invoked, run this once to get oriented:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
vmware -vl
echo '---'
esxcli storage filesystem list | grep -v '^$'
echo '---'
vim-cmd vmsvc/getallvms | head -30
"
```

---

## Actions

### `status` (default)
Fast overall health snapshot:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
echo '=== Host ==='
uptime
vmware -vl
echo
echo '=== Datastores ==='
esxcli storage filesystem list
echo
echo '=== Physical disks ==='
esxcli storage core device list | grep -E 'Display Name|Status|Is Offline' | head -20
echo
echo '=== VMs (power state) ==='
for vmid in \$(vim-cmd vmsvc/getallvms 2>/dev/null | awk 'NR>1 && \$1 ~ /^[0-9]+\$/ {print \$1}'); do
    name=\$(vim-cmd vmsvc/get.config \$vmid 2>/dev/null | grep -m1 'name = ' | sed 's/.*\"\(.*\)\",/\1/')
    state=\$(vim-cmd vmsvc/power.getstate \$vmid 2>/dev/null | tail -1)
    echo \"\$vmid | \$state | \$name\"
done
"
```

### `vms` — list all VMs with VMID, name, datastore path
```bash
vim-cmd vmsvc/getallvms
```

### `vm-info <vmid>`
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
echo '=== Power state ==='
vim-cmd vmsvc/power.getstate $1
echo
echo '=== Guest (IP, tools, OS) ==='
vim-cmd vmsvc/get.guest $1 2>&1 | grep -E 'ipAddress|hostName|toolsRunningStatus|guestState|guestFullName' | head -10
echo
echo '=== Snapshots ==='
vim-cmd vmsvc/get.snapshotinfo $1 2>&1 | head -10
"
```

### `poweroff <vmid | all>`
Hard power off (use when graceful is hung). Sequential with short delay:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
if [ '$1' = 'all' ]; then
    VMIDS=\$(vim-cmd vmsvc/getallvms 2>/dev/null | awk 'NR>1 && \$1 ~ /^[0-9]+\$/ {print \$1}')
else
    VMIDS='$1'
fi
for vmid in \$VMIDS; do
    state=\$(vim-cmd vmsvc/power.getstate \$vmid 2>/dev/null | tail -1)
    if echo \"\$state\" | grep -q 'Powered on'; then
        echo \"--- Hard power-off VMID \$vmid ---\"
        vim-cmd vmsvc/power.off \$vmid 2>&1
    fi
done
"
```

For graceful shutdown (when guests are responsive), use `vim-cmd vmsvc/power.shutdown <vmid>` instead — requires working VMware Tools.

### `poweron <vmid | all>`
Sequential with 3s delay (gentler on post-outage disk):
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
if [ '$1' = 'all' ]; then
    VMIDS=\$(vim-cmd vmsvc/getallvms 2>/dev/null | awk 'NR>1 && \$1 ~ /^[0-9]+\$/ {print \$1}')
else
    VMIDS='$1'
fi
for vmid in \$VMIDS; do
    vim-cmd vmsvc/power.on \$vmid 2>&1 | tail -1
    sleep 3
done
"
```

Note: Initial `power.on` may return `Power on failed` while `power.getstate` says `Powered off` — this is a race with hostd. If you see `VM is already running` in hostd.log, the VM actually started. Verify with `power.getstate`.

### `cycle <vmid>`
```bash
vim-cmd vmsvc/power.off <vmid>; sleep 3; vim-cmd vmsvc/power.on <vmid>
```

### `disk-health`
SMART data + device status for all physical disks:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
echo '=== Devices ==='
esxcli storage core device list | grep -E '^(eui|naa|t10)|Display Name|Status|Is Offline' | head -40
echo
echo '=== Error stats ==='
esxcli storage core device stats get 2>&1
echo
echo '=== NVMe SMART ==='
for hba in \$(esxcli storage core adapter list 2>/dev/null | awk '/vmhba.*NVMe/ {print \$1}'); do
    echo \"--- \$hba ---\"
    esxcli nvme device log smart get -A \$hba 2>&1 | head -25
done
"
```

### `backup`
Run full backup NOW via the one-at-a-time wrapper (avoids VMFS lock race). Logs to `/var/log/ghettoVCB.log`:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
nohup /vmfs/volumes/datastore2/ghettoVCB/run-backup.sh > /tmp/ghettoVCB.out 2>&1 &
echo 'Backup started. PID:' \$!
echo 'Monitor with: tail -f /var/log/ghettoVCB.log'
"
```

### `check-backup`
See last backup results:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
echo '=== Last run summary ==='
grep -E 'BACKUP RUN|Successfully completed|ERROR|Final status' /var/log/ghettoVCB.log | tail -40
echo
echo '=== Backup sizes ==='
du -sh /vmfs/volumes/datastore2/backups/*/* 2>/dev/null | sort -k2
echo
echo '=== Datastore2 free ==='
df -h /vmfs/volumes/datastore2 | tail -1
"
```

### `fix-cloudinit <vmid>`
Applied when an Ubuntu guest hangs at `Starting Cloud-init: Network Stage...` and never reaches login. Requires guest SSH access (after one-time manual recovery — see Recovery Playbook). Persists across reboots.
```bash
# Run INSIDE the guest VM (via SSH):
sudo touch /etc/cloud/cloud-init.disabled
sudo systemctl mask cloud-init.service cloud-init-local.service cloud-config.service cloud-final.service
```
Verify with `systemctl is-enabled cloud-init.service` (should say `masked`) and `ls -la /etc/cloud/cloud-init.disabled`.

Network continues to work because on Subiquity-installed Ubuntu, netplan config is in `/etc/netplan/00-installer-config.yaml` and is applied by systemd-networkd — cloud-init is not in the critical path.

### `reboot-safety-check`
Before rebooting the host, verify the boot partition is on a healthy disk:
```bash
sshpass -p "$ESXI_PASS" ssh -o StrictHostKeyChecking=no root@$ESXI_HOST "
echo '=== What disk holds bootbank? ==='
readlink -f /bootbank
esxcli storage vmfs extent list
echo
echo '=== Disk statuses ==='
esxcli storage core device list | grep -E 'Display Name|^   Status' | paste - -
echo
echo '=== Partition tables ==='
for d in /vmfs/devices/disks/eui.* /vmfs/devices/disks/naa.*; do
    [ -e \$d ] || continue
    echo \"--- \$d ---\"
    partedUtil getptbl \$d 2>&1 | head -3
done
"
```
If bootbank is on a disk with `Status: not connected`, **do not reboot** without OVH rescue mode ready.

---

## Recovery Playbooks

### Disk failed / datastore disappeared mid-run
Symptoms: Datastore shows `Mounted: false` or Size/Free=0; `/vmfs/volumes/<uuid>` missing; `/var/log/vmkernel.log` returns I/O errors; GUI maintenance-mode times out.

1. Hard power off all VMs: `poweroff all` — graceful will hang.
2. Check which disk failed: `esxcli storage core device list` — look for `Status: not connected`.
3. Check where boot partitions live: `readlink -f /bootbank`. If pointing to the failed disk, ESXi is running purely from RAM and a reboot may not recover.
4. If boot is on the failed disk: open OVH ticket, request KVM/rescue mode BEFORE rebooting.
5. If boot is on a healthy disk OR you're ready for recovery: `reboot -f` (not `esxcli system shutdown reboot` — that requires maintenance mode, which is failing).
6. After reboot comes back: verify `esxcli storage filesystem list` shows datastore mounted, check SMART for the resurrected disk (`Percentage Used`, `Media Errors`, `Error Info Log Entries`). A transient NVMe controller hang usually leaves SMART clean — but keep the replacement ticket open anyway.

### VMFS snapshot lock race during backup
Symptoms: Every other VM fails with `Failed to lock the file (16392)` or `Extra arguments at the end of the command line`; successful/failed pattern alternates through the VM list.

Cause: parallel snapshot create/remove operations hit VMFS metadata lock.

Fix: use the wrapper script that backs up VMs sequentially with pauses — `/vmfs/volumes/datastore2/ghettoVCB/run-backup.sh`. This is what the cron job uses.

One-at-a-time recovery for leftover failed VMs:
```bash
for vm in "Failed VM 1" "Failed VM 2"; do
    echo "$vm" > /tmp/single.list
    /vmfs/volumes/datastore2/ghettoVCB/ghettoVCB.sh -f /tmp/single.list -g /vmfs/volumes/datastore2/ghettoVCB/ghettoVCB.conf
    sleep 15
done
```

### Guest VM stuck at Cloud-init Network Stage (Ubuntu)
Symptoms: VM shows `Starting Cloud-init: Network Stage...` indefinitely on console; no IP reported to VMware Tools; VM can't be SSH'd into.

1. User interrupts GRUB (hold Shift at boot), presses `e` on Ubuntu entry, appends `cloud-init=disabled` to the `linux` line, Ctrl+X to boot.
2. Once logged in, SSH from this host to apply persistent fix (see `fix-cloudinit <vmid>` action above).

---

## Initial Setup (one-time)

### Create a second datastore on an empty NVMe
```bash
# Replace DEVICE with the empty disk's eui.* identifier
DEVICE=/vmfs/devices/disks/eui.xxxx
partedUtil mklabel $DEVICE gpt
partedUtil getUsableSectors $DEVICE  # note last sector, e.g. 1875384974
partedUtil setptbl $DEVICE gpt "1 2048 <last-sector> AA31E02A400F11DB9590000C2911D1B8 0"
vmkfstools -C vmfs6 -S datastore2 ${DEVICE}:1
```

### Install ghettoVCB on ESXi 6.7 (script-based, not VIB)
```bash
mkdir -p /vmfs/volumes/datastore2/ghettoVCB /vmfs/volumes/datastore2/backups
cd /vmfs/volumes/datastore2/ghettoVCB
wget -q https://raw.githubusercontent.com/lamw/ghettoVCB/master/ghettoVCB.sh
chmod +x ghettoVCB.sh
```

For ESXi 8.x, install the VIB: `https://github.com/lamw/ghettoVCB/releases/latest`

### ghettoVCB.conf (proven working on 6.7)
```
VM_BACKUP_VOLUME=/vmfs/volumes/datastore2/backups
DISK_BACKUP_FORMAT=thin
VM_BACKUP_ROTATION_COUNT=2
POWER_VM_DOWN_BEFORE_BACKUP=0
ENABLE_HARD_POWER_OFF=0
ITER_TO_WAIT_SHUTDOWN=3
POWER_DOWN_TIMEOUT=5
ENABLE_COMPRESSION=0
ADAPTER_FORMAT=lsilogic
VM_SNAPSHOT_MEMORY=0
VM_SNAPSHOT_QUIESCE=0
ALLOW_VMS_WITH_SNAPSHOTS_TO_BE_BACKEDUP=0
VMDK_FILES_TO_BACKUP=all
RSYNC_LINK=0
EMAIL_LOG=0
VM_BACKUP_DIR_NAMING_CONVENTION="$(date +%Y-%m-%d_%H-%M-%S)"
```

### One-at-a-time wrapper (`run-backup.sh`)
Mandatory — never call `ghettoVCB.sh` directly with a full VM list:
```sh
#!/bin/sh
GHETTO=/vmfs/volumes/datastore2/ghettoVCB
LOG=/var/log/ghettoVCB.log
TMPLIST=/tmp/ghettoVCB-single.list

echo "===== $(date) BACKUP RUN START =====" >> $LOG
while IFS= read -r vm; do
    [ -z "$vm" ] && continue
    echo "$vm" > $TMPLIST
    $GHETTO/ghettoVCB.sh -f $TMPLIST -g $GHETTO/ghettoVCB.conf -l $LOG 2>&1
    sleep 15
done < $GHETTO/vms_to_backup
rm -f $TMPLIST
echo "===== $(date) BACKUP RUN END =====" >> $LOG
```

### Cron + persistence across reboots
Cron on ESXi is wiped on boot. Add the job AND a persistence hook:

```bash
# Add cron entry
echo '0 2 * * * /vmfs/volumes/datastore2/ghettoVCB/run-backup.sh' >> /var/spool/cron/crontabs/root
kill $(pidof crond); /bin/crond

# Persistence on boot
cat > /etc/rc.local.d/ghettovcb-cron.sh <<'EOF'
#!/bin/sh
CRONLINE='0 2 * * * /vmfs/volumes/datastore2/ghettoVCB/run-backup.sh'
if ! grep -qF 'run-backup.sh' /var/spool/cron/crontabs/root; then
    echo "$CRONLINE" >> /var/spool/cron/crontabs/root
    kill $(pidof crond) 2>/dev/null
    /bin/crond
fi
EOF
chmod +x /etc/rc.local.d/ghettovcb-cron.sh

# Commit to bootbank
/sbin/auto-backup.sh
```

---

## Useful one-liners

```bash
# Get all VM IPs (from VMware Tools)
for vmid in $(vim-cmd vmsvc/getallvms | awk 'NR>1 && $1 ~ /^[0-9]+$/ {print $1}'); do
    name=$(vim-cmd vmsvc/get.config $vmid | grep -m1 'name = ' | sed 's/.*"\(.*\)",/\1/')
    ip=$(vim-cmd vmsvc/get.guest $vmid 2>/dev/null | grep -m1 'ipAddress = "' | sed 's/.*"\(.*\)".*/\1/')
    echo "$vmid | $ip | $name"
done

# Watch active backup
tail -f /var/log/ghettoVCB.log

# Total backup footprint
du -sh /vmfs/volumes/datastore2/backups/

# Which disk is a VM on
vim-cmd vmsvc/get.datastores <vmid>

# Force-clear a stuck snapshot
vim-cmd vmsvc/snapshot.removeall <vmid>
```
