<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('app.login') }} · {{ config('app.name') }}</title>
    @include('layouts._head')
</head>
<body>
<div class="auth-shell">
    <aside class="auth-aside">
        <div class="brand">
            <img src="{{ asset(config('brand.mark_white')) }}" alt="" width="56" height="56">
            <span>{{ config('brand.org') }} · {{ config('app.name') }}</span>
        </div>
        <div>
            <blockquote>{{ __('app.auth_pitch', ['org' => config('brand.org')]) }}</blockquote>
            <ul>
                @foreach(trans('app.auth_features') as [$icon, $text])
                    <li><i class="bi {{ $icon }}"></i>{{ $text }}</li>
                @endforeach
            </ul>
        </div>
        <small class="opacity-75">&copy; {{ date('Y') }} {{ config('brand.org') }}{{ config('brand.location') ? ', ' . config('brand.location') : '' }}</small>
    </aside>

    <main class="auth-main">
        <div class="auth-top">
            <div class="app-segmented" role="group" aria-label="{{ __('app.language') }}">
                <a href="{{ route('lang.switch', 'sr') }}" class="{{ app()->getLocale() === 'sr' ? 'active' : '' }}">SR</a>
                <a href="{{ route('lang.switch', 'en') }}" class="{{ app()->getLocale() === 'en' ? 'active' : '' }}">EN</a>
            </div>
            <button type="button" class="btn-icon bordered" data-theme-toggle
                    aria-label="{{ __('app.toggle_theme') }}" title="{{ __('app.toggle_theme') }}">
                <i class="bi bi-moon theme-icon-light"></i>
                <i class="bi bi-sun theme-icon-dark"></i>
            </button>
        </div>

        <div class="auth-form">
            <img src="{{ asset(config('brand.logo')) }}" alt="{{ config('brand.org') }}" width="210" height="56" class="auth-logo">
            <h1 class="h3 fw-semibold mb-1" style="letter-spacing:-.02em">{{ __('app.sign_in') }}</h1>
            <p class="text-muted mb-4">{{ __('app.app_tagline', ['org' => config('brand.org')]) }}</p>

            @if($errors->any())
                <div class="alert alert-danger py-2">
                    @foreach($errors->all() as $error)
                        <div><i class="bi bi-exclamation-circle me-1"></i>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form action="{{ route('login') }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label for="login" class="form-label fw-semibold">{{ __('app.login_identifier') }}</label>
                    <input type="text" name="login" id="login" value="{{ old('login') }}"
                           class="form-control @error('login') is-invalid @enderror"
                           required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold">{{ __('app.password') }}</label>
                    <div class="input-group">
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="password"
                                title="{{ __('app.show_password') }}" aria-label="{{ __('app.show_password') }}">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                @if($ldapEnabled)
                <label class="ldap-option mb-3" for="use_ldap">
                    <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" name="use_ldap" id="use_ldap" value="1"
                           {{ old('use_ldap', request()->cookie('login_ldap')) ? 'checked' : '' }}>
                    <span>
                        <strong class="d-block small"><i class="bi bi-building me-1 text-primary"></i>{{ __('app.ldap_login') }}</strong>
                        <span class="text-muted" style="font-size:.75rem">{{ __('app.ldap_login_hint', ['domain' => config('ldap.domain_label')]) }}</span>
                    </span>
                </label>
                @endif

                <div class="mb-4 form-check">
                    <input type="checkbox" name="remember" class="form-check-input" id="remember">
                    <label class="form-check-label small" for="remember">{{ __('app.remember_me') }}</label>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <span data-login-label data-local="{{ __('app.sign_in') }}" data-ldap="{{ __('app.sign_in_ad') }}">{{ __('app.sign_in') }}</span><i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>
<script>
// Show/hide password; AD checkbox switches the button label and is remembered for next time.
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var f = document.getElementById(btn.dataset.togglePassword);
        var show = f.type === 'password';
        f.type = show ? 'text' : 'password';
        btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});
(function () {
    var chk = document.getElementById('use_ldap');
    var label = document.querySelector('[data-login-label]');
    if (!chk || !label) return;
    function update() {
        chk.closest('.ldap-option').classList.toggle('checked', chk.checked);
        label.textContent = chk.checked ? label.dataset.ldap : label.dataset.local;
        document.cookie = 'login_ldap=' + (chk.checked ? '1' : '') + '; path=/; max-age=31536000; SameSite=Lax';
    }
    chk.addEventListener('change', update);
    update();
})();
document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var root = document.documentElement;
        var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('app-theme', next); } catch (e) {}
    });
});
</script>
</body>
</html>
