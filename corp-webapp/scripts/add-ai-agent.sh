#!/usr/bin/env bash
# AI asistent (agent) u corp-webapp aplikaciju: modul modules/ai-agent + Anthropic PHP SDK + rute, meni, layout, vodič, .env.
#
#   add-ai-agent.sh <app-dir> [--site https://www.sajt.rs]     # --site: i javni asistent sajta (varijanta B)
#
# Postojeće fajlove aplikacije ne gazi (cp -n). Posle: ANTHROPIC_API_KEY u .env, php artisan migrate,
# alati aplikacije u app/Services/Ai/Tools + config/ai.php — vidi references/ai-agent.md.
set -euo pipefail

APP=$(realpath "${1:?usage: add-ai-agent.sh <app-dir> [--site URL]}")
SITE=""; [ "${2:-}" = "--site" ] && SITE="${3:?--site traži URL}"
SKILL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MOD="$SKILL_DIR/modules/ai-agent"
[ -f "$APP/artisan" ] || { echo "ERROR: $APP nije Laravel aplikacija (nema artisan)." >&2; exit 1; }

echo "==> fajlovi modula"
( cd "$MOD" && find . -type f | while read -r f; do mkdir -p "$APP/$(dirname "$f")"; [ -e "$APP/$f" ] || cp "$f" "$APP/$f"; done )

echo "==> anthropic-ai/sdk (Composer u Docker-u)"
if ! grep -q '"anthropic-ai/sdk"' "$APP/composer.json"; then
    docker run --rm -u "$(id -u):$(id -g)" -v "$APP":/app -w /app composer:2 require -q --ignore-platform-reqs anthropic-ai/sdk
fi

echo "==> rute, meni, layout, prevodi, vodič, .env"
python3 - "$APP" "$SITE" <<'PY'
import re, sys, pathlib
app, site = pathlib.Path(sys.argv[1]), sys.argv[2]
def edit(rel, fn):
    p = app / rel
    if not p.exists(): print(f"   ! nema {rel} — dodaj ručno"); return
    s = p.read_text(); n = fn(s)
    if n != s: p.write_text(n); print(f"   ✓ {rel}")
    else: print(f"   = {rel} (već je tu ili nije nađeno mesto — proveri ručno)")

# routes/web.php: u auth grupu; javni asistent van nje
def routes(s):
    if "ai.php'" not in s:
        s = s.replace("    // Application routes go here.", "    require __DIR__ . '/ai.php';   // AI asistent (modul ai-agent)\n\n    // Application routes go here.", 1)
    if site and "ai-public.php" not in s:
        s = s.rstrip() + "\n\nrequire __DIR__ . '/ai-public.php';   // javni asistent sajta (bez prijave)\n"
    return s
edit("routes/web.php", routes)

# CSRF izuzetak za javni asistent
if site:
    edit("bootstrap/app.php", lambda s: s if "ai/site/*" in s else re.sub(
        r"->withMiddleware\(function \(Middleware \$middleware\)(?:: void)? \{",
        lambda m: m.group(0) + "\n        $middleware->validateCsrfTokens(except: ['ai/site/*']);   // javni AI asistent sajta", s, count=1))

# meni: prva grupa
def nav(s):   # na kraj prve grupe (posle kontrolne table)
    if "'ai.index'" in s: return s
    m = re.search(r"return \[\s*'[a-z_]+' => \[\n(?:.*\n)*?(    \],\n)", s)
    return s if not m else s[:m.start(1)] + "        ['ai.index', 'ai.*', 'bi-stars', 'ai_assistant', null],\n" + s[m.start(1):]
edit("config/navigation.php", nav)

# layout: asset-i i mehur pre poslednjeg @stack('scripts')
def layout(s):
    if "ai._assets" in s: return s
    i = s.rfind("@stack('scripts')")
    return s if i < 0 else s[:i] + "@include('ai._assets')\n" + s[i:]
edit("resources/views/layouts/app.blade.php", layout)

# prevod stavke menija
for lang, txt in (("sr", "AI asistent"), ("en", "AI assistant")):
    edit(f"lang/{lang}/app.php", lambda s, t=txt: s if "'ai_assistant'" in s else re.sub(r"(return (?:array \(|\[)\n)", lambda m: m.group(1) + f"  'ai_assistant' => '{t}',\n", s, count=1))

# vodič za stranu /ai
steps = {
 "sr": "'^/ai$' => [\n            ['[data-ai-input]', '✨ Pitajte aplikaciju', 'Pitanje običnim jezikom — asistent sam traži u podacima i dokumentima, a dok radi vidi se šta čita.', 'top'],\n            ['#aiList', '🗂️ Razgovori', 'Svi vaši razgovori ostaju sačuvani; klik otvara razgovor.', 'right'],\n            ['#aiPage', '✅ Izmene uz potvrdu', 'Asistent ništa ne menja sam: ispod odgovora predloži izmenu sa „staro → novo\", a upisuje se tek kad kliknete Potvrdi.', 'left'],\n        ],",
 "en": "'^/ai$' => [\n            ['[data-ai-input]', '✨ Ask the application', 'Ask in plain language — the assistant searches data and documents itself and shows what it is reading.', 'top'],\n            ['#aiList', '🗂️ Conversations', 'All your conversations are kept; click one to open it.', 'right'],\n            ['#aiPage', '✅ Changes with confirmation', 'The assistant never changes anything itself: it proposes a change (old → new) below the answer, saved only when you click Confirm.', 'left'],\n        ],",
}
for lang, block in steps.items():
    edit(f"lang/{lang}/tour.php", lambda s, b=block: s if "'^/ai$'" in s else s.replace("'pages' => [\n", "'pages' => [\n        " + b + "\n", 1))

# .env
def env(s):
    add = ""
    if "ANTHROPIC_API_KEY" not in s: add += "\n# AI asistent (modul ai-agent) — bez ključa je isključen\nANTHROPIC_API_KEY=\nANTHROPIC_MODEL=claude-opus-5-5\n"
    if site and "AI_SITE_URL" not in s: add += f"AI_SITE_URL={site}\n"
    return s.rstrip("\n") + "\n" + add if add else s
for f in (".env", ".env.example"): edit(f, env)
PY

cat <<EOF

Gotovo. Dalje:
  1. ANTHROPIC_API_KEY u $APP/.env (nikad u git), pa: docker compose exec app php artisan migrate
  2. Alati aplikacije: app/Services/Ai/Tools/* po uzoru na SearchUsersTool / ChangeUserProposal,
     upis u config/ai.php ('tools', 'proposals', 'sql_schema'), prompt prilagodi domenu.
  3. Kontejner: poppler-utils (pdftotext) za PDF, LibreOffice za .doc/.xls (već ga ima ako je pregled fajlova uključen).
  4. Proba: references/ai-agent.md → „Proba".
EOF
[ -n "$SITE" ] && echo "  5. Javni sajt: docker compose exec app php artisan ai:index-site  (cron dnevno), widget: public/js/ai-site-widget.js"
exit 0
