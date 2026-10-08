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

## 6. Sklopive kartice

Veće kartice na detalju (npr. „Ugovor iz spoljnog sistema", „Arhiva predmeta") mogu da se skupe: strelica
`.sklopi-dugme[data-sklopi=kljuc]` desno u zaglavlju, klik na prazan deo zaglavlja radi isto.
Stanje u kolačiću `sklop_<kljuc>` i **server ga renderuje** (`class="card {{ sklopljeno('ugovor') }}"`) —
zato ostaje i kad AJAX zameni sekciju i na drugim zapisima. CSS (`.card.sklopljena`) i JS (delegirani klik,
kolačić) su u starteru (`theme.css`, `app-ui.js`); na serveru je samo helper koji čita kolačić.

## 7. Filter godine prati otvoreni zapis

Kad aplikacija ima izbor godine u zaglavlju, detalj zapisa **postavlja aktivnu godinu na godinu zapisa**
(kolačić, pre ispisa) — otvoriš zapis iz 2025 i cela aplikacija je u 2025, ne ostaje 2027. Promena
godine na samom detalju vodi na listu te godine (inače bi je detalj odmah vratio).

## 8. Čekanje = spinner sa porukom

Sve što se učitava ili generiše preko AJAX-a (pregled, konverzija, JSON lista) prikazuje spinner +
**šta se radi** + brojač sekundi (`window.cekanje(el, poruka)` u `app-ui.js`). Nikad prazan prostor
ni goli spinner; greška zamenjuje spinner porukom i dugmetom „Preuzmi".

## 9. Saglasnost i potpis u aplikaciji umesto „saglasan" odgovorom na mejl

Kad neko šalje dokument mejlom da bi drugi odgovorili „saglasan" (komisija, rukovodilac), to ide kroz aplikaciju.
- **Zahtev** se pokreće sa mesta gde dokument živi (korak procesa): dokumenta + lica (podrazumevano uloge sa
  tog zapisa, npr. komisija i zamenici) + poruka + rok.
- **Član** dobija mejl samo sa linkom (bez priloga) i brojač u meniju/na kontrolnoj tabli; na stranici zahteva
  dokumenta u lightbox-u (i ZIP ponude), dva dugmeta: „Saglasan sam" / „Imam primedbu" (komentar obavezan), AJAX.
- **Evidencija**: ko, kada, komentar, **sha1 dokumenta u trenutku zahteva** — izmenjen fajl ne može da dobije
  saglasnost, traži se novi krug („Traži ponovo"); zapisnik za štampu. Svi saglasni → korak urađen sam; onaj ko
  je tražio dobija mejl o ishodu. Podsetnik samo onima koji nisu odgovorili; „Povuci".
- **Ista mehanika za potpis izjave** (vrsta zahteva `potpis`, npr. izjava o nepostojanju sukoba interesa koju
  potpisuju svi članovi komisije): dugmad „Potpisujem izjavu" / „Prijavljujem sukob interesa" (opis obavezan);
  kad svi potpišu, aplikacija napravi **potpisanu verziju** dokumenta — umesto linije za potpis „potvrđeno u
  aplikaciji <datum u vreme>", datum dokumenta = poslednji potpis. Korak se pri tome ne označava.
- **Identitet**: lice je radnik (šifarnik, uloge na zapisu) i/ili nalog; veza se pravi sama pri prijavi
  (ćirilica = latinica po imenu i prezimenu, ili mejl). Pozvani bez naloga dobija nalog pri AD prijavi i kad je
  automatsko kreiranje naloga isključeno.
- Ovo je interna saglasnost/potvrda, ne kvalifikovani elektronski potpis — u tekstu i zapisniku to piše.

## 10. Mejl iz aplikacije

SMTP podešavanja **unosi administrator u aplikaciji** (Podešavanja → Mejl: uključeno, server, port, šifrovanje
bez/STARTTLS/SSL, korisnik/lozinka (prazno = zadrži sačuvanu), pošiljalac, adresa aplikacije za linkove,
provera sertifikata) sa dugmetom **„Pošalji probni"** koji koristi i još nesačuvane vrednosti. Bez podešenog
mejla funkcije rade, obaveštenje je samo u aplikaciji. U Laravel-u: `Mail` + vrednosti iz tabele podešavanja
u runtime `config()`; u čistom PHP-u: `assets/php/mail.php` (SMTP klijent bez biblioteka).
**AD najčešće nema mejl adrese** (`mail` prazan; UPN nije prava adresa) — adresa je polje na nalogu, korisnik
može sam da je upiše (na stranici zahteva), admin u formi korisnika.

## 11. Dokumenti na obrascu službe (Word)

Kad služba ima svoj Word obrazac (zahtev, rešenje, izjava), aplikacija ga popunjava — ne pravi novi izgled:
- **Šablon = pravi dokument iz arhive** (isti logo, zaglavlje, potpis, numerisana lista), u kome su vrednosti
  zamenjene poljima `${polje}`. Pasusi sa poljem se svode na jedan run (isti format) da se polje ne raspadne.
- **Red koji se ponavlja** (članovi komisije, „Dostaviti: <lice>, ____") je jedan pasus sa poljem čija vrednost
  ima više redova → pasus se ponavlja za svaki red. Polje sa `null` briše pasus.
- **Forma sa predlogom** iz podataka (lica sa uloge na zapisu, broj/datum ugovora iz spoljnog sistema, sledeći
  podbroj u delovodniku, članovi/datumi pravilnika iz podešavanja — pamti se poslednje uneto), sve se može
  izmeniti; dugmad Pregledaj (docx-preview u modalu), Preuzmi .docx, **Sačuvaj uz korak** (dokument koraka,
  lightbox, mrežni disk) i po potrebi **Pošalji na saglasnost/potpis** (odeljak 9).
- Šta dokument odlučuje upiši i u podatke: rešenje o imenovanju lica → uloga „praćenje" na zapisu (vidi se u
  osnovnim podacima i na stranici radnika „Prati ugovore"), izjava → članovi komisije na zapisu.
- Proveri: tekst generisanog dokumenta uporedi sa originalom iz arhive, i pretvori ga u PDF (LibreOffice) da vidiš izgled.

