@extends('layouts.app')
@section('title', __('ai.title'))

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <h2 class="fw-bold mb-0">{{ __('ai.title') }}</h2>
    <span class="text-muted small ms-2">{{ __('ai.subtitle') }}</span>
</div>
<div class="row g-3" style="height: calc(100vh - 190px); min-height: 480px">
    <div class="col-lg-3 d-flex flex-column">
        <button type="button" class="btn btn-primary btn-sm mb-2" id="aiNew"><i class="bi bi-plus-lg me-1"></i>{{ __('ai.new_chat') }}</button>
        <div class="card border-0 flex-grow-1 overflow-auto">
            <div class="list-group list-group-flush small" id="aiList">
                @forelse ($conversations as $c)
                <div class="list-group-item list-group-item-action d-flex align-items-center gap-2" role="button" data-id="{{ $c->id }}">
                    <span class="klik-naziv text-truncate flex-grow-1">{{ $c->title }}</span>
                    <button type="button" class="btn btn-link btn-sm text-danger p-0" data-del="{{ $c->id }}" title="{{ __('app.delete') }}"><i class="bi bi-trash"></i></button>
                </div>
                @empty
                <div class="text-muted text-center py-4" id="aiEmpty">{{ __('ai.no_chats') }}</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-9 d-flex flex-column">
        <div class="card border-0 flex-grow-1 d-flex flex-column overflow-hidden" id="aiPage">
            <div class="flex-grow-1 overflow-auto p-3" data-ai-messages>
                <div class="ai-msg ai-msg-assistant"><div class="ai-bubble">{{ __('ai.hello') }}</div></div>
            </div>
            <div class="border-top p-2 d-flex gap-2">
                <textarea class="form-control" rows="2" data-ai-input placeholder="{{ __('ai.placeholder') }}"></textarea>
                <button type="button" class="btn btn-primary" data-ai-send><i class="bi bi-send"></i></button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var list = document.getElementById('aiList');
    var chat = aiChat(document.getElementById('aiPage'), { onAnswer: function () { refresh(); } });
    function refresh() {
        fetch(@json(route('ai.conversations')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) {
                list.innerHTML = d.conversations.map(function (c) {
                    var t = String(c.title).replace(/&/g, '&amp;').replace(/</g, '&lt;');
                    return '<div class="list-group-item list-group-item-action d-flex align-items-center gap-2" role="button" data-id="' + c.id + '">'
                         + '<span class="klik-naziv text-truncate flex-grow-1">' + t + '</span>'
                         + '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-del="' + c.id + '"><i class="bi bi-trash"></i></button></div>';
                }).join('');
            });
    }
    list.addEventListener('click', function (e) {
        var del = e.target.closest('[data-del]'), item = e.target.closest('[data-id]');
        if (del) {
            e.stopPropagation();
            fetch(AI.showUrl.replace('__ID__', del.dataset.del), { method: 'DELETE', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' } }).then(refresh);
            return;
        }
        if (!item) return;
        fetch(AI.showUrl.replace('__ID__', item.dataset.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) { chat.load(+item.dataset.id, d); });
        list.querySelectorAll('[data-id]').forEach(function (x) { x.classList.toggle('active', x === item); });
    });
    document.getElementById('aiNew').addEventListener('click', function () {
        chat.reset(); chat.add('assistant', @json(__('ai.hello')));
        list.querySelectorAll('[data-id]').forEach(function (x) { x.classList.remove('active'); });
    });
})();
</script>
@endpush
