# Claude Code Skills

A collection of [Claude Code](https://docs.anthropic.com/en/docs/claude-code) slash command skills for server administration tasks.

## Skills

| Skill | Description |
|---|---|
| [server-upgrade.md](server-upgrade.md) | Upgrades Ubuntu servers to the latest LTS release, disables unnecessary services, and hardens the firewall |
| [redmine.md](redmine.md) | Administers a Redmine instance on Ubuntu with Phusion Passenger |

## Installation

Copy any `.md` skill file to `~/.claude/commands/` and invoke it with `/<skill-name>` in Claude Code.

## Usage

```
/server-upgrade user@your-server-ip
/redmine
```
