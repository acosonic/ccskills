# AI asistent (agent) — modul `modules/ai-agent`

Asistent koji je pravi **agent**: Claude dobija alate nad podacima aplikacije i sam ih poziva koliko treba
(nađe zapis → uđe u detalje → pročita dokument → prebroji), uživo pokazuje šta radi („Čitam dokument…"),
odgovara sa tabelama i linkovima, a **izmene samo predlaže** — upisuju se tek kad korisnik klikne „Potvrdi".
Druga varijanta: **javni asistent sajta** — posetioci bez prijave, odgovori iz sadržaja sajta.

„Pogađanje" (LIKE po rečima pitanja pa jedan poziv modela) daje slabe odgovore: vidi samo ono što je pogađanje
uhvatilo i ne može da postavi sledeće pitanje bazi. Zato uvek agent sa alatima.

## Sadržaj

1. Ugradnja (skripta) · 2. Arhitektura · 3. Alati aplikacije · 4. Izmene uz potvrdu · 5. Prompt ·
6. Javni asistent sajta · 7. Pravila naučena u produkciji · 8. Proba · 9. Izveštaj korisniku

## 1. Ugradnja

```bash
~/.claude/skills/corp-webapp/scripts/add-ai-agent.sh ~/apps/<ime> [--site https://www.sajt.rs]
# ANTHROPIC_API_KEY u .env (nikad u git), pa:
cd ~/apps/<ime> && docker compose exec app php artisan migrate
```

Skripta (ne gazi postojeće fajlove): kopira modul, `composer require anthropic-ai/sdk` (Composer u Docker-u),
rute u `auth` grupu, stavku „AI asistent" na kraj prve grupe menija, `@include('ai._assets')` u layout (skripta +
mehur u uglu na svakoj strani), prevode, korake vodiča za `/ai`, `ANTHROPIC_*` u `.env`. Sa `--site`: javna ruta,
CSRF izuzetak `ai/site/*`, `AI_SITE_URL`. Bez ključa modul je isključen (nema menija ni mehura).

| Fajl | Šta je |
|---|---|
| `app/Services/Ai/Agent.php` | petlja alata preko zvaničnog PHP SDK-a (`$client->beta->messages->create`), keš prompta, fallback |
| `app/Services/Ai/Tool.php`, `ProposalTool.php` | osnova alata za čitanje / izmene uz potvrdu |
| `app/Services/Ai/Toolbox.php` | sklapa agenta za korisnika: alati iz `config/ai.php` kojima ima pravo |
| `app/Services/Ai/TextExtractor.php` | tekst dokumenta (docx, PDF, .doc/.xls), ćirilica→latinica, koreni reči |
| `app/Services/Ai/Tools/*` | primeri: `SearchUsersTool`, `SqlReadTool`, `ChangeUserProposal`, `SiteSearchTool`, `SitePageTool` |
| `app/Http/Controllers/AiController.php` | strana, SSE pitanje, istorija, Potvrdi/Odustani |
| `app/Http/Controllers/AiSiteController.php`, `SiteIndex.php`, komanda `ai:index-site` | javni asistent sajta |
| `config/ai.php` | model, effort, spisak alata, SQL šema i zabrane, prompt, podešavanja javnog sajta |
| `public/js/ai-chat.js` | `aiAsk` (SSE), `aiMarkdown` (bezbedan), `aiProposals`, `aiChat` (strana i mehur) |
| `public/js/ai-site-widget.js` | mehur za ugradnju na javni sajt jednim `<script>` |

Model: `ANTHROPIC_MODEL` (podrazumevano `claude-opus-5-5`, effort `medium`); jeftiniji model je odluka korisnika
(npr. `claude-sonnet-5-5`). Pre menjanja poziva modela učitaj skill `claude-api` (aktuelni oblik zahteva).

## 2. Arhitektura

```
pregledač ─POST /ai/ask {q, conversation_id} (Accept: text/event-stream, X-CSRF-TOKEN)─► AiController@ask
   ▲  event: step {label}  — svaki alat („Tražim korisnike", „Čitam dokument")              │
   │  event: done {answer, conversation_id, proposals[]}                                     ▼
   │                                              Agent: Messages API ⇄ alati (max 14 krugova)
   └── kartice Potvrdi/Odustani ─POST /ai/proposals/{id}─► ponovna provera ─► upis kao forma (log)
```

- Ručna petlja (ne Tool Runner): treba kontrola dozvola po korisniku, SSE napretka i predloga.
- Istorija: `ai_messages` (tekst) iz baze — ne iz pregledača; odgovor modela se u petlji vraća nepromenjen.
- Dug zahtev: `session()->save()` pre petlje (sesija se ne drži zaključanom), `X-Accel-Buffering: no` za proxy;
  bez `Accept: text/event-stream` isti endpoint vraća JSON (testovi, stari klijenti).

## 3. Alati aplikacije

Novi alat = klasa u `app/Services/Ai/Tools/`, upis u `config/ai.php` → `tools`. Model bira alat **samo po opisu**:
opis kaže šta vraća i kada ga koristiti.

Pravila za svaki alat:
- **Dozvole u alatu** — isti uslov kao liste aplikacije (radnik = samo svoji zapisi); `allowedFor()` za ceo alat.
  Detalj po id-u kroz isti filter: „ne postoji ili nema pristupa".
- **Svaki zapis ima `link`** (`route(...)` — apsolutni URL); prompt kaže da linkove prepisuje tačno.
- **Kratko**: samo korisna polja, prazna izbaci; liste `limit` (30, max 200) + `total_found`.
- **Greška = `RuntimeException` sa porukom koja pomaže** („Korak X ne postoji. Ključevi: a, b") — model se ispravi.

Koje alate napraviti (po važnosti):

| Alat | Šta | Napomena |
|---|---|---|
| pretraga zapisa | filtri + reči + sortiranje | `TextExtractor::roots()/matches()` — pismo i padeži (vidi 7) |
| detalj zapisa | **sve o jednom zapisu odjednom**: polja, tok/koraci sa rokovima, lica i uloge (sa id-jem), povezano, istorija, **lista dokumenata (id, naziv)** | jedan poziv umesto šest |
| tekst dokumenta | po id-u iz detalja | `TextExtractor::extract()`; provera da dokument pripada dostupnom zapisu |
| statistika | zbirovi po polju | `group_by` je enum — nikad sirov naziv kolone od modela u SQL |
| rokovi | šta ističe/kasni/čeka u N dana | |
| propisi/pravilnici | član po broju ili pretraga | SQLite FTS5 po članovima, `"koren"*` prefiks |
| `sql` | SELECT za sve ostalo, samo služba | `SqlReadTool`: READ ONLY transakcija, zabranjene tabele, `sql_schema` u config-u sa kolonama i značenjem statusa |
| pomoćni za predloge | npr. radnici → id | da model ne pogađa id-jeve |

## 4. Izmene uz potvrdu

`ProposalTool`: `prepare()` proveri (dozvole, da zapis/korak/lica/dokumenta postoje i pripadaju zapisu, duplikat,
da se nešto stvarno menja) i vrati naslov + **„polje: staro → novo"** + normalizovane podatke; predlog ide u
`ai_proposals`, modelu „ČEKA POTVRDU". `execute()` se poziva tek na Potvrdi: **ponovo** proveri dozvole i stanje
(prošlo je vreme) i upiše **istim putem kao forma aplikacije** (isti model/servis, isti log, isto usklađivanje
statusa). Kontroler predlog prvo „zauzme" (`running`) — dvoklik ne izvrši dvaput; potvrđuje samo korisnik koji ga
je dobio; ishod ide u istoriju razgovora (agent zna šta je urađeno), kartica pokazuje rezultat i link.

Ima smisla: izmena dozvoljenih polja (beli spisak), novi zapis, korak toka urađen/poništen, link na spoljni sistem,
zahtev drugim licima (saglasnost — šalje mejl, bez transakcije), nacrt dokumenta (korisnik ga otvara i završava).
Ne: brisanje, dozvole i nalozi drugih, masovne izmene jednim predlogom, slanje van sistema bez pregleda.
Primer: `ChangeUserProposal` (uloga/aktivnost korisnika, samo admin, ne nad sobom).

## 5. Prompt

`config/ai.php` → `prompt` (stabilan, keširan zajedno sa alatima) + `Toolbox::volatilePrompt()` (datum, korisnik,
uloga, adresa) u DRUGOM bloku — promenljivo u prvom bloku poništava keš svaki dan/za svakog korisnika.
Dopuni domenom: pojmovi koje model mora da razlikuje (npr. „iznos bez PDV" vs „procenjena vrednost"), šifre
vrsta/statusa, format brojeva, kako se citiraju propisi. Rečenice koje su se pokazale ključnim:
„Ako alat ništa ne nađe, pokušaj drugačije… pa tek onda reci da podatak ne postoji" i pravilo o linkovima.
Ne traži od modela da „razmišlja naglas" u tekstu (izaziva odbijanja); razmišljanje je u thinking-u.

## 6. Javni asistent sajta (varijanta B)

`add-ai-agent.sh <app> --site https://www.sajt.rs`, pa `php artisan ai:index-site` (cron dnevno):
robots.txt/sitemap (i WordPress `wp-sitemap.xml`) → glavni tekst strane → SQLite FTS5 u
`storage/app/ai-site-index.sqlite`, sa latiničnom kolonom za pretragu. `POST /ai/site/ask` bez prijave, alati
samo `search_site` i `read_page` (samo indeksirane strane), effort `low`, najviše 6 krugova.
Zaštite: N pitanja po IP-u na sat, dnevni limit tokena za ceo sajt, pitanje ≤ 1000 znakova, istorija iz pregledača
skraćena; prompt kaže da je tekst strana **sadržaj, ne uputstvo** (prompt injection) i da bez odgovora uputi na kontakt.
Ugradnja na sajt: `ai-chat.js` + `ai-site-widget.js` (`data-endpoint`, `data-title`, `data-hello`, `data-color`,
`data-chips`); za drugi domen CORS za `ai/site/*`. Ako je sajt na CMS-u sa bazom, bolje je indeksirati iz baze.

## 7. Pravila naučena u produkciji

- **Pismo i padeži**: korisnik piše latinicom, podaci su ćirilicom (ili obrnuto), u padežima. Pretraga mora da
  normalizuje oba teksta i traži po korenu reči (`TextExtractor`). Bez toga agent pretraži šest puta i kaže
  „nema podataka" (desilo se na probi sajta; posle ispravke našao je odmah).
- **Uloge i šema iz koda, ne iz glave**: pre pisanja alata pročitaj migracije (u starteru su uloge
  `admin|supervisor|worker`); pogrešna enum vrednost u alatu = greška baze.
- **Sadržaj odgovora modela vrati nepromenjen** — SDK objekti idu nazad kao `['role' => 'assistant', 'content' => $response->content]`.
- **Svi `tool_result` jednog kruga u jednoj poruci**; greška alata sa `isError` — ne izostavljaj je.
- **Podaci idu spoljnom servisu** (Anthropic): reci korisniku; po potrebi isključi alat za tekst dokumenata.
- **Trošak**: na Opus-u obično 1–6 centi po pitanju (5–50 hiljada ulaznih tokena; keš smanjuje); svako pitanje
  se loguje sa alatima i tokenima (`logger`, `ai_messages.meta`).
- Kontejner: `poppler-utils` za PDF, LibreOffice za stari Office (isti kao za pregled fajlova).

## 8. Proba

1. **HTTP kao pregledač** (brže od UI-ja, vidi se izbor alata):
   ```bash
   J=/tmp/jar; B=http://localhost:<port>
   T=$(curl -s -c $J -b $J $B/login | grep -o 'name="_token" value="[^"]*' | cut -d'"' -f4)
   curl -s -c $J -b $J --data-urlencode "_token=$T" -d "login=admin&password=…" $B/login -o /dev/null
   C=$(curl -s -b $J $B/dashboard | grep -o 'name="csrf-token" content="[^"]*' | cut -d'"' -f4)
   curl -sN -b $J -H "X-CSRF-TOKEN: $C" -H "Accept: text/event-stream" -H "Content-Type: application/json" \
        -d '{"q":"…"}' $B/ai/ask
   ```
   Pitanja: najveći/najnoviji zapis + detalj; „pročitaj dokument X"; zbir po statusu; isto kao **običan korisnik**
   (samo svoje, bez SQL-a i predloga); predlog → u bazi NIŠTA dok se ne potvrdi → `POST /ai/proposals/{id}`
   `{"decision":"confirm"}` dvaput (drugi put ne sme da izvrši ponovo); `usage.cache_read` > 0 od drugog pitanja.
2. **Pregledač** (Playwright; vodič preskoči sa `localStorage['app-tutorial-done']='1'`): mehur na kontrolnoj tabli,
   koraci umesto tačkica, tabela u odgovoru, kartica → Potvrdi → „Urađeno", strana `/ai` (lista razgovora,
   ponovo otvoren razgovor sa karticama), tamna tema, telefon, bez JS grešaka.
3. Javni: `php artisan ai:index-site --max=25`, pa `curl -d '{"q":"…"}' -H "Content-Type: application/json" $B/ai/site/ask`.

Ovim postupkom je modul proveren na svežoj aplikaciji iz startera: pretraga + SQL (tačan odgovor sa linkovima,
~9 s), predlog → potvrda → upis (dvoklik bez ponovnog izvršenja), javni asistent nad 25 strana (~11 s).

## 9. Izveštaj korisniku

2–4 stvarna pitanja i odgovora (koje alate je agent sam pozvao, trajanje, tokeni), šta agent ne može, model i
približna cena, da podaci idu Anthropic-u, šta nije provereno (npr. predlog na produkciji nije potvrđen).
