<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LdapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Login: local account (username or e-mail + password)
 * or Active Directory (checkbox) — AD verifies the password, the local account holds role and status.
 */
class AuthController extends Controller
{
    public function showLogin(LdapService $ldap)
    {
        return view('auth.login', ['ldapEnabled' => $ldap->enabled()]);
    }

    public function login(Request $request, LdapService $ldap)
    {
        $request->validate([
            'login'    => 'required|string|max:255',
            'password' => 'required|string',
        ]);

        $login    = trim($request->input('login'));
        $password = $request->input('password');
        $useLdap  = $request->boolean('use_ldap') && $ldap->enabled();

        $user = $useLdap
            ? $this->ldapUser($ldap, $login, $password)
            : $this->localUser($login, $password);

        if (is_string($user)) {
            return back()->withErrors(['login' => $user])->onlyInput('login', 'use_ldap');
        }

        if (!$user->is_active) {
            return back()->withErrors(['login' => __('app.account_disabled')])->onlyInput('login', 'use_ldap');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        if ($user->language_preference) {
            session(['locale' => $user->language_preference]);
        }

        Log::info('login', ['user_id' => $user->id, 'method' => $useLdap ? 'ldap' : 'local', 'ip' => $request->ip()]);

        return redirect()->intended(route('dashboard'));
    }

    /** @return User|string user, or an error message */
    private function localUser(string $login, string $password): User|string
    {
        $user = User::query()
            ->where(fn($q) => $q->where('email', $login)->orWhereRaw('LOWER(username) = ?', [mb_strtolower($login)]))
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return __('auth.failed');
        }
        return $user;
    }

    /** @return User|string user, or an error message */
    private function ldapUser(LdapService $ldap, string $login, string $password): User|string
    {
        $info = $ldap->authenticate($login, $password);
        if (!$info) {
            return __('app.ldap_auth_failed');
        }

        $user = User::whereRaw('LOWER(username) = ?', [mb_strtolower($info['username'])])->first();

        if (!$user && config('ldap.auto_create')) {
            $user = User::create([
                'name'                => trim($info['ime'] . ' ' . $info['prezime']) ?: $info['username'],
                'username'            => $info['username'],
                'email'               => $info['mail'] !== '' && !User::where('email', $info['mail'])->exists() ? $info['mail'] : null,
                'password'            => Str::random(40), // unusable for local login
                'ldap_user'           => true,
                'role'                => config('ldap.default_role', 'worker'),
                'language_preference' => 'sr',
                'is_active'           => true,
            ]);
        }

        if (!$user) {
            return __('app.ldap_no_local_account', ['username' => $info['username']]);
        }
        return $user;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
