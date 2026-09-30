# Obrasci ekrana (provereni u produkciji, korisnici su ih izabrali)

Nastali na internoj evidenciji (čist PHP + Bootstrap 5.3), primeri su iz toka nabavke; CSS za
kontrolnu tablu (`dash-*`) i sortiranje tabela (`table-sort`) već su u starteru
(`public/css/theme.css`, `public/js/app-ui.js`). Kad korisnik pošalje sliku/mockup: preslikaj strukturu sa **pravim podacima**,
pa na kraju reci šta se razlikuje i zašto (npr. „kolona Početak ne postoji → prikazan Rok“).
**Ne dodaji dugmad i polja bez funkcije** („Izvezi", „Obavesti me" dok nema obaveštenja) —
predloži ih kao posebnu stavku.

## 1. Proces sa koracima — „hibrid" (faze + lista + detalj koraka)

Za tok nabavke, odobravanja, realizaciju ugovora — svaki proces sa 5–20 koraka. Korisnik je
uporedio tri varijante i izabrao hibrid:

| Varijanta | Zašto nije |
|---|---|
| Kartica po koraku (datum + napomena + dugmad na svakoj) | previše šuma; 11 praznih polja za datum |
| Čarobnjak („Prethodno / Sačuvaj", jedna faza na ekranu) | proces nije linearan (dokumenti stižu bilo kojim redom, spoljni sistem sam označi više koraka); globalno „Sačuvaj" = štikliranje |
| **Hibrid** | ceo tok na jednom ekranu, malo šuma, brze akcije ✔ |

**Struktura** (stranica detalja zapisa, sekcija npr. `#tok-procesa`; faze i koraci iz jedne funkcije/servisa, npr. `workflowFaze()`):

1. **Zaglavlje** — naslov, „X od N koraka urađeno, Y u toku · Z%", desno „Označi sve kao urađeno".
2. **Stepper faza** (4–6 faza koje grupišu korake): krug + naziv + „urađeno/ukupno". Stanja:
   `done` (svi koraci urađeni → ✓), `active` (sadrži prvi neurađen korak → pun plavi krug),
   `partial` (nešto počelo), `todo`. Linija između krugova plava do poslednje započete faze.
   Klik na fazu skroluje do njene grupe (`href="#faza-…"`).
3. **Lista koraka po fazama** — jedan korak = jedan red: broj (✓ kad je urađen), ikonica, naziv,
   datum, 📎 broj priloga, 💬 ako ima napomenu, **status pill**, strelica, i **jedno dugme**:
   „Urađeno" (outline-success) ili „Poništi" (outline-secondary). Urađen red blago zelen, **aktivni
   korak** (prvi neurađen) blago plav sa levom plavom linijom.
4. **Detalj koraka** se otvara klikom na red (aktivni je otvoren odmah; `?korak=` otvara drugi):
   datum, napomena, posebna polja koraka (portal: broj + URL), „Sačuvaj", dokumenta (klikabilni
   nazivi → pregled), vezani zapisi iz spoljnih sistema (npr. delovodnik/DMS), „Priloži dokument…".

**Stanje „U toku" bez promene šeme** (baza ima samo status 0/1): korak nije urađen, a ima sačuvan
datum/napomenu, dokument ili vezan predmet. „Sačuvaj" na neurađenom koraku ga time prebacuje u
„U toku"; „Poništi" vraća u „U toku" i čuva datum i napomenu.

**AJAX za sve akcije u sekciji, bez ponovnog učitavanja:**
- Sve forme u sekciji imaju klasu `wf-ajax`; jedan delegirani `submit` handler šalje `FormData`
  (+ `e.submitter` name/value) sa `Accept: application/json`; potvrda preko `data-confirm` na formi.
- Server: isti POST handler kao pre, samo na mestu PRG redirect-a
  `if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) { echo json_encode(['ok'=>…,'type'=>…,'msg'=>…]); exit; }`
  — obične forme i dalje rade redirect, nijedna akcija se ne duplira.
- Posle uspeha: `fetch(location.href)` → `DOMParser` → zameni **samo** `#tok-procesa`, a koraci koji su
  bili otvoreni ostaju otvoreni. Poruka u toast-u u uglu. Server renderuje stanje na jednom mestu.
- Upload: skriven `input[type=file]`, klik na „Priloži dokument…" ga otvara, `change` → `requestSubmit()`.

**Zamke:**
- Posle zamene HTML-a handleri vezani na elemente nestaju → sve preko **delegacije** na `document`,
  i ne koristi klase koje globalni `app.js` već vezuje (dvostruki dijalog za fajl) — nove klase (`wf2-*`).
- Forme se ne gnezde; dugme van forme koristi `form="<id>"`.
- `onchange="this.form.submit()"` preskače `submit` događaj → `this.form.requestSubmit()`.
- „Označi sve" je prepisivao datume današnjim → `datum = COALESCE(postojeći, novi)`.
- Telefon: dugmad samo ikonica (`<span class="d-none d-sm-inline">Tekst</span>`), naziv sme u dva reda.
- Uloga samo za čitanje: isti redovi i detalj, bez formi (datum/napomena kao tekst).

Pre ovakvog redizajna prethodni izgled sačuvaj kao git tag (npr. `workflow-kartice`) — commit + tag
pre početka, da korisnik može da se vrati.

## 2. Kontrolna tabla

Početna strana + klase `dash-*` (u `theme.css` startera):
- **Stat kartice** (4): ikonica u mekom obojenom kvadratu (`bg-*-subtle text-*-emphasis`, 48px,
  zaobljeno), naziv, velika vrednost (`white-space: nowrap`), podnaslov („planirano u 2026. godini"),
  strelica `›` desno; cela kartica je link (`stretched-link`) na filtriranu listu.
- **Periodi (kvartali)**: „I kvartal" + meseci desno, **realizovano / planirano** (broj i vrednost,
  planirano sivo iza „/"), traka napretka u boji perioda + procenat, rok kao pill
  (Isteklo sivo / < 30 dana crveno / < 90 žuto / inače zeleno); kartica vodi na listu tog perioda.
- **Tabele na tabli**: `#`, naziv (klikabilan, jedan red sa „…" — `td { max-width:0; width:45% }` +
  `a { display:block; text-overflow:ellipsis }`, pun tekst u `title`), iznos, datum, status kao
  tačka + tekst u boji, meni `⋯` (`dropdown` sa `data-bs-popper-config='{"strategy":"fixed"}'` da ga
  `table-responsive` ne odseče). Zaglavlje kartice providno, desno outline dugme sa `›`.
- Sve preko Bootstrap promenljivih — radi u tamnoj temi bez posebnih pravila.

## 3. Naslov strane detalja = zapis

Na detalju zapisa zaglavlje i `<title>` nose identifikator i naziv („2.39 · Periodični pregled…"),
nikad generičko „Detalj nabavke". Naslov u flex zaglavlju: `min-width: 0` + `text-overflow: ellipsis`
+ `title` sa punim tekstom.

## 4. Sortiranje tabela

`<table class="table-sort">`, `<th data-sort="text|num" [data-sort-default="asc|desc"]>`; vrednost
ćelije iz `data-v` (datumi `YYYY-MM-DD`, iznosi kao broj), inače tekst. Prvi klik: brojevi od najvećeg,
tekst od A; drugi klik obrće; strelica ↑/↓ u zaglavlju. Radi zajedno sa filterom pretrage.
Implementacija: `starter/laravel/public/js/app-ui.js` (`sortTable`, delegirano — radi i u modalu/panelu).

## 5. Keš statičkih fajlova

Svaki CSS **i JS** se učitava sa `?v=<?= filemtime(...) ?>` (u Laravel-u `?v={{ filemtime(public_path(...)) }}`).
Bez toga pregledač zadrži stari `app.js` i nove funkcije (sortiranje, prečice) „ne rade" samo kod korisnika.
