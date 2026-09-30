@extends('layouts.app')
@section('title', __('app.dashboard'))

{{-- Starter dashboard: replace the cards with the application's own figures. --}}
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1 class="page-title mb-0">{{ __('app.dashboard_greeting', ['name' => Str::before(auth()->user()->name, ' ')]) }}</h1>
        <p class="page-subtitle">{{ config('app.name') }} · {{ now()->format('d.m.Y') }}</p>
    </div>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['users_total', $usersTotal, 'bi-people', route('users.index')],
        ['users_active', $usersActive, 'bi-person-check', route('users.index')],
        ['users_ad', $usersAd, 'bi-building', route('users.index')],
    ] as [$label, $value, $icon, $href])
    <div class="col-sm-6 col-xl-4">
        <a href="{{ auth()->user()->isAdmin() ? $href : '#' }}" class="card kpi-card">
            <div class="card-body">
                <div class="kpi-label">{{ __('app.' . $label) }} <i class="bi {{ $icon }}"></i></div>
                <div class="kpi-value">{{ $value }}</div>
            </div>
        </a>
    </div>
    @endforeach
</div>

<div class="card">
    <div class="card-header">{{ __('app.recent_users') }}</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>{{ __('app.name') }}</th><th>{{ __('app.username') }}</th><th>{{ __('app.role') }}</th><th>{{ __('app.created_at') }}</th></tr>
            </thead>
            <tbody>
                @foreach($recentUsers as $u)
                <tr>
                    <td class="fw-semibold">{{ $u->name }} @if($u->ldap_user)<span class="badge bg-info ms-1">AD</span>@endif</td>
                    <td class="font-monospace small">{{ $u->username ?: '–' }}</td>
                    <td><span class="badge bg-secondary">{{ __('app.role_' . $u->role) }}</span></td>
                    <td class="small text-muted">{{ $u->created_at?->format('d.m.Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
