# Server Audit

Perform a comprehensive server security audit after a suspected compromise or spam attack. Run checks in parallel where possible, then produce a consolidated report with remediation steps.

This skill targets Ubuntu/Debian servers running Postfix, fail2ban, and PHP-FPM behind Apache/Nginx with Virtualmin/Webmin.

## 1. Active Threat Assessment

Check for ongoing abuse:
- `mailq | tail -5` — pending mail queue size
- `postqueue -p | grep -c "^[A-F0-9]"` — count queued messages
- `journalctl -u postfix --since "1 hour ago" | grep -i "sasl_username"` — recent SMTP AUTH logins
- `grep "sasl_username" /var/log/mail.log | awk '{print $NF}' | sort | uniq -c | sort -rn | head -10` — top SASL users
- If spam found: `postsuper -d ALL` to flush queue after confirmation

## 2. Postfix Hardening

Verify these settings in `/etc/postfix/main.cf`:

**SASL AUTH** — must NOT be on port 25:
- Port 25: relay only, no SASL AUTH (check `master.cf` — smtpd on port 25 should have `-o smtpd_sasl_auth_enable=no`)
- Port 587/465: SASL AUTH enabled with TLS encrypt

**Restrictions** (check `smtpd_recipient_restrictions`, `smtpd_helo_restrictions`, `smtpd_sender_restrictions`):
- HELO required + reject invalid/non-FQDN
- Sender: reject non-FQDN, reject unknown sender domain
- RBL blacklists: `reject_rbl_client zen.spamhaus.org`, `bl.spamcop.net`, `dnsbl.sorbs.net`

**Rate limiting** (`smtpd_client_*` or via `anvil`):
- Port 25: max ~20 connections, ~30 messages, ~50 recipients per client
- Port 587/465: max ~15 connections, ~20 messages, ~50 recipients

**Other**:
- `smtpd_banner` — should NOT expose OS version
- `message_size_limit` — reasonable value (25MB = 26214400)
- TLS on submission ports: `smtpd_tls_security_level = encrypt`

## 3. fail2ban Hardening

Check `/etc/fail2ban/jail.local`:
- `bantime` should be >= 24h (86400)
- `maxretry` should be <= 3
- `findtime` should be ~600s
- `bantime.increment` should be enabled (exponential backoff)
- Jails enabled: `sshd`, `postfix-sasl`, `apache-auth` at minimum
- Run `fail2ban-client status` to verify active jails
- Run `fail2ban-client status postfix-sasl` to check current bans

## 4. SSH Hardening

Check `/etc/ssh/sshd_config`:
- `PermitRootLogin` should be `prohibit-password` or `no`
- `MaxAuthTries` should be <= 3
- `PasswordAuthentication` — prefer `no` (key-only)
- Check for unauthorized keys in `/root/.ssh/authorized_keys` and `/home/*/.ssh/authorized_keys`

## 5. PHP-FPM Security

For each pool in `/etc/php/*/fpm/pool.d/`:
- `disable_functions` should include: `mail, passthru, exec, system, popen, proc_open, shell_exec`
- Check which pools have `mail` enabled (potential spam source)
- Verify `open_basedir` is set per pool

## 6. Compromised Account Check

- Check for credential abuse: `grep "sasl_username" /var/log/mail.log | awk -F'sasl_username=' '{print $2}' | sort | uniq -c | sort -rn`
- Flag accounts sending > 50 messages/day
- Check for unauthorized cron jobs: `for u in $(cut -f1 -d: /etc/passwd); do crontab -u $u -l 2>/dev/null; done`
- Check for suspicious processes: `ps aux | grep -E "perl|python|nc |ncat|wget|curl" | grep -v grep`

## 7. Malware Scan

Run if available:
- `clamscan -r /var/www/ --infected --no-summary 2>/dev/null` (ClamAV)
- `chkrootkit 2>/dev/null | grep -v "not found\|nothing found\|not infected"`
- Check for suspicious files: `find /tmp /var/tmp /dev/shm -type f -executable 2>/dev/null`
- Check webroot for PHP shells: `grep -rl "eval(base64_decode\|system(\$_\|passthru(\$_\|shell_exec(\$_" /var/www/ 2>/dev/null`

## 8. DNS & Email Authentication

Check DNS records for all domains:
- SPF: should end with `-all` (hard fail), NOT `~all`
- DKIM: verify key is published and signing works (`opendkim-testkey -d domain -s selector`)
- DMARC: should exist with `p=quarantine` or `p=reject`

## 9. Firewall & Network

- `iptables -L -n --line-numbers` — review rules
- Check persistence: `/etc/iptables/rules.v4` should exist
- Verify only needed ports are open: 22, 25, 80, 443, 587, 465, 993, 10000 (Webmin)
- Check for unexpected listening ports: `ss -tulnp`

## Output Format

Produce a report with:
1. **Threat status** — is there an active attack? Immediate actions taken.
2. **Findings table** — severity (CRITICAL/HIGH/MEDIUM/LOW), what was found, current vs recommended value
3. **Remediation script** — bash commands to fix all issues, with comments. User confirms before execution.
4. **Post-remediation checklist** — what to verify manually (DNS changes, password rotations, etc.)

Always ask before making changes. Credential rotation must be coordinated with the user.
