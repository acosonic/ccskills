#!/usr/bin/env bash
# New internal web app: fresh Laravel + the corp-webapp starter
# (theme, AD login, users admin + AD import, mandatory tutorial, modals/sheets, Docker).
#
#   new-app.sh <target-dir> "<App name>" [http-port=8090] [db-port=3310]
#
# Needs Docker. Composer runs in a container, so PHP on the host is not required.
set -euo pipefail

TARGET=${1:?usage: new-app.sh <target-dir> "<App name>" [http-port] [db-port]}
APP_NAME=${2:?usage: new-app.sh <target-dir> "<App name>" [http-port] [db-port]}
HTTP_PORT=${3:-8090}
DB_PORT=${4:-3310}
LARAVEL=${LARAVEL_VERSION:-}   # e.g. "^12.0"; empty = latest stable
LOCALE=${APP_LOCALE:-sr}        # default UI language (sr | en)
ORG=${ORG_NAME:-}               # organisation name for the login page / sidebar (default: Generic Corp)

SKILL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
STARTER="$SKILL_DIR/starter/laravel"
TARGET="$(realpath -m "$TARGET")"
SLUG="$(basename "$TARGET" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '_' | sed 's/_*$//')"

if [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ]; then
    echo "ERROR: $TARGET exists and is not empty." >&2; exit 1
fi
for p in "$HTTP_PORT" "$DB_PORT"; do
    if (echo > /dev/tcp/127.0.0.1/$p) 2>/dev/null; then echo "ERROR: port $p is already in use." >&2; exit 1; fi
done

echo "==> Laravel ${LARAVEL:-latest} into $TARGET"
mkdir -p "$(dirname "$TARGET")"
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
    -v "$(dirname "$TARGET")":/app -w /app composer:2 \
    create-project laravel/laravel "$(basename "$TARGET")" ${LARAVEL:+"$LARAVEL"} --prefer-dist --no-interaction --ignore-platform-req=ext-*

echo "==> Applying corp-webapp starter"
( cd "$STARTER" && tar cf - --exclude=.env.corp . ) | ( cd "$TARGET" && tar xf - )
rm -f "$TARGET/resources/views/welcome.blade.php" "$TARGET/database/database.sqlite"   # MySQL in Docker instead

DB_PASS=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
DB_ROOT=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
sed -i "s/__APP_SLUG__/$SLUG/g; s/__HTTP_PORT__/$HTTP_PORT/g; s/__DB_PORT__/$DB_PORT/g; s/__DB_NAME__/$SLUG/g; s/__DB_PASSWORD__/$DB_PASS/g; s/__DB_ROOT_PASSWORD__/$DB_ROOT/g" "$TARGET/docker-compose.yml"

# set_env FILE KEY VALUE — replaces (also commented-out) KEY or appends it
set_env() {
    local f=$1 k=$2 v=$3
    if grep -qE "^#?\s*$k=" "$f"; then sed -i -E "s|^#?\s*$k=.*|$k=$v|" "$f"; else echo "$k=$v" >> "$f"; fi
}
for f in "$TARGET/.env" "$TARGET/.env.example"; do
    set_env "$f" APP_NAME "\"$APP_NAME\""
    set_env "$f" APP_URL "http://localhost:$HTTP_PORT"
    set_env "$f" APP_LOCALE "$LOCALE"
    [ "$LOCALE" = sr ] && set_env "$f" APP_FAKER_LOCALE sr_RS
    set_env "$f" DB_CONNECTION mysql
    set_env "$f" DB_HOST mysql
    set_env "$f" DB_PORT 3306
    set_env "$f" DB_DATABASE "$SLUG"
    set_env "$f" DB_USERNAME "$SLUG"
    cat "$STARTER/.env.corp" >> "$f"
done
set_env "$TARGET/.env" DB_PASSWORD "$DB_PASS"
set_env "$TARGET/.env.example" DB_PASSWORD ""
if [ -n "$ORG" ]; then for f in "$TARGET/.env" "$TARGET/.env.example"; do set_env "$f" ORG_NAME "\"$ORG\""; done; fi

echo
echo "==> Done: $TARGET"
echo "   cd $TARGET && docker compose up -d --build"
echo "   http://localhost:$HTTP_PORT  — admin password: docker compose logs app | grep 'Local admin'"
echo "   Brand: ORG_NAME / ORG_LOCATION in .env, logos in public/images/brand-*.svg, colour --app-brand in public/css/theme.css"
echo "   AD: set LDAP_HOST / LDAP_BASE_DN / LDAP_SEARCH_OU / LDAP_BIND_USER / LDAP_BIND_PASS in .env, then Users → Import from AD"
echo "   Next: config/navigation.php (menu), config/ui.php (modals/sheets), lang/*/tour.php (tour steps)"
