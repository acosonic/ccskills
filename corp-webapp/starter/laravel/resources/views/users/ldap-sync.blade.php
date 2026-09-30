@extends('layouts.app')
@section('title', __('app.ldap_import'))

@section('content')
<div class="d-flex align-items-center mb-4 gap-3">
    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <div>
        <h2 class="fw-bold mb-0"><i class="bi bi-building-down me-2"></i>{{ __('app.ldap_import') }}</h2>
        <p class="page-subtitle">{{ __('app.ldap_import_hint') }}</p>
    </div>
</div>

<div class="alert {{ $connection['ok'] ? 'alert-success' : 'alert-danger' }} py-2 small">
    <i class="bi {{ $connection['ok'] ? 'bi-check-circle' : 'bi-x-circle' }} me-1"></i>{{ $connection['msg'] }}
    · {{ __('app.ldap_new_count', ['count' => $newCount, 'total' => $total]) }}
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ __('app.search') }}...">
            </div>
            <div class="col-md-3">
                <select name="show" class="form-select">
                    @foreach(['new' => __('app.ldap_show_new'), 'existing' => __('app.ldap_show_existing'), 'all' => __('app.all')] as $v => $l)
                        <option value="{{ $v }}" {{ $show === $v ? 'selected' : '' }}>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search me-1"></i>{{ __('app.filter') }}</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" action="{{ route('users.ldap-import') }}">
    @csrf
    <div class="card border-0">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span>{{ __('app.ldap_users_found', ['count' => $rows->count()]) }}</span>
            <div class="d-flex gap-2 align-items-center">
                <label class="small text-muted fw-normal" for="import_role">{{ __('app.role') }}</label>
                <select name="role" id="import_role" class="form-select form-select-sm" style="width:auto">
                    @foreach(['worker','supervisor','admin'] as $r)
                        <option value="{{ $r }}" {{ config('ldap.default_role') === $r ? 'selected' : '' }}>{{ __('app.role_' . $r) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-primary" data-import-btn disabled>
                    <i class="bi bi-download me-1"></i>{{ __('app.ldap_import_selected') }} (<span data-sel-count>0</span>)
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:2.5rem"><input type="checkbox" class="form-check-input" data-select-all aria-label="{{ __('app.all') }}"></th>
                        <th>{{ __('app.name') }}</th>
                        <th>{{ __('app.username') }}</th>
                        <th>{{ __('app.email') }}</th>
                        <th>{{ __('app.ldap_department') }}</th>
                        <th>{{ __('app.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr class="{{ $r['disabled'] ? 'opacity-50' : '' }}">
                        <td>
                            @if(!$r['exists'] && !$r['disabled'])
                            <input type="checkbox" name="sel[]" value="{{ $r['username'] }}" class="form-check-input" data-sel aria-label="{{ $r['username'] }}">
                            @endif
                        </td>
                        <td class="fw-semibold">{{ trim($r['ime'] . ' ' . $r['prezime']) }}</td>
                        <td class="font-monospace small">{{ $r['username'] }}</td>
                        <td class="small">{{ $r['mail'] ?: '–' }}</td>
                        <td class="small text-muted">{{ $r['department'] ?: '–' }}</td>
                        <td>
                            @if($r['exists'])
                                <span class="badge bg-success">{{ __('app.ldap_already_imported') }}</span>
                            @elseif($r['disabled'])
                                <span class="badge bg-secondary">{{ __('app.ldap_disabled') }}</span>
                            @else
                                <span class="badge bg-primary">{{ __('app.ldap_new') }}</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">{{ __('app.no_records') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var all = document.querySelector('[data-select-all]');
    var boxes = Array.from(document.querySelectorAll('[data-sel]'));
    var btn = document.querySelector('[data-import-btn]');
    var count = document.querySelector('[data-sel-count]');
    function update() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        count.textContent = n;
        btn.disabled = n === 0;
        if (all) all.checked = n > 0 && n === boxes.length;
    }
    if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); update(); });
    boxes.forEach(function (b) { b.addEventListener('change', update); });
    update();
})();
</script>
@endpush
