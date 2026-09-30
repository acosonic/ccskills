@extends('layouts.app')
@section('title', __('app.users'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0"><i class="bi bi-people me-2"></i>{{ __('app.users') }}</h2>
    <div class="d-flex gap-2">
        @if($ldapEnabled)
        <a href="{{ route('users.ldap-sync') }}" class="btn btn-outline-secondary">
            <i class="bi bi-building-down me-1"></i>{{ __('app.ldap_import') }}
        </a>
        @endif
        <a href="{{ route('users.create') }}" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i>{{ __('app.user_new') }}
        </a>
    </div>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form action="{{ route('users.index') }}" method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="{{ __('app.search') }}...">
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select">
                    <option value="">{{ __('app.all_roles') }}</option>
                    @foreach(['admin','supervisor','worker'] as $r)
                        <option value="{{ $r }}" {{ request('role') === $r ? 'selected' : '' }}>{{ __('app.role_' . $r) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>{{ __('app.filter') }}
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ __('app.name') }}</th>
                    <th>{{ __('app.username') }}</th>
                    <th>{{ __('app.email') }}</th>
                    <th>{{ __('app.role') }}</th>
                    <th>{{ __('app.status') }}</th>
                    <th>{{ __('app.created_at') }}</th>
                    <th class="text-end">{{ __('app.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                @php $isMe = $user->id === auth()->id(); @endphp
                <tr>
                    <td class="fw-semibold">
                        {{ $user->name }}
                        @if($isMe)<span class="badge bg-primary ms-1">{{ __('app.you') }}</span>@endif
                    </td>
                    <td class="font-monospace small">
                        {{ $user->username ?: '–' }}
                        @if($user->ldap_user)<span class="badge bg-info ms-1" title="{{ __('app.ldap_account') }}">AD</span>@endif
                    </td>
                    <td class="small">{{ $user->email ?: '–' }}</td>
                    <td><span class="badge bg-secondary">{{ __('app.role_' . $user->role) }}</span></td>
                    <td>
                        @if($user->is_active)
                            <span class="badge bg-success">{{ __('app.status_active') }}</span>
                        @else
                            <span class="badge bg-danger">{{ __('app.status_inactive') }}</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $user->created_at->format('d.m.Y') }}</td>
                    <td class="text-end">
                        <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="{{ __('app.edit') }}">
                            <i class="bi bi-pencil"></i>
                        </a>
                        @if(!$isMe)
                        <form action="{{ route('users.toggle-active', $user) }}" method="POST" class="d-inline" data-ajax>
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $user->is_active ? 'btn-outline-warning' : 'btn-outline-success' }} ms-1"
                                    title="{{ $user->is_active ? __('app.deactivate') : __('app.activate') }}">
                                <i class="bi {{ $user->is_active ? 'bi-pause-circle' : 'bi-play-circle' }}"></i>
                            </button>
                        </form>
                        <form action="{{ route('users.destroy', $user) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('{{ __('app.confirm_delete') }}')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger ms-1" title="{{ __('app.delete') }}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-5">{{ __('app.no_records') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())
    <div class="card-footer bg-white">{{ $users->links() }}</div>
    @endif
</div>
@endsection
