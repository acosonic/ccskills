# Virtualmin Server Management

Manage virtual servers, domains, users, databases, SSL certificates and more via Virtualmin CLI on Ubuntu/Debian servers running Apache + PHP-FPM + Postfix + Virtualmin/Webmin.

## Arguments
- `$ARGUMENTS` — action to perform (see sections below)
  - Examples: `list domains`, `create domain example.com`, `ssl letsencrypt example.com`, `create user`, `list databases`, `backup`, `php version`, `status`
  - If no arguments given, run `list domains` with details

## Connection
```bash
# Always use sudo -S with password piped via echo
echo 'SUDO_PASSWORD' | ssh -o ConnectTimeout=30 USER@HOST "sudo -S virtualmin COMMAND 2>&1"

# For background/long-running commands:
echo 'SUDO_PASSWORD' | ssh -o ConnectTimeout=60 USER@HOST "sudo -S virtualmin COMMAND 2>&1"
```

## CRITICAL RULES
- **Always backup before destructive operations** — `backup-domain` before delete, rename, migrate
- **Always confirm with user before**: `delete-domain`, `delete-database`, `delete-user`, `restore-domain`
- **DNS propagation** — after DNS changes wait 5 min before testing, warn user
- **Let's Encrypt** — domain must be publicly reachable on port 80 before requesting cert
- **Wildcard certs** (`*.domain.rs`) do NOT cover two levels (`www.sub.domain.rs`) — use LE for those
- **PFX import** — strip Bag Attributes before passing chain to `install-cert` (use `awk '/-----BEGIN/,/-----END/'`)
- **sudo -S** requires password piped via `echo 'pass' | sudo -S`, never interactive

---

## 1. Domain Management

### List all domains
```bash
virtualmin list-domains --name-only
# With details:
virtualmin list-domains --multiline
# Single domain:
virtualmin list-domains --domain example.com --multiline
```

### Create domain (top-level)
```bash
virtualmin create-domain \
  --domain example.com \
  --user username \
  --pass 'password' \
  --unix --dir --web --ssl --dns --mail --webmin --mysql \
  --plan 'Default Plan'
```
- Home dir: `/home/username/`
- Webroot: `/home/username/public_html/`
- PHP mode: FPM (default), PHP 8.3
- MySQL user and DB auto-created with same name as `--user`

### Create sub-server (under existing domain)
```bash
virtualmin create-domain \
  --domain sub.example.com \
  --parent example.com \
  --unix --dir --web --ssl --dns --mail --mysql
```

### Create alias domain (points to parent)
```bash
virtualmin create-domain \
  --domain alias.com \
  --alias example.com
```

### Modify domain
```bash
# Change password
virtualmin modify-domain --domain example.com --pass 'newpassword'
# Change quota
virtualmin modify-domain --domain example.com --quota 5000000
# Enable/disable features
virtualmin enable-feature --domain example.com --mysql
virtualmin disable-feature --domain example.com --spam
```

### Rename/move domain
```bash
# Rename domain (also renames home dir and user)
virtualmin rename-domain --domain old.com --newdomain new.com --newuser newuser --newhome /home/newuser

# Move to different owner
virtualmin move-domain --domain example.com --newowner admin
```

### Enable / disable domain
```bash
virtualmin disable-domain --domain example.com
virtualmin enable-domain --domain example.com
```

### Delete domain (CONFIRM FIRST)
```bash
virtualmin delete-domain --domain example.com
```

### Validate domain config
```bash
virtualmin validate-domains --domain example.com --all-features
```

---

## 2. SSL Certificates

### List all certs and expiry
```bash
virtualmin list-certs
virtualmin list-certs-expiry
# Single domain:
virtualmin get-ssl --domain example.com
```

### Install existing cert (from PFX)
```bash
# 1. Extract from PFX (strip Bag Attributes from chain!):
openssl pkcs12 -in cert.pfx -clcerts -nokeys -legacy -passin pass:'PFX_PASS' 2>/dev/null | openssl x509 > /tmp/cert.crt
openssl pkcs12 -in cert.pfx -nocerts -nodes -legacy -passin pass:'PFX_PASS' 2>/dev/null | openssl pkey > /tmp/cert.key
openssl pkcs12 -in cert.pfx -cacerts -nokeys -chain -legacy -passin pass:'PFX_PASS' 2>/dev/null | awk '/-----BEGIN CERTIFICATE-----/,/-----END CERTIFICATE-----/' > /tmp/chain.pem

# 2. Upload to server:
scp /tmp/cert.crt /tmp/cert.key /tmp/chain.pem user@host:/tmp/

# 3. Install via Virtualmin:
virtualmin install-cert \
  --domain example.com \
  --cert /tmp/cert.crt \
  --key /tmp/cert.key \
  --ca /tmp/chain.pem
```

**Wildcard cert coverage:**
- `*.novisad.rs` covers: `kultura.novisad.rs`, `novagpu.novisad.rs` ✅
- `*.novisad.rs` does NOT cover: `www.kultura.novisad.rs` ❌ — use Let's Encrypt for those

### Let's Encrypt (single/multi domain)
```bash
# Single domain:
virtualmin generate-letsencrypt-cert \
  --domain example.com \
  --host example.com \
  --web

# With www:
virtualmin generate-letsencrypt-cert \
  --domain example.com \
  --host example.com \
  --host www.example.com \
  --web

# Force renewal:
virtualmin generate-letsencrypt-cert --domain example.com --host example.com --web --renew
```

### Copy cert to system services (Postfix, Dovecot, Webmin)
```bash
virtualmin install-service-cert --domain example.com --service postfix
virtualmin install-service-cert --domain example.com --service dovecot
virtualmin install-service-cert --domain example.com --service webmin
```

---

## 3. Mail & FTP Users

### List users
```bash
virtualmin list-users --domain example.com
```

### Create user
```bash
virtualmin create-user \
  --domain example.com \
  --user ime.prezime \
  --pass 'password' \
  --email \
  --ftp \
  --quota 500000
```

### Modify user (password, quota)
```bash
virtualmin modify-user --domain example.com --user ime.prezime --pass 'newpass'
virtualmin modify-user --domain example.com --user ime.prezime --quota 1000000
```

### Delete user
```bash
virtualmin delete-user --domain example.com --user ime.prezime
```

### Mail aliases
```bash
# List
virtualmin list-aliases --domain example.com
# Create
virtualmin create-alias --domain example.com --from info --to admin@example.com
# Delete
virtualmin delete-alias --domain example.com --from info
```

### Test mail
```bash
virtualmin test-smtp --host mail.example.com --to user@example.com
virtualmin test-imap --host mail.example.com --user user@example.com --pass 'pass'
virtualmin test-pop3 --host mail.example.com --user user@example.com --pass 'pass'
```

---

## 4. Databases

### List databases
```bash
virtualmin list-databases --domain example.com
```

### Create database
```bash
virtualmin create-database --domain example.com --name example_db --type mysql
```

### Delete database (CONFIRM FIRST)
```bash
virtualmin delete-database --domain example.com --name example_db --type mysql
```

### Change DB password
```bash
virtualmin modify-database-pass --domain example.com --pass 'newpass'
```

### Import/attach existing DB
```bash
virtualmin import-database --domain example.com --name existing_db --type mysql
```

---

## 5. PHP Configuration

### List available PHP versions
```bash
virtualmin list-php-versions
```

### Check PHP version per domain
```bash
virtualmin list-php-directories --domain example.com
virtualmin list-php-ini --domain example.com
```

### Set PHP version for directory
```bash
virtualmin set-php-directory --domain example.com --dir /home/user/public_html --version 8.3
```

### Modify PHP ini values
```bash
virtualmin modify-php-ini --domain example.com --ini-name memory_limit --ini-value 256M
virtualmin modify-php-ini --domain example.com --ini-name upload_max_filesize --ini-value 64M
virtualmin modify-php-ini --domain example.com --ini-name max_execution_time --ini-value 120
```

### Drupal 10 requirements check
Drupal 10.6.x needs: PHP 8.3+, MySQL 8.0+, Apache 2.4.7+
```bash
php8.3 --version
mysql --version
apache2 -v
```

---

## 6. Web Configuration

### Modify web settings
```bash
# Enable HTTPS redirect
virtualmin modify-web --domain example.com --ssl-redirect yes
# Match all subdomains
virtualmin modify-web --domain example.com --match-subdomains yes
```

### Redirects
```bash
# List
virtualmin list-redirects --domain example.com
# Create redirect
virtualmin create-redirect --domain example.com --path /old --dest https://example.com/new --type 301
# Delete
virtualmin delete-redirect --domain example.com --path /old
```

### Proxy
```bash
virtualmin list-proxies --domain example.com
virtualmin create-proxy --domain example.com --path /api --url http://localhost:3000
virtualmin delete-proxy --domain example.com --path /api
```

### Fix permissions
```bash
virtualmin fix-domain-permissions --domain example.com
```

---

## 7. DNS

### View DNS records
```bash
virtualmin get-dns --domain example.com
```

### Modify DNS settings
```bash
virtualmin modify-dns --domain example.com --spf yes
```

### DKIM
```bash
virtualmin set-dkim --enable
```

---

## 8. Backup & Restore

### Backup single domain
```bash
virtualmin backup-domain \
  --domain example.com \
  --dest /home/backups/example-$(date +%Y%m%d).tar.gz \
  --all-features
```

### Backup all domains
```bash
virtualmin backup-domain \
  --all-domains \
  --dest /home/backups/all-$(date +%Y%m%d).tar.gz \
  --all-features
```

### List backup logs
```bash
virtualmin list-backup-logs
```

### Restore domain (CONFIRM FIRST)
```bash
virtualmin restore-domain \
  --domain example.com \
  --source /home/backups/example.tar.gz \
  --all-features
```

---

## 9. Script Installers (WordPress, Drupal, etc.)

### List available scripts
```bash
virtualmin list-available-scripts
```

### List installed scripts on domain
```bash
virtualmin list-scripts --domain example.com
```

### Install script (e.g. WordPress)
```bash
virtualmin install-script \
  --domain example.com \
  --script wordpress \
  --path / \
  --db example_wp \
  --db-pass 'dbpass'
```

### Delete script
```bash
virtualmin delete-script --domain example.com --script wordpress --path /
```

---

## 10. Server Status & Info

### General system info
```bash
virtualmin info
virtualmin check-config
virtualmin list-server-statuses
```

### Bandwidth usage
```bash
virtualmin list-bandwidth --domain example.com --start 2026-01-01 --end 2026-04-30
```

### Logs
```bash
virtualmin get-logs --domain example.com --lines 100
```

### Restart services
```bash
virtualmin restart-server --server apache
virtualmin restart-server --server mysql
virtualmin restart-server --server postfix
virtualmin restart-server --server dovecot
```

### List ports
```bash
virtualmin list-ports --domain example.com
```

---

## 11. Common Workflows

### New staging site (e.g. migracija Drupal 10)
1. Kreiraj domen: `create-domain` sa `--unix --dir --web --ssl --dns --mail --mysql`
2. Instaliraj SSL cert ili Let's Encrypt
3. Provjeri PHP verziju i podesi `memory_limit`, `upload_max_filesize`
4. Upload fajlova: SCP u `/home/user/public_html/`
5. Import baze: `mysql -u user -p db_name < dump.sql`
6. Podesi `settings.php` s novim DB credentialima

### SSL wildcard ne pokriva www.sub.domain
- Wildcard `*.domain.rs` pokrije `sub.domain.rs` ali NE `www.sub.domain.rs`
- Rješenje: `generate-letsencrypt-cert --host sub.domain.rs --host www.sub.domain.rs`

### Zamjena domena (staging → produkcija)
1. Verificiraj staging: sve radi na `novadomain.rs`
2. Backup produkcije: `backup-domain --domain stari.rs`
3. `rename-domain` ili DNS swap
4. Instaliraj SSL na novi naziv
5. Testiraj mail: `test-smtp`, `test-imap`

### Generisanje login linka (bez lozinke)
```bash
virtualmin create-login-link --domain example.com
```
