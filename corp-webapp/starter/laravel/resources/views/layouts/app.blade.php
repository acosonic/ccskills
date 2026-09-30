@if(request()->header('X-Fragment') === '1')
{{-- Fragment: page content only, loaded by app-ui.js into the page, a modal or a sheet --}}
<div class="fragment" data-title="@yield('title', config('app.name'))" data-errors="{{ $errors->any() ? 1 : 0 }}"
     data-success="{{ session('success') }}" data-error="{{ session('error') }}">
    @stack('styles')
    @include('layouts._errors')
    @yield('content')
    @stack('scripts')
</div>
@else
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name')) · {{ config('app.name') }}</title>
    @include('layouts._head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
    @stack('styles')
</head>
<body>
@php
    $user = auth()->user();
    // Sidebar menu from config/navigation.php: [route, active pattern, icon, label key, roles|null].
    $navGroups = collect(config('navigation'))->map(fn($items) => array_values(array_filter($items,
        fn($i) => ($i[4] ?? null) === null || in_array($user->role, $i[4], true))))->filter();
    $initials = collect(explode(' ', $user->name))->filter()->take(2)->map(fn($p) => mb_substr($p, 0, 1))->implode('');
@endphp

<div class="app-shell">
    {{-- Sidebar --}}
    <aside class="app-sidebar" id="appSidebar" aria-label="{{ __('app.main_navigation') }}">
        <a class="app-sidebar-brand" href="{{ route('dashboard') }}" title="{{ config('app.name') }}">
            <span class="brand-mark">
                <img src="{{ asset(config('brand.mark_white')) }}" alt="{{ config('brand.org') }}" width="56" height="56">
            </span>
            <span class="brand-text">
                <strong>{{ config('app.name') }}</strong>
                <small>{{ config('brand.org') }}{{ config('brand.location') ? ' · ' . config('brand.location') : '' }}</small>
            </span>
        </a>

        <nav class="app-sidebar-nav">
            @foreach($navGroups as $group => $items)
                <div class="app-nav-label">{{ __('app.' . $group) }}</div>
                @foreach($items as [$route, $pattern, $icon, $label])
                    @php $text = __('app.' . $label); @endphp
                    @php $active = request()->routeIs($pattern); @endphp
                    <a href="{{ route($route) }}" class="app-nav-link {{ $active ? 'active' : '' }}"
                       title="{{ $text }}" @if($active) aria-current="page" @endif>
                        <i class="bi {{ $icon }}"></i><span>{{ $text }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="app-sidebar-footer dropup">
            <button class="app-user" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="app-avatar">{{ $initials }}</span>
                <span class="who">
                    <strong>{{ $user->name }}</strong>
                    <small>{{ __('app.role_' . $user->role) }}</small>
                </span>
                <i class="bi bi-chevron-expand text-muted"></i>
            </button>
            <ul class="dropdown-menu w-100">
                <li><span class="dropdown-item-text small text-muted">{{ $user->email }}</span></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-box-arrow-right me-2"></i>{{ __('app.logout') }}
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </aside>
    <div class="app-backdrop" data-sidebar-close></div>

    {{-- Main --}}
    <div class="app-main">
        <div class="app-inset">
            <header class="app-header">
                <button type="button" class="btn-icon" data-sidebar-toggle
                        aria-controls="appSidebar" aria-label="{{ __('app.toggle_sidebar') }}" title="{{ __('app.toggle_sidebar') }}">
                    <i class="bi bi-layout-sidebar"></i>
                </button>
                <span class="app-header-sep"></span>
                <span class="app-header-title">@yield('title', config('app.name'))</span>

                <div class="ms-auto d-flex align-items-center gap-2">
                    <div class="app-segmented" role="group" aria-label="{{ __('app.language') }}">
                        <a href="{{ route('lang.switch', 'sr') }}" class="{{ app()->getLocale() === 'sr' ? 'active' : '' }}">SR</a>
                        <a href="{{ route('lang.switch', 'en') }}" class="{{ app()->getLocale() === 'en' ? 'active' : '' }}">EN</a>
                    </div>
                    <button type="button" class="btn-icon bordered" data-tour-start
                            aria-label="{{ __('app.start_tour') }}" title="{{ __('app.start_tour') }}">
                        <i class="bi bi-question-lg"></i>
                    </button>
                    <button type="button" class="btn-icon bordered" data-theme-toggle
                            aria-label="{{ __('app.toggle_theme') }}" title="{{ __('app.toggle_theme') }}">
                        <i class="bi bi-moon theme-icon-light"></i>
                        <i class="bi bi-sun theme-icon-dark"></i>
                    </button>
                </div>
            </header>

            <main class="app-content">
                @include('layouts._errors')
                <div id="appFlash" hidden data-success="{{ session('success') }}" data-error="{{ session('error') }}"></div>

                @yield('content')
            </main>
        </div>
    </div>
</div>

{{-- Overlays used by public/js/app-ui.js: page-in-modal, page-in-sheet, confirm dialog, toasts --}}
<div class="modal fade app-modal" id="appModal" tabindex="-1" aria-hidden="true" aria-labelledby="appModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('app.close') }}"></button>
            </div>
            <div class="modal-body"></div>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end app-sheet" id="appSheet" tabindex="-1" aria-labelledby="appSheetTitle">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title text-truncate" id="appSheetTitle"></h5>
        <div class="d-flex align-items-center gap-1 ms-auto">
            <a href="#" class="btn-icon" data-sheet-full title="{{ __('app.open_full_page') }}" aria-label="{{ __('app.open_full_page') }}">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <button type="button" class="btn-icon" data-bs-dismiss="offcanvas" aria-label="{{ __('app.close') }}"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <div class="offcanvas-body"></div>
</div>

<div class="modal fade" id="appConfirm" tabindex="-1" aria-hidden="true" aria-labelledby="appConfirmTitle">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body pt-4">
                <h5 class="fw-semibold mb-2" id="appConfirmTitle">{{ __('app.confirm_title') }}</h5>
                <p class="text-muted mb-0" data-confirm-message></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('app.cancel') }}</button>
                <button type="button" class="btn btn-danger" data-confirm-ok>{{ __('app.delete') }}</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="appToasts"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var root = document.documentElement;
    var desktop = window.matchMedia('(min-width: 992px)');

    function store(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }

    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (desktop.matches) {
                store('app-sidebar-collapsed', root.classList.toggle('sidebar-collapsed') ? '1' : '0');
            } else {
                root.classList.toggle('sidebar-open');
            }
        });
    });
    document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
        el.addEventListener('click', function () { root.classList.remove('sidebar-open'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') root.classList.remove('sidebar-open');
    });

    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-bs-theme', next);
            store('app-theme', next);
            refreshBaseLayers();
        });
    });

    // Map base layer: CARTO Positron (light) / Dark Matter (dark), follows the theme toggle.
    // In dark mode the street names come from a separate labels-only layer that is brightened
    // in theme.css (.app-map-labels) — Dark Matter's built-in labels are too dim to read.
    var baseLayers = [];
    var cartoKey = @json(config('maps.carto_key'));
    window.APP_MAP_CENTER = @json(config('maps.center')); // L.map(el).setView(APP_MAP_CENTER, 13)
    // Without a key CARTO serves watermarked tiles, and a key restricted to another domain
    // (e.g. on localhost) gets 403 — in both cases fall back to standard OSM tiles.
    function useOsm() { cartoKey = null; root.classList.add('tiles-osm'); }
    if (!cartoKey) useOsm();
    function isDark() { return root.getAttribute('data-bs-theme') === 'dark'; }
    function cartoUrl(style) {
        return 'https://basemaps.cartocdn.com/rastertiles/' + style + '/{z}/{x}/{y}{r}.png?key=' + encodeURIComponent(cartoKey);
    }
    function tileUrl() {
        if (!cartoKey) return 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
        return cartoUrl(isDark() ? 'dark_nolabels' : 'light_all');
    }
    function syncLabels(entry) {
        var map = entry.base._map;
        if (!map) return;
        var want = !!cartoKey && isDark();
        if (want && !entry.labels) {
            if (!map.getPane('appLabels')) {
                map.createPane('appLabels');
                map.getPane('appLabels').style.zIndex = 390;
                map.getPane('appLabels').style.pointerEvents = 'none';
            }
            entry.labels = L.tileLayer(cartoUrl('dark_only_labels'), { pane: 'appLabels', maxZoom: 20, className: 'app-map-labels' }).addTo(map);
        } else if (!want && entry.labels) {
            entry.labels.remove();
            entry.labels = null;
        }
    }
    function refreshBaseLayers() {
        baseLayers = baseLayers.filter(function (e) { return e.base._map; });
        baseLayers.forEach(function (e) {
            e.base.setUrl(tileUrl());
            syncLabels(e);
        });
    }
    window.appBaseLayer = function (map) {
        var layer = L.tileLayer(tileUrl(), {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                + (cartoKey ? ' &copy; <a href="https://carto.com/attributions">CARTO</a>' : ''),
            maxZoom: cartoKey ? 20 : 19
        }).addTo(map);
        var entry = { base: layer, labels: null };
        if (cartoKey) {
            layer.once('tileerror', function () {
                useOsm();
                refreshBaseLayers();
            });
        }
        baseLayers.push(entry);
        syncLabels(entry);
        return layer;
    };
})();
</script>
<script>
window.APP_UI = {
    modal: @json(config('ui.modal', [])),
    sheet: @json(config('ui.sheet', [])),
    confirmDelete: @json(__('app.confirm_delete')),
    error: @json(__('app.error_generic')),
    loading: @json(__('app.loading'))
};
</script>
<script src="{{ asset('js/app-ui.js') }}?v={{ filemtime(public_path('js/app-ui.js')) }}"></script>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script>window.APP_TOUR = @json(__('tour'));</script>
<script src="{{ asset('js/tour.js') }}?v={{ filemtime(public_path('js/tour.js')) }}"></script>
<script src="{{ asset('js/lightbox.js') }}?v={{ filemtime(public_path('js/lightbox.js')) }}"></script>
@stack('scripts')
</body>
</html>
@endif
