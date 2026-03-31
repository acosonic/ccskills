# Redmine Admin Skill (Ubuntu www + PhusionPassenger)

You are helping administer a Redmine instance. Before doing anything, gather context by running these checks silently:

```bash
# Detect Redmine root
REDMINE_ROOT=$(find /home -name "Gemfile" -path "*/redmine*" 2>/dev/null | head -1 | xargs dirname 2>/dev/null || echo "/var/www/redmine")
echo "Root: $REDMINE_ROOT"

# Detect app user (owner of Gemfile)
APP_USER=$(stat -c '%U' "$REDMINE_ROOT/Gemfile" 2>/dev/null || echo "www-data")
echo "User: $APP_USER"

# Detect Ruby/bundle
BUNDLE=$(su - $APP_USER -s /bin/bash -c "which bundle" 2>/dev/null || find /home -name bundle -path "*rbenv*shims*" 2>/dev/null | head -1)
echo "Bundle: $BUNDLE"

# Detect Redmine version
grep -r "VERSION" "$REDMINE_ROOT/lib/redmine/version.rb" 2>/dev/null | head -1

# Detect app server
systemctl list-units --type=service --state=running 2>/dev/null | grep -iE "passenger|puma|unicorn|thin"
passenger-status --show=pool 2>/dev/null | head -5

# Detect web server
apache2ctl -v 2>/dev/null | head -1 || nginx -v 2>/dev/null

# Detect DB
grep -A5 "production:" "$REDMINE_ROOT/config/database.yml" 2>/dev/null | grep -E "adapter|database|host"
```

Use the detected values for all subsequent commands. Always run bundle commands as the app user from the Redmine root directory with RAILS_ENV=production.

## Restart app server
```bash
# Passenger
touch $REDMINE_ROOT/tmp/restart.txt

# Puma/systemd
systemctl restart redmine 2>/dev/null || systemctl restart puma 2>/dev/null
```

## Common rake tasks (run as app user)
```bash
cd $REDMINE_ROOT && RAILS_ENV=production $BUNDLE exec rake <task>

# Asset precompile (after theme/plugin changes)
assets:precompile

# Clear cache
tmp:cache:clear

# Send reminders
redmine:send_reminders days=7

# Clean old sessions
redmine:cleanse:sessions

# DB migrate (after plugin install)
db:migrate redmine:plugins:migrate
```

## Theme deployment checklist
1. Place theme in `$REDMINE_ROOT/themes/<name>/stylesheets/application.css`
2. Theme CSS must start with `@import url(/application.css);` (Redmine 6+ / Propshaft)
3. Run `assets:precompile`
4. Restart app server
5. Activate: Administration → Settings → Display → Theme

## Plugin install checklist
1. Copy plugin to `$REDMINE_ROOT/plugins/<name>/`
2. Run `db:migrate redmine:plugins:migrate`
3. Run `assets:precompile`
4. Restart app server

## Performance checks
```bash
# Passenger workers
passenger-status --show=pool

# Slow MySQL queries
mysqldumpslow -s t -t 10 /var/log/mysql/slow.log 2>/dev/null

# Redmine request times
grep "Completed" $REDMINE_ROOT/log/production.log | tail -20

# Memory usage
free -h
ps aux --sort=-%mem | grep -E "ruby|passenger" | head -10

# Log size
du -sh $REDMINE_ROOT/log/*.log
```

## Email troubleshoot
```bash
# Test SMTP from Rails console (run as app user)
cd $REDMINE_ROOT && RAILS_ENV=production $BUNDLE exec rails runner \
  "Mailer.test_email(User.find(1)).deliver_now"

# Check Postfix cert
openssl s_client -starttls smtp -connect 127.0.0.1:587 -brief 2>&1 | grep -E "CN|notAfter|Verify"

# Check cert expiry
certbot certificates 2>/dev/null
```

## SSL cert renewal
```bash
certbot renew --dry-run
# Deploy hook for Postfix: /etc/letsencrypt/renewal-hooks/deploy/reload-postfix.sh
```

## Backup (before risky changes)
```bash
# DB dump
mysqldump -u root prijave > /tmp/redmine_backup_$(date +%Y%m%d).sql

# Files backup
tar -czf /tmp/redmine_files_$(date +%Y%m%d).tar.gz $REDMINE_ROOT/files/
```

## Key config files
| File | Purpose |
|---|---|
| `config/database.yml` | DB connection |
| `config/configuration.yml` | SMTP, attachments, cipher key |
| `config/environments/production.rb` | Rails production settings |
| `themes/<name>/stylesheets/application.css` | Custom theme |
| `/etc/apache2/mods-available/passenger.conf` | Passenger global settings |
| `/etc/mysql/conf.d/redmine-performance.cnf` | MySQL tuning |

## Safety rules
- NEVER run `db:drop`, `db:reset`, or destructive migrations without explicit instruction
- Always backup DB before plugin installs or major upgrades
- Test email config changes with the built-in test (Administration → Settings → Email)
- After `assets:precompile`, always hard-refresh browser (Ctrl+Shift+R)
