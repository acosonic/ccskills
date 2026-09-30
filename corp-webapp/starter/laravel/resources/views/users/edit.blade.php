@extends('layouts.app')
@section('title', __('app.edit') . ' ' . $user->name)

@section('content')
<div class="d-flex align-items-center mb-4">
    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h2 class="fw-bold mb-0">{{ __('app.edit') }}: {{ $user->name }}</h2>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card border-0">
            <div class="card-body">
                <form action="{{ route('users.update', $user) }}" method="POST">
                    @csrf @method('PUT')

                    @include('users._form')

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i>{{ __('app.save') }}
                        </button>
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary px-4">{{ __('app.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
