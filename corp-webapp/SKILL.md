---
name: corp-webapp
description: House standard for internal company web applications — new apps are created from a ready Laravel starter that already has the house look (shadcn-style Bootstrap theme, sidebar, light/dark, organisation logo — "Generic Corp" placeholder), Active Directory login with local roles and AD user import, a mandatory driver.js onboarding tutorial, modals/side sheets without page reloads, sortable tables, dashboard cards, CARTO maps, https behind Caddy and Docker. Use this skill WHENEVER a new internal web app, tool, admin panel, evidencija, register or portal is created for the organisation (even if the user doesn't mention AD, theme or tutorial — they are required by default), and whenever an existing internal app should get the same look, AD/LDAP login ("prijava preko AD"), user import from Active Directory, a tutorial/vodič, dark mode or modals.
---

# corp-webapp — standard za interne web aplikacije

Svaka nova interna web aplikacija izgleda i ponaša se isto, i ima tri obavezne celine:

1. **Izgled** — kućna tema (bočni meni, svetla/tamna tema, logotip organizacije, Inter font).
2. **Active Directory prijava** — domen nalog, lokalne uloge, admin korisnici, uvoz iz AD-a.
3. **Obavezni tutorijal** — driver.js vodič koji mora da se prođe, pamti se u pregledaču, dugme „?“.

Uz to idu modali i bočni paneli bez učitavanja stranice, toast poruke, sortiranje tabela, https iza
Caddy-ja i Docker. Ne pitaj da li ih treba — podrazumevani su. Izostavi neku samo ako korisnik to izričito kaže.

Brend je generički: **Generic Corp**, plava `#2563eb`, znak-kocka u `starter/laravel/public/images/`.
Za pravu organizaciju vidi „Brend organizacije“ ispod.

## Režim 1 — nova aplikacija (podrazumevano)

```bash
~/.claude/skills/corp-webapp/scripts/new-app.sh ~/apps/<ime> "<Naziv aplikacije>" <http-port> <db-port>
cd ~/apps/<ime> && docker compose up -d --build
```

Opcione promenljive: `ORG_NAME="Acme d.o.o."` (naziv organizacije), `APP_LOCALE=en` (podrazumevano `sr`),
`LARAVEL_VERSION="^12.0"` (podrazumevano najnovija stabilna).

Skripta pravi sveži Laravel (Composer u Docker-u, najnovija stabilna verzija — 13 traži PHP 8.4, starter
koristi `php:8.4-fpm`) i preko njega kopira `starter/laravel/`. Dobija se odmah upotrebljiva aplikacija:
prijava (lokalno + AD), kontrolna tabla, korisnici (kreiranje, aktiviranje, uvoz iz AD), vodič, tema,
modali, Docker (nginx + php-fpm + MySQL). Lokalni `admin` se pravi jednom (`ADMIN_INITIAL_PASSWORD` ili
nasumična lozinka u `docker compose logs app`) i seeder ga nikad ne resetuje.

Pre pokretanja proveri slobodne portove (`ss -ltn`; skripta odbija zauzet port).
Posle generisanja:
- `.env`: `LDAP_HOST`, `LDAP_BASE_DN`, `LDAP_SEARCH_OU`, `LDAP_DOMAIN_LABEL`, `LDAP_BIND_USER` /
  `LDAP_BIND_PASS` (servisni nalog samo za čitanje; u navodnicima `"CORP\\korisnik"` sa **dve** kose crte),
  `ORG_NAME` / `ORG_LOCATION`, `MAP_CENTER`, po potrebi `CARTO=` ključ vezan za domen aplikacije.
- Aplikaciju gradi na tom skeletu — gde šta ide:

| Šta | Gde |
|---|---|
| Naziv organizacije, putanje logotipa | `config/brand.php` (`ORG_NAME`, `ORG_LOCATION` u `.env`) |
| Stavke menija i grupe | `config/navigation.php` (ruta, obrazac aktivne rute, ikonica, prevod, uloge) |
| Koje adrese se otvaraju u modalu / bočnom panelu | `config/ui.php` (regex putanje) |
| Koraci vodiča po stranici | `lang/{sr,en}/tour.php` → `pages` (regex putanje → `[selektor, naslov, tekst, strana]`) |
| Tekstovi interfejsa | `lang/{sr,en}/app.php` (`APP_LOCALE`, podrazumevano `sr`) |
| Nove stranice | `@extends('layouts.app')`, Bootstrap 5.3 markup, `h2.fw-bold` naslov, `card border-0`, `table` |
| Prava pristupa | `role:admin,supervisor` middleware; `User::isAdmin()`, `canManage()` |
| Mape | Leaflet + `appBaseLayer(map)` (nikad direktno `L.tileLayer`), centar `APP_MAP_CENTER` — vidi `references/maps.md` |
| Sortiranje tabela | `<table class="table-sort">` + `<th data-sort="text\|num">` (već u `app-ui.js`) |
| Pregled priloga (lightbox) | više priloga jednog zapisa → **lightbox sa listom** (lista levo, pregled desno, akcije dole); pojedinačan fajl → `data-lightbox` — vidi `references/file-preview.md` |
| Proces sa koracima, kontrolna tabla, naslov detalja | `references/ui-patterns.md` (hibrid: faze + lista + detalj koraka; `dash-*` tabla) |

Za svaku novu stranicu dodaj korake u oba `tour.php` i proveri je u svetloj i tamnoj temi i na telefonu.

## Brend organizacije

Starter nosi generički brend. Za pravu organizaciju (jednom, pa važi za sve njene aplikacije —
najbolje u kopiji ovog skill-a):

1. `.env.corp` u starteru (ili `.env` aplikacije): `ORG_NAME`, `ORG_LOCATION`, AD podešavanja, `MAP_CENTER`.
2. `starter/laravel/public/images/`: zameni `brand-logo.svg` (pun logotip, ~240×64, tamni tekst na
   svetlom — u tamnoj temi se automatski invertuje), `brand-mark.svg` i `brand-mark-white.svg`
   (kvadratni znak; beli ide na podlogu u boji brenda), `favicon.svg` i `favicon-32.png`.
   PNG umesto SVG-a je u redu — promeni putanje u `config/brand.php`.
3. `public/css/theme.css`: `--app-brand`, `--app-brand-rgb`, `--app-brand-hover`, `--app-brand-soft`,
   `--app-ring`, `--bs-primary-*` u svetlom i tamnom bloku, i dve fiksne boje (`.brand-mark`, `.auth-aside`).
4. Proveri kontrast: bela slova na `--app-brand` (login panel, primarna dugmad) i brend boja na tamnoj pozadini.

## Pravila interfejsa (korisnici su ih izričito tražili)

- **Nazivi su klikabilni svuda, ne samo ikonica.** Naziv dokumenta, priloga, ugovora ili zapisa otvara
  isto što i 👁 pored njega (pregled, lightbox, detalj). Ako naziv nije `<a>`, piši
  `<span class="klik-naziv" role="button" tabindex="0" …>` — klasa je u `theme.css`, Enter/razmak
  obrađuje `app-ui.js`. Ikonica sme da ostane, ali nikad kao jedini okidač. Isto važi za kartice na
  kontrolnoj tabli (brojevi vode na filtriranu listu — `stretched-link`).
- **Prilozi se pregledaju u lightbox-u sa listom**, ne u novom tabu: lista levo, prvi prilog odmah
  desno, akcija (npr. „Poveži") u podnožju — da korisnik pregleda pa odmah odluči
  (`references/file-preview.md`, odeljak 6).
- **Akcije nad redom su pojedinačna dugmad preko AJAX-a**, ne štikliranje + „Primeni na izabrane".
  Red se ažurira na mestu (npr. zelen „Povezano"), poruka ide u toast, stranica se ne učitava ponovo;
  server vraća JSON `{ok, msg, …}`, greška ostaje vidljiva.
- **Proces sa koracima = hibrid**: stepper faza + lista koraka (jedan red, jedno dugme
  Urađeno/Poništi) + detalj koraka ispod reda; ne kartica po koraku, ne čarobnjak sa „Sačuvaj".
  Akcije preko AJAX-a, osvežava se samo ta sekcija (`references/ui-patterns.md`, odeljak 1).
- **Kontrolna tabla**: stat kartice sa ikonicom/podnaslovom/›, periodi „realizovano / planirano",
  tabele sa statusom-tačkom i menijem ⋯ (`references/ui-patterns.md`, odeljak 2).
- **Detalj zapisa u naslovu nosi zapis** („2.39 · Predmet…"), ne „Detalj …".
- **Po slici (mockup) — pravi podaci, bez praznih dugmadi**; razlike se kažu na kraju.
- Pre većeg redizajna: commit + git tag trenutnog izgleda, da korisnik može da se vrati.

## Režim 2 — postojeća aplikacija

Isti rezultat, ali ručno i pažljivo, jer aplikacija već ima svoje korisnike, rute i izgled:

1. Upoznaj aplikaciju pre izmena: framework, postojeća prijava i tabela korisnika, layout fajlovi, sve
   mape (`grep -rn tileLayer`), Dockerfile, proxy, način deploy-a, `git status` (tuđe necommitovane
   izmene ne gazi).
2. Primeni celinu po celinu prema referencama; izvorni fajlovi su u `starter/laravel/` — prilagodi
   rute, uloge i nazive (meni iz `config/navigation.php`, `tour.js` i `app-ui.js` bez hardkodovanih putanja).

| Celina | Referenca |
|---|---|
| AD prijava, admin korisnici, uvoz iz AD (Laravel i čist PHP) | `references/ad-login.md` |
| Obavezni tutorijal | `references/tour.md` |
| Tema (izgled, brend, tamni režim, login, https iza Caddy-ja) | `references/theme.md` |
| Modali, bočni paneli, potvrde, toast | `references/overlays.md` |
| CARTO mape sa čitljivim nazivima i OSM rezervom | `references/maps.md` |
| Pregled fajlova (PDF/slike lightbox, DOCX/XLSX → HTML) | `references/file-preview.md` |
| Proces sa koracima (hibrid), kontrolna tabla, sortiranje tabela, naslov detalja | `references/ui-patterns.md` |

Čist PHP: tema i JavaScript su isti (`theme.css`, `app-ui.js`, `tour.js`, `lightbox.js`); AD logiku iz
`LdapService` prepiši u helper — vidi `references/ad-login.md`.

## Provera (oba režima)

```bash
(cd ~/.claude/skills/corp-webapp/scripts && npm install)   # jednom: puppeteer-core
node ~/.claude/skills/corp-webapp/scripts/ui-smoke.mjs --base http://localhost:<port> \
     --user admin --pass <lozinka> --modal /users [--sheet /<lista>] --ldap-probe --out /tmp/smoke
```
Treba lokalni Chrome/Chromium (`CHROME_PATH` ako nije na standardnoj putanji). Proverava prijavu, AD
odbijanje (sa **nepostojećim** imenom), obavezni vodič, pamćenje u pregledaču, dugme „?“, modal/panel i
JavaScript greške, i pravi snimke. Pogledaj i snimke u tamnoj temi i na 390 px.

## Deploy

Pre slanja uporedi serverske fajlove sa lokalnim, napravi backup baze, fajlova i `.env`, šalji samo
izmenjene fajlove; posle promene Dockerfile-a gradi samo `app` servis. Produkciju proveri preko https
(samo radnje koje ne menjaju podatke). Za novu aplikaciju: Caddy blok (npr. sa wildcard
`*.corp.example` sertifikatom) → `reverse_proxy localhost:<port>`, i DNS zapis **u svakoj zoni** u kojoj
se ime razrešava — kod split-DNS-a i na javnom DNS-u **i** na internom AD DNS-u (posebna kopija zone,
inače ime ne radi u internoj mreži).

## Zamke koje su se već desile (i zašto)

- **AD lockout**: svaki neuspeli bind se broji u AD-u → `throttle:10,1` na POST `/login`; testiraj samo
  nepostojećim imenima.
- **PHP bez ldap** u zvaničnoj slici → `libldap2-dev` + `docker-php-ext-configure ldap
  --with-libdir=lib/$(uname -m)-linux-gnu` + `docker-php-ext-install ldap`.
- **Composer 2.9 odbija Laravel verzije sa bezbednosnim propustima** (stara aplikacija, Laravel 11 bez
  `composer.lock` → privremeno `composer:2.8`). Nove aplikacije: najnoviji Laravel + commitovan `composer.lock`.
- **`composer dump-autoload` u Dockerfile-u** pokreće `package:discover` koji ne radi pri izgradnji →
  `--no-scripts`. `/bin/sh` ne razume `{a,b}` u `mkdir -p`.
- **Kolačići iz JavaScript-a** (`login_ldap`, `app_tutorial_done`) → `encryptCookies(except: [...])`.
- **https iza Caddy-ja**: bez `trustProxies(at: '*')` Laravel pravi `http://` linkove → pregledač blokira
  CSS (mixed content) i tema „nestane“ samo na produkciji.
- **Validacija u modalu** — `back()` koristi Referer → `fetch(..., {referrer: urlForme})`.
- **CARTO** traži ključ vezan za domen (bez njega vodeni žig, na localhost 403) → automatska OSM rezerva;
  Dark Matter nazivi su pretamni → poseban posvetljeni sloj.
- **Blade keš kao root** (`artisan view:cache` iz `docker exec`) blokira php-fpm →
  `chown -R www-data storage bootstrap/cache`.
- **AD nalozi retko imaju email** → email nullable, prijava po korisničkom imenu.
- **Seeder koji na svakom startu resetuje lozinke demo naloga** — starter to ne radi.
- **Nevidljiv plutajući widget guta klikove** (chat widget): prozor je u inline stilu imao
  `display:none` pa kasnije `display:flex` → važio je flex, a `opacity:0` + `pointer-events:all` +
  visok `z-index` blokirali su donji desni ugao svih stranica dok se chat ne otvori/zatvori.
  Skriven overlay mora biti `display:none` (ili `pointer-events:none`); proveri
  `document.elementFromPoint` u uglu posle učitavanja.

## Podešavanja organizacije (popuni za svoju sredinu)

| Vrednost | Primer u starteru | Gde |
|---|---|---|
| Naziv / mesto | `Generic Corp` / prazno | `ORG_NAME`, `ORG_LOCATION` |
| Domen kontroler | `dc01.corp.example:389` | `LDAP_HOST`, `LDAP_PORT` |
| Base DN / OU korisnika | `DC=corp,DC=example` / `OU=Users,DC=corp,DC=example` | `LDAP_BASE_DN`, `LDAP_SEARCH_OU` |
| Filter korisnika | `(&(objectClass=user)(objectCategory=person)(givenName=*)(sn=*))` | `LDAP_USER_FILTER` |
| Oznaka domena u formi | `corp.example` | `LDAP_DOMAIN_LABEL` |
| Centar mapa | `51.5074,-0.1278` | `MAP_CENTER` |
| Brend boja | `#2563eb` (tamna `#60a5fa`) | `public/css/theme.css` |

Servisni AD nalog neka bude poseban nalog samo za čitanje; lozinka samo u `.env`, nikad u kodu ili u git-u.
Ako neka postojeća aplikacija drži AD lozinku u fajlu pod git-om, podseti korisnika — ne kopiraj taj fajl.

## Sadržaj paketa

```
scripts/new-app.sh       nova aplikacija: Laravel + starter + .env + docker-compose
scripts/ui-smoke.mjs     provera u pregledaču (headless Chrome, bez menjanja podataka; npm install u scripts/)
starter/laravel/         skelet (brend, config-driven meni, modali, vodič, sortiranje; AD, korisnici, tema, Docker)
references/              ad-login, tour, theme, overlays, maps, file-preview, ui-patterns
```
