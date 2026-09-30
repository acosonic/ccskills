# AD (LDAP) prijava, admin korisnici i uvoz iz AD

Izvor: `starter/laravel/` (`app/Services/LdapService.php`, `config/ldap.php`, `AuthController`,
`UserController`, `resources/views/{auth,users}/*`). Sve je već uključeno u novu aplikaciju — ovo je
opis za dogradnju postojeće.

## Princip (samo čitanje)
1. Servisni bind → pretraga `(&(objectClass=user)(sAMAccountName=<escaped>))` u `LDAP_BASE_DN`,
   čitaju se DN, `givenName`, `sn`, `mail`, `userAccountControl`. Bit 2 u UAC = isključen → odbij.
2. Nova veza, bind sa DN-om korisnika i unetom lozinkom → uspeh = ispravna lozinka.
3. Lokalni nalog sa istim `username` (case-insensitive) daje ulogu i `is_active`. Ako ne postoji:
   greška „obratite se administratoru“, osim ako je `LDAP_AUTO_CREATE=true`.
Unos `DOMEN\korisnik` ili `korisnik@domen` svedi na `korisnik`. Uvek `ldap_escape(..., LDAP_ESCAPE_FILTER)`.

## Laravel — koraci
1. `Dockerfile`: `libldap2-dev`, `docker-php-ext-configure ldap --with-libdir=lib/$(uname -m)-linux-gnu`,
   `docker-php-ext-install ldap`. Ako build pada na composer-u zbog „security advisories“, vidi SKILL.md.
   Proveri: `docker compose exec app php -m | grep ldap`.
2. `config/ldap.php` ← `starter/laravel/config/ldap.php`; `.env` / `.env.example`:
   `LDAP_ENABLED LDAP_HOST LDAP_PORT LDAP_BIND_USER LDAP_BIND_PASS LDAP_BASE_DN LDAP_SEARCH_OU
   LDAP_USER_FILTER LDAP_DEFAULT_ROLE LDAP_AUTO_CREATE`. `LDAP_BIND_USER="CORP\\korisnik"` (dvostruki
   backslash u navodnicima). Lozinku prenesi bez ispisivanja (`grep ^LDAP_BIND_PASS= … | cut`).
3. `app/Services/LdapService.php` ← starter (`enabled()`, `authenticate()`, `users()`, `testConnection()`).
   Brzi test: `testConnection()` treba da vrati broj korisnika u OU.
4. Migracija ← starter (`database/migrations/…add_app_fields_to_users_table.php`): `users.username` (nullable, unique), `users.ldap_user` (bool), `email` nullable.
   Model: `username`, `ldap_user` u `$fillable`, `ldap_user` cast bool.
5. `AuthController` ← starter: polje `login` (email ili korisničko ime), `use_ldap`, provera `is_active`,
   `Auth::login($user, remember)`. Ruta: `->middleware(['guest', 'throttle:10,1'])`.
6. Login view ← `starter/laravel/resources/views/auth/login.blade.php` (AD opcija prikazana samo kad je
   `LdapService::enabled()`, kolačić `login_ldap` pamti izbor, oko za lozinku). CSS `.ldap-option` je u theme.css.
7. `bootstrap/app.php`: `encryptCookies(except: ['login_ldap', '<app>_tutorial_done'])`.
8. Admin: `UserController` ← starter (pravila: username ili email obavezan; AD nalog bez lozinke dobija
   `Str::random(40)`; `toggleActive`; ne može sebe deaktivirati/obrisati; `ldapSync` / `ldapImport` koji
   ponovo čita AD i ne uvozi isključene). Rute pre `Route::resource('users')`:
   `GET/POST /users/ldap-sync`, `POST /users/{user}/toggle-active`, sve pod `role:admin`.
   Pogledi: `users/_form`, `users/ldap-sync`, izmene u `users/index` (AD oznaka, „vi“, toggle sa `data-ajax`).
9. Seeder: demo nalozima dodaj `username`. Upozori ako seeder na svakom startu resetuje lozinke.
10. Prevodi: ključevi `login_identifier, show_password, ldap_login, ldap_login_hint, sign_in_ad,
    ldap_auth_failed, ldap_no_local_account, ldap_*` (tekstovi: `starter/laravel/lang/{sr,en}/app.php`).

## Čist PHP
Ista logika bez framework-a: `LdapService` iz startera prepiši u `includes/ldap_helper.php`
(bez `config()` — niz podešavanja iz okruženja ili tabele podešavanja), `login.php` sa checkbox-om
„Prijava preko Active Directory“, i `ldap-sync.php` za uvoz. Lozinku servisnog naloga čitaj iz
okruženja (`getenv('LDAP_BIND_PASS')`), nikad iz koda ili fajla u git-u. Dodaj ograničenje pokušaja
(npr. brojač u sesiji/bazi po IP).

## Provera
- nepostojeće korisničko ime + AD → poruka o neuspehu (bez lockout rizika);
- lokalna prijava korisničkim imenom i emailom; deaktiviran nalog odbijen;
- `/users/ldap-sync` lista naloge; uvoz jednog lokalno pa ga obriši; na produkciji ne uvoziti bez korisnika.
