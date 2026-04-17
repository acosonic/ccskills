# Claude Code Skills

A collection of [Claude Code](https://docs.anthropic.com/en/docs/claude-code) slash command skills for server administration and development tasks.

## Skills

| Skill | Description |
|---|---|
| [server-upgrade.md](server-upgrade.md) | Upgrades Ubuntu servers to the latest LTS release, disables unnecessary services, and hardens the firewall |
| [redmine.md](redmine.md) | Administers a Redmine instance on Ubuntu with Phusion Passenger |
| [cakephp2-security-analysis.md](cakephp2-security-analysis.md) | Comprehensive security audit for CakePHP 2.x applications |
| [omv.md](omv.md) | Manages an OpenMediaVault server via SSH |
| [wayland-detect.md](wayland-detect.md) | Auto-detects Wayland sessions for Lazarus/Qt5 applications |
| [qt5-to-qt6-wayland.md](qt5-to-qt6-wayland.md) | Qt5 to Qt6 migration with native Wayland support |
| [grub-hidpi-font.md](grub-hidpi-font.md) | Fix unreadable GRUB menu font on HiDPI/4K displays |
| [server-audit.md](server-audit.md) | Comprehensive server security audit after suspected compromise or spam attack |
| [vmware-esxi.md](vmware-esxi.md) | Manage VMware ESXi host over SSH — VM lifecycle, datastores, ghettoVCB backups, NVMe/cloud-init recovery |

## References

Patterns and best practices (not slash commands — used as context by Claude when relevant):

| Reference | Description |
|---|---|
| [references/crawler-tmp-cleanup.md](references/crawler-tmp-cleanup.md) | Prevent /tmp bloat with Playwright/Selenium/Camoufox crawlers |

## Installation

Copy any `.md` skill file to `~/.claude/commands/` and invoke it with `/<skill-name>` in Claude Code.

## Usage

```
/server-upgrade user@your-server-ip
/redmine
/cakephp2-security-analysis
/omv user@server-ip
/wayland-detect
/qt5-to-qt6-wayland
/grub-hidpi-font
/server-audit user@server-ip
/vmware-esxi status
```
