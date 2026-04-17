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

# Passenger config audit
grep -r "PassengerMaxPoolSize\|PassengerMinInstances\|PassengerPoolIdleTime\|PassengerPreStart\|PassengerMaxRequests\|PassengerSpawnMethod" /etc/apache2/ 2>/dev/null

# Slow MySQL queries
mysqldumpslow -s t -t 10 /var/log/mysql/slow.log 2>/dev/null

# Redmine request times
grep "Completed" $REDMINE_ROOT/log/production.log | tail -20

# Slow requests (>500ms) with controller info
grep -B5 "Completed.*in [0-9]\{4,\}ms" $REDMINE_ROOT/log/production.log | grep -E "Processing|Completed" | tail -20

# Memory usage
free -h
ps aux --sort=-%mem | grep -E "ruby|passenger" | head -10

# Log size
du -sh $REDMINE_ROOT/log/*.log

# DB table sizes and bloat
mysql -u $DB_USER -p$DB_PASS $DB_NAME -e "SELECT table_name, table_rows, ROUND(data_length/1024/1024,2) as data_MB, ROUND(index_length/1024/1024,2) as index_MB FROM information_schema.tables WHERE table_schema='$DB_NAME' ORDER BY data_length DESC LIMIT 15;"

# MySQL buffer pool hit rate
mysql -u $DB_USER -p$DB_PASS -e "SELECT ROUND(100 * (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_buffer_pool_read_requests') / ((SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_buffer_pool_read_requests') + (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_buffer_pool_reads')), 2) AS hit_rate_pct;"

# Fragment cache status
du -sh $REDMINE_ROOT/tmp/cache/
```

## Passenger optimization
Tune these based on available RAM and CPU. Each Passenger Ruby worker uses ~250-350MB.

**Formula:** MaxPoolSize = min(CPU_cores, available_RAM_GB / 0.35)

```bash
# /etc/apache2/conf-available/passenger-tuning.conf (global)
PassengerMaxPoolSize 6           # adjust per formula above
PassengerMaxRequests 2000        # recycle workers after N requests
PassengerPoolIdleTime 600        # keep idle workers 10min (default 300)
PassengerStatThrottleRate 5      # check filesystem every 5s not every request
PassengerMaxRequestQueueSize 100
PassengerPreStart https://DOMAIN/ # eliminate cold start after restart

# In VirtualHost (per-app)
PassengerSpawnMethod smart       # copy-on-write, shares memory between workers
PassengerMinInstances 2          # always keep 2 workers warm
PassengerAppEnv production
```

After changes: `apache2ctl configtest && systemctl restart apache2`

## MySQL optimization for Redmine
```bash
# Add fulltext indexes for Redmine search (huge speedup on large DBs)
ALTER TABLE journals ADD FULLTEXT INDEX ft_journals_notes (notes);
ALTER TABLE issues ADD FULLTEXT INDEX ft_issues_subject (subject);
ALTER TABLE issues ADD FULLTEXT INDEX ft_issues_description (description);

# Optimize/defragment large tables (safe, rebuilds indexes)
OPTIMIZE TABLE journals, issues, notes, attachments, journal_details, contacts;
ANALYZE TABLE journals, issues, notes;

# Check for missing indexes on custom_values (common bottleneck)
SHOW INDEX FROM custom_values;
# Should have: (customized_type, customized_id) and (custom_field_id)
```

Key MySQL variables to check:
| Variable | Recommended | Notes |
|---|---|---|
| `innodb_buffer_pool_size` | 25-50% of RAM | Most impactful setting |
| `innodb_flush_log_at_trx_commit` | 2 | Good balance for Redmine (not banking) |
| `tmp_table_size` / `max_heap_table_size` | 64M+ | Avoid temp tables on disk |
| `slow_query_log` | ON | Always enable, `long_query_time` = 1 |

## Plugin optimization patterns
Common performance issues in Redmine plugins and how to fix them.

### N+1 query: `project.children.any?` in loops
**Problem:** Calling `project.children.any?` or `project.children.count` inside a loop triggers a separate SQL query per project.

**Fix:** Precompute parent IDs from the already-loaded collection:
```ruby
all_projects = projects.to_a
project_ids = all_projects.map(&:id)
parent_ids = all_projects.select { |p| project_ids.include?(p.parent_id) }.map(&:parent_id).to_set
has_children = ->(p) { parent_ids.include?(p.id) }

# Then in loop: use has_children.call(project) instead of project.children.any?
```

### Fragment caching for view hooks
**Problem:** Sidebar/hook partials re-render on every request even when data rarely changes.

**Fix:** Add Rails fragment caching with a smart cache key:
```erb
<% cache_key = "sidebar_projects/#{User.current.id}/#{@project&.id}/#{Project.maximum(:updated_on).to_i}" %>
<% cache(cache_key, expires_in: 5.minutes) do %>
  <%= render_project_sidebar_tree(Project.active.visible) %>
<% end %>
```
Cache key includes user_id (visibility varies per user), project_id (selected state), and max updated_on (auto-invalidates on any project change).

### Eager loading in view hooks
**Problem:** `render_on :view_layouts_base_sidebar` partials that query DB without includes.

**Fix:** Use `.includes()` or `.preload()`:
```ruby
# Bad:  Project.active.visible  (then accessing associations in loop)
# Good: Project.active.visible.includes(:parent, :enabled_modules)
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
| `config/database.yml` | DB connection (has DB user/pass for mysql commands) |
| `config/configuration.yml` | SMTP, attachments, cipher key |
| `config/environments/production.rb` | Rails production settings |
| `themes/<name>/stylesheets/application.css` | Custom theme |
| `/etc/apache2/mods-available/passenger.conf` | Passenger global settings (PassengerRoot, DefaultRuby) |
| `/etc/apache2/conf-available/passenger-tuning.conf` | Passenger pool tuning (MaxPoolSize, IdleTime, PreStart) |
| `/etc/apache2/sites-enabled/<domain>.conf` | Per-app Passenger settings (SpawnMethod, MinInstances) |
| `/etc/mysql/conf.d/redmine-performance.cnf` | MySQL tuning |

## Remote access
Redmine instances may be on remote servers. If the user specifies a hostname, prefix all commands with `ssh user@host`. When working remotely:
- Use `scp` to transfer files (easier than escaping heredocs over SSH)
- Create files locally in `/tmp/` first, then `scp` to server
- Read `config/database.yml` to get DB credentials for mysql commands
- The app user's PATH may not include rbenv — use full path: `export PATH=/home/$APP_USER/.rbenv/shims:/home/$APP_USER/.rbenv/bin:$PATH`

## Safety rules
- NEVER run `db:drop`, `db:reset`, or destructive migrations without explicit instruction
- Always backup DB before plugin installs or major upgrades
- Always backup config files before modifying: `cp file file.bak.$(date +%Y%m%d)`
- Run `apache2ctl configtest` before restarting Apache
- Test email config changes with the built-in test (Administration → Settings → Email)
- After `assets:precompile`, always hard-refresh browser (Ctrl+Shift+R)
