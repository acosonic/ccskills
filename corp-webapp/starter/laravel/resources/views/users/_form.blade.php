{{-- Shared create/edit user fields. Expects optional $user. --}}
@php $u = $user ?? null; $isMe = $u && $u->id === auth()->id(); @endphp

<div class="mb-3">
    <label class="form-label fw-semibold" for="u_name">{{ __('app.name') }} <span class="text-danger">*</span></label>
    <input type="text" name="name" id="u_name" value="{{ old('name', $u?->name) }}"
           class="form-control @error('name') is-invalid @enderror" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold" for="u_username">{{ __('app.username') }}</label>
        <input type="text" name="username" id="u_username" value="{{ old('username', $u?->username) }}"
               class="form-control font-monospace @error('username') is-invalid @enderror"
               placeholder="{{ __('app.ph_username') }}" autocomplete="off" autocapitalize="none" spellcheck="false">
        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">{{ __('app.username_hint') }}</div>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold" for="u_email">{{ __('app.email') }}</label>
        <input type="email" name="email" id="u_email" value="{{ old('email', $u?->email) }}"
               class="form-control @error('email') is-invalid @enderror" autocomplete="off">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<label class="ldap-option mb-3 {{ old('ldap_user', $u?->ldap_user) ? 'checked' : '' }}" for="u_ldap">
    <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" name="ldap_user" id="u_ldap" value="1"
           {{ old('ldap_user', $u?->ldap_user) ? 'checked' : '' }}
           onchange="this.closest('.ldap-option').classList.toggle('checked', this.checked)">
    <span>
        <strong class="d-block small"><i class="bi bi-building me-1 text-primary"></i>{{ __('app.ldap_account') }}</strong>
        <span class="text-muted" style="font-size:.75rem">{{ __('app.ldap_account_hint') }}</span>
    </span>
</label>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold" for="u_password">
            {{ __('app.password') }}
            @if($u)<small class="text-muted fw-normal">{{ __('app.password_keep_hint') }}</small>@endif
        </label>
        <div class="input-group">
            <input type="password" name="password" id="u_password"
                   class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" title="{{ __('app.show_password') }}" aria-label="{{ __('app.show_password') }}"
                    onclick="var f=document.getElementById('u_password'),c=document.getElementById('u_password_confirmation'),s=f.type==='password';f.type=c.type=s?'text':'password';this.firstElementChild.className=s?'bi bi-eye-slash':'bi bi-eye'">
                <i class="bi bi-eye"></i>
            </button>
        </div>
        @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold" for="u_password_confirmation">{{ __('app.password_confirm') }}</label>
        <input type="password" name="password_confirmation" id="u_password_confirmation" class="form-control" autocomplete="new-password">
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold" for="u_role">{{ __('app.role') }} <span class="text-danger">*</span></label>
        <select name="role" id="u_role" class="form-select @error('role') is-invalid @enderror" required>
            @foreach(['admin','supervisor','worker'] as $r)
                <option value="{{ $r }}" {{ old('role', $u?->role ?? 'worker') === $r ? 'selected' : '' }}>{{ __('app.role_' . $r) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold" for="u_lang">{{ __('app.language_preference') }}</label>
        <select name="language_preference" id="u_lang" class="form-select">
            <option value="sr" {{ old('language_preference', $u?->language_preference ?? 'sr') === 'sr' ? 'selected' : '' }}>Srpski</option>
            <option value="en" {{ old('language_preference', $u?->language_preference) === 'en' ? 'selected' : '' }}>English</option>
        </select>
    </div>
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" class="form-check-input" id="u_active" value="1"
           {{ old('is_active', $u?->is_active ?? true) ? 'checked' : '' }} {{ $isMe ? 'disabled' : '' }}>
    <label class="form-check-label fw-semibold" for="u_active">{{ __('app.is_active') }}</label>
    @if($isMe)<div class="form-text">{{ __('app.cannot_deactivate_self') }}</div>@endif
</div>
