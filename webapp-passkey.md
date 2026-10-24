# Webapp Passkeys (WebAuthn / FIDO2) — Laravel

Playbook for adding **passwordless passkey login** (fingerprint / Face ID / Windows Hello / phone) to a
Laravel web app, so users stop typing TOTP codes. Distilled from a real implementation (Unival SecureLink VPN
portal) with every pitfall hit in production. Passkeys are phishing-resistant and stronger than TOTP.

Use this skill when:
- A Laravel app has password (+ optional TOTP) login and you want to add passkeys / WebAuthn / "prijava bez lozinke".
- The user says passkey, Face ID, Touch ID, Windows Hello, fingerprint login, "da se ne smaram sa 2FA".
- You need passwordless **and** want to keep password/TOTP as a recovery fallback.

Out of scope:
- Non-Laravel stacks (the ceremony/gotchas still apply; swap the library).
- Passkeys as a *second factor only* (this playbook does passwordless primary + password/TOTP fallback).

## 0. Decide the domain FIRST (the one irreversible choice)

A passkey is bound to the **Relying Party ID = the domain** (e.g. `vpn.example.com`, no scheme, no port).
**If you move domains later, every passkey breaks** and must be re-registered. So before writing any code:

1. Pick the **final** hostname and point DNS at the app.
2. **If the app is IP-whitelisted** (`Require ip` / allowlist) and sits behind a CDN: make the record
   **DNS-only, NOT Cloudflare-proxied.** Proxied → the origin sees CDN IPs, not the real client, and the IP
   allowlist blocks everyone (or forces `mod_remoteip`). WebAuthn itself works either way; this is about not
   breaking your existing access control. (Same trap as any CF-fronted IP-restricted vhost.)
3. Issue a real TLS cert for that hostname (WebAuthn requires HTTPS; `localhost` is the only HTTP exception).
   Non-443 ports are fine — the **port is not part of the RP ID**, but it **is** part of the origin (step 2 below).

## 1. Library + wiring (laragear/webauthn v5, Laravel 12)

```bash
composer require laragear/webauthn        # v5 needs PHP 8.3+, ext-openssl (ext-sodium optional)
php artisan vendor:publish --provider="Laragear\WebAuthn\WebAuthnServiceProvider" --tag=migrations
php artisan migrate                        # creates webauthn_credentials
```
> GOTCHA: v5 does **not** auto-load the migration — you must publish it (`--tag=migrations`). `migrate` alone
> says "Nothing to migrate".

**`config/auth.php`** — swap the user provider driver so one provider handles both password and WebAuthn:
```php
'users' => [
    'driver' => 'eloquent-webauthn',   // was 'eloquent'
    'password_fallback' => true,        // still allow classic password login
    'model' => env('AUTH_MODEL', App\Models\User::class),
],
```

**`app/Models/User.php`** — implement the contract and use the trait:
```php
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;

class User extends Authenticatable implements WebAuthnAuthenticatable
{
    use HasFactory, Notifiable, WebAuthnAuthentication;
}
```
> GOTCHA (bit me): you MUST add the two `use Laragear\WebAuthn\...` **import** lines. If you only add the
> trait/interface names, PHP resolves them to `App\Models\WebAuthnAuthentication` and you get a 500
> `Trait "App\Models\WebAuthnAuthentication" not found` — which **does not get logged** when `APP_DEBUG=false`
> (it's a fatal before Laravel's handler). Flip `APP_DEBUG=true` for one request to see it.

**`.env`** — RP ID (bare domain) and origins (**must include the port** if non-443):
```
WEBAUTHN_NAME="My App"
WEBAUTHN_ID=vpn.example.com
WEBAUTHN_ORIGINS=https://vpn.example.com:8443
```

## 2. Routes + CSRF

```php
// routes/web.php
use App\Http\Controllers\WebAuthn\{LoginController, PasskeyController};

Route::middleware('guest')->group(function () {
    Route::post('/webauthn/login/options', [LoginController::class, 'options'])->name('webauthn.login.options');
    Route::post('/webauthn/login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('webauthn.login');
});
Route::middleware('auth')->group(function () {
    Route::get('/passkeys', [PasskeyController::class, 'index'])->name('passkeys.index');
    Route::post('/webauthn/register/options', [PasskeyController::class, 'options'])->name('webauthn.register.options');
    Route::post('/webauthn/register', [PasskeyController::class, 'register'])->name('webauthn.register');
    Route::delete('/passkeys/{id}', [PasskeyController::class, 'destroy'])->whereNumber('id')->name('passkeys.destroy');
});
```

**`bootstrap/app.php`** — exempt the ceremony routes from CSRF (WebAuthn is CSRF-resistant by design: fresh
server challenge + origin binding). Keep CSRF on `/passkeys` management.
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['webauthn/*']);
})
```

## 3. Controllers

```php
// app/Http/Controllers/WebAuthn/LoginController.php  — passwordless, guest
use Laragear\WebAuthn\Http\Requests\{AssertionRequest, AssertedRequest};

public function options(AssertionRequest $request)      // usernameless/discoverable
{
    return $request->toVerify();
}
public function login(AssertedRequest $request)
{
    $user = $request->login(callbacks: fn ($u) => (bool) $u->is_active);  // reject disabled accounts
    if (!$user) return response()->json(['ok' => false], 422);
    // login() already called Auth::login + session regenerate → fully authed, NO password/TOTP step
    $user->forceFill(['last_login_at' => now()])->save();
    return response()->json(['ok' => true, 'redirect' => route('dashboard')]);
}
```
```php
// app/Http/Controllers/WebAuthn/PasskeyController.php  — register/list/delete, auth required
use Laragear\WebAuthn\Http\Requests\{AttestationRequest, AttestedRequest};

public function options(AttestationRequest $request)
{
    return $request->userless()->toCreate();   // resident key → one-touch login, no username typed
}
public function register(AttestedRequest $request)
{
    $data = $request->validate(['alias' => 'nullable|string|max:40']);  // alias comes from ?alias=... query
    $request->save(['alias' => $data['alias'] ?? null]);
    return response()->json(['ok' => true]);
}
public function index(Request $request)   // list $request->user()->webAuthnCredentials()
public function destroy(Request $request, string $id)  // $user->webAuthnCredentials()->whereKey($id)->first()?->delete()
```
Key points: `userless()` makes a **resident/discoverable** credential (one-touch, no username). `is_active`
callback blocks disabled users. `AssertedRequest::login()` does the `Auth::login()` internally, so a passkey
login **bypasses the whole password→TOTP flow** — that's the win.

## 4. Front end (JS helper)

Use the official helper `@laragear/webpass`. **No CSP?** load from CDN. **Strict CSP?** self-host it (or allow
`cdn.jsdelivr.net`). Writing the base64url↔ArrayBuffer conversion by hand is error-prone — don't.

```html
<script src="https://cdn.jsdelivr.net/npm/@laragear/webpass@2/dist/webpass.js"></script>
<script>
// LOGIN button (on the login page, guest):
const { success } = await Webpass.assert('/webauthn/login/options', '/webauthn/login');
if (success) window.location.href = '/';
// REGISTER button (on /passkeys, authed) — pass the alias as a query param, AttestedRequest reads input():
const { success } = await Webpass.attest('/webauthn/register/options', '/webauthn/register?alias=' + encodeURIComponent(name));
if (success) window.location.reload();
// Guard: if (!window.Webpass || Webpass.isUnsupported()) disable the button.
</script>
```
Add a "Sign in with a passkey" button on login (next to password) and a "Passkeys" page/menu item to enrol &
delete. Keep the password + TOTP form — it's the recovery path.

## 5. Verify without a real authenticator

You can't script a biometric, but you can prove the backend:
```bash
curl -sk -X POST -H 'Host: vpn.example.com' -H 'Accept: application/json' \
     https://127.0.0.1:<port>/webauthn/login/options
# → 200  {"timeout":60000,"rpId":"vpn.example.com","challenge":"...."}   (no allowCredentials = usernameless)
```
`php artisan view:cache` to catch Blade errors; render the login page and grep for the passkey button. Then the
user does the real ceremony: enrol on /passkeys (Touch ID/Hello/phone), log out, "Sign in with a passkey".

## Pitfalls checklist (all hit in production)
1. **Domain churn** → dead passkeys. Lock the final hostname before enrolling any.
2. **CF-proxied + IP allowlist** → everyone blocked. Use DNS-only (or `mod_remoteip`).
3. **Port missing from `WEBAUTHN_ORIGINS`** → assertion origin mismatch. Include `:8443`.
4. **Missing `use` imports** for trait/interface → `App\Models\...` not found, unlogged 500. `APP_DEBUG=true` to see it.
5. **Migration not published** (v5) → no table. `vendor:publish --tag=migrations`.
6. **CSRF on ceremony routes** → 419. Exempt `webauthn/*`.
7. **No `is_active` callback** → disabled users can still passkey in.
8. **Hand-rolled JS** → base64url bugs. Use `@laragear/webpass`.
9. Keep **password + TOTP** as fallback; never make passkeys the only way in (lost-device lockout).
