# Claude Code Skills

A collection of [Claude Code](https://docs.anthropic.com/en/docs/claude-code) slash command skills for server administration and development tasks.

## Skills

| Skill | Description |
|---|---|
| [server-upgrade.md](server-upgrade.md) | Upgrades Ubuntu servers to the latest LTS release, disables unnecessary services, and hardens the firewall |
| [redmine.md](redmine.md) | Administers a Redmine instance on Ubuntu with Phusion Passenger |
| [cakephp2-security-analysis.md](cakephp2-security-analysis.md) | Comprehensive security audit for CakePHP 2.x applications |
| [cakephp2-deep-audit.md](cakephp2-deep-audit.md) | Deeper CakePHP 2.x audit with concrete patterns from real-world findings (statična Security.salt, AutoLogin cookie, XXE, weak tokens) — outputs `security_nalaz.md` with P0–P3 priority report |
| [omv.md](omv.md) | Manages an OpenMediaVault server via SSH |
| [wayland-detect.md](wayland-detect.md) | Auto-detects Wayland sessions for Lazarus/Qt5 applications |
| [qt5-to-qt6-wayland.md](qt5-to-qt6-wayland.md) | Qt5 to Qt6 migration with native Wayland support |
| [grub-hidpi-font.md](grub-hidpi-font.md) | Fix unreadable GRUB menu font on HiDPI/4K displays |
| [server-audit.md](server-audit.md) | Comprehensive server security audit after suspected compromise or spam attack |
| [vmware-esxi.md](vmware-esxi.md) | Manage VMware ESXi host over SSH — VM lifecycle, datastores, ghettoVCB backups, NVMe/cloud-init recovery |
| [corp-webapp/](corp-webapp/SKILL.md) | House standard for internal Laravel web apps: ready starter with shadcn-style Bootstrap theme (light/dark, generic "Generic Corp" branding), Active Directory login + AD user import, mandatory driver.js tutorial, modals/side sheets, sortable tables, collapsible cards, CARTO maps, Docker + Caddy https. Patterns: preview for every file type (.doc → PDF via LibreOffice, Outlook .msg and ZIP in pure PHP), in-app approval/signature instead of "I agree" e-mails, admin SMTP settings, Word documents filled from the organisation's own templates. Optional module: AI assistant as an agent (Claude + tools over the app's data with the user's permissions, changes only after the user confirms, public website assistant) — `scripts/add-ai-agent.sh` |
| [demo-video/](demo-video/SKILL.md) | Narrated demo video of a web app: Playwright walks through the real application, a text-to-speech narrator explains each scene, title cards open and close it (what it is, what you will see, recap), quiet music ducks under the voice. Scenes are paced to the voice clips; scripts for speech, music, mixing and for checking audio you cannot hear |
| [mi-smart-clock-hass-kiosk.md](mi-smart-clock-hass-kiosk.md) | Convert Xiaomi Mi Smart Clock (X04G) into a Home Assistant kiosk — backup, bootloader unlock, LineageOS 20 GSI flash, Fully Kiosk + Cyrillic dashboard |
| [lineageos-install.md](lineageos-install.md) | Generic LineageOS install playbook for any supported phone/tablet — pre-flight, bootloader unlock, recovery flash, ROM + GApps/microG sideload, common gotchas |
| [web-credential-vault.md](web-credential-vault.md) | Playbook for client-side encrypted credential storage in PHP+JS: bcrypt auth, AES-256-GCM vault, PBKDF2, roles, audit log, global rekey, and the five pitfalls we hit in production |
| [webapp-passkey.md](webapp-passkey.md) | Add passwordless **passkey / WebAuthn** login (Face ID, Touch ID, Windows Hello, phone) to a Laravel app so users skip TOTP. laragear/webauthn v5 recipe (eloquent-webauthn driver, trait, published migration), passwordless login that bypasses the password→TOTP flow, resident/userless keys, /passkeys enrol+delete, @laragear/webpass JS. Keeps password+TOTP as fallback. Nine production pitfalls: domain/RP-ID churn, CF-proxy vs IP allowlist, port in origins, missing namespace imports (unlogged 500), CSRF-exempt ceremonies, is_active callback |
| [evolution-mail/](evolution-mail/SKILL.md) | Read, search and answer mail in GNOME Evolution straight from its local cache (Exchange/EWS, IMAP, local): list/search messages, show bodies, extract attachments (ZIPs with non-ASCII names), turn PDF/DOC/DOCX/XLSX into text for review, cross-check e-mailed contracts and tender documents, and reply through a pre-filled Evolution composer (To/Cc, body, attachments, In-Reply-To threading) that the user sends with one click |

## References

Patterns and best practices (not slash commands — used as context by Claude when relevant):

| Reference | Description |
|---|---|
| [references/crawler-tmp-cleanup.md](references/crawler-tmp-cleanup.md) | Prevent /tmp bloat with Playwright/Selenium/Camoufox crawlers |

## Installation

Copy any `.md` skill file to `~/.claude/commands/` and invoke it with `/<skill-name>` in Claude Code.

Folder skills (with `SKILL.md`, scripts and templates, e.g. `corp-webapp/`, `demo-video/`) go to `~/.claude/skills/`:

```bash
cp -r corp-webapp demo-video evolution-mail ~/.claude/skills/
```

Claude Code picks them up automatically when the task matches the skill description (or call `/corp-webapp`).

## Usage

```
/server-upgrade user@your-server-ip
/redmine
/cakephp2-security-analysis
/cakephp2-deep-audit
/omv user@server-ip
/wayland-detect
/qt5-to-qt6-wayland
/grub-hidpi-font
/server-audit user@server-ip
/vmware-esxi status
/mi-smart-clock-hass-kiosk
/lineageos-install
/web-credential-vault
/webapp-passkey
```
