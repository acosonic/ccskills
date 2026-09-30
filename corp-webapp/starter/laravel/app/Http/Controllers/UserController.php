<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LdapService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['admin', 'supervisor', 'worker'];

    public function index(Request $request, LdapService $ldap)
    {
        $query = User::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")
                                      ->orWhere('username', 'like', "%$s%")
                                      ->orWhere('email', 'like', "%$s%"));
        }
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $users = $query->orderBy('role')->orderBy('name')->paginate(25)->withQueryString();
        $ldapEnabled = $ldap->enabled();

        return view('users.index', compact('users', 'ldapEnabled'));
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $validated['ldap_user'] = $request->boolean('ldap_user');
        $validated['is_active'] = $request->boolean('is_active', true);
        // AD accounts check the password against Active Directory; the local one is random and unusable.
        $validated['password'] = $validated['ldap_user'] && empty($validated['password'])
            ? Str::random(40)
            : $validated['password'];

        User::create($validated);

        return redirect()->route('users.index')
            ->with('success', __('app.user_created'));
    }

    public function show(User $user)
    {
        return redirect()->route('users.edit', $user);
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate($this->rules($user));

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $validated['ldap_user'] = $request->boolean('ldap_user');
        // An admin cannot lock themselves out.
        $validated['is_active'] = $user->id === auth()->id() ? true : $request->boolean('is_active');

        $user->update($validated);

        return redirect()->route('users.index')
            ->with('success', __('app.user_updated'));
    }

    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', __('app.cannot_deactivate_self'));
        }
        $user->update(['is_active' => !$user->is_active]);

        return back()->with('success', __($user->is_active ? 'app.user_activated' : 'app.user_deactivated'));
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', __('app.cannot_delete_self'));
        }
        $user->delete();
        return redirect()->route('users.index')
            ->with('success', __('app.user_deleted'));
    }

    // ── Active Directory import (read-only on the AD side) ──────────────────

    public function ldapSync(Request $request, LdapService $ldap)
    {
        abort_unless($ldap->enabled(), 404);

        $show   = in_array($request->show, ['all', 'new', 'existing'], true) ? $request->show : 'new';
        $search = mb_strtolower(trim((string) $request->q));

        $adUsers  = $ldap->users();
        $existing = User::whereNotNull('username')->pluck('username')->map(fn($u) => mb_strtolower($u))->flip();

        $rows = collect($adUsers)
            ->map(fn($u) => $u + ['exists' => $existing->has(mb_strtolower($u['username']))])
            ->filter(fn($u) => $show === 'all' || ($show === 'new' ? !$u['exists'] : $u['exists']))
            ->filter(fn($u) => $search === '' || str_contains(mb_strtolower(
                $u['username'] . ' ' . $u['ime'] . ' ' . $u['prezime'] . ' ' . $u['mail'] . ' ' . $u['department']
            ), $search))
            ->values();

        return view('users.ldap-sync', [
            'rows'       => $rows,
            'total'      => count($adUsers),
            'newCount'   => collect($adUsers)->reject(fn($u) => $existing->has(mb_strtolower($u['username'])))->count(),
            'connection' => $ldap->testConnection(),
            'show'       => $show,
        ]);
    }

    public function ldapImport(Request $request, LdapService $ldap)
    {
        abort_unless($ldap->enabled(), 404);

        $data = $request->validate([
            'sel'   => 'required|array|min:1',
            'sel.*' => 'string|max:100',
            'role'  => ['required', Rule::in(self::ROLES)],
        ]);

        // Re-read AD so only real, enabled directory accounts can be imported.
        $selected = collect($data['sel'])->map(fn($u) => mb_strtolower($u))->flip();
        $created  = 0;

        foreach ($ldap->users() as $u) {
            if ($u['disabled'] || !$selected->has(mb_strtolower($u['username']))) continue;
            if (User::whereRaw('LOWER(username) = ?', [mb_strtolower($u['username'])])->exists()) continue;

            User::create([
                'name'                => trim($u['ime'] . ' ' . $u['prezime']) ?: $u['username'],
                'username'            => $u['username'],
                'email'               => $u['mail'] !== '' && !User::where('email', $u['mail'])->exists() ? $u['mail'] : null,
                'password'            => Str::random(40),
                'ldap_user'           => true,
                'role'                => $data['role'],
                'language_preference' => 'sr',
                'is_active'           => true,
            ]);
            $created++;
        }

        return redirect()->route('users.index')
            ->with('success', __('app.ldap_imported', ['count' => $created]));
    }

    private function rules(?User $user = null): array
    {
        return [
            'name'                => 'required|string|max:255',
            'username'            => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-]+$/',
                                      Rule::unique('users')->ignore($user?->id), 'required_without:email'],
            'email'               => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user?->id), 'required_without:username'],
            'password'            => $user
                ? 'nullable|string|min:8|confirmed'
                : 'nullable|string|min:8|confirmed|required_unless:ldap_user,1',
            'role'                => ['required', Rule::in(self::ROLES)],
            'language_preference' => 'required|in:en,sr',
            'is_active'           => 'boolean',
            'ldap_user'           => 'boolean',
        ];
    }
}
