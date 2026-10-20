{{-- AI asistent: tekstovi, stil i skripta + mehur u uglu. U layouts/app.blade.php pre @stack('scripts'): @include('ai._assets') --}}
@if (config('ai.enabled') && auth()->check())
<script>
window.AI = {
    askUrl: @json(route('ai.ask')),
    decideUrl: @json(route('ai.decide', ['id' => '__ID__'])),
    showUrl: @json(route('ai.show', ['id' => '__ID__'])),
    thinking: @json(__('ai.thinking')), serverError: @json(__('ai.server_error')), incomplete: @json(__('ai.incomplete')),
    confirm: @json(__('ai.confirm')), cancel: @json(__('ai.cancel')), open: @json(__('ai.open')),
    saving: @json(__('ai.saving')), cancelling: @json(__('ai.cancelling')),
    status: @json(__('ai.status'))
};
</script>
<style>
.ai-msg { display: flex; margin-bottom: .6rem; }
.ai-msg-user { justify-content: flex-end; }
.ai-bubble { max-width: 85%; padding: .55rem .8rem; border-radius: .75rem; font-size: .9rem; line-height: 1.5; word-break: break-word; }
.ai-msg-user .ai-bubble { background: var(--app-brand); color: #fff; border-top-right-radius: .2rem; }
.ai-msg-assistant .ai-bubble { background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); border-top-left-radius: .2rem; }
.ai-bubble:has(table) { max-width: 100%; overflow-x: auto; }
.ai-bubble p:last-child { margin-bottom: 0; }
#aiFab { position: fixed; right: 22px; bottom: 22px; z-index: 1080; width: 54px; height: 54px; border-radius: 50%; border: 0;
         background: var(--app-brand); color: #fff; box-shadow: 0 6px 20px rgba(0,0,0,.25); font-size: 1.4rem; }
#aiPanel { position: fixed; right: 22px; bottom: 88px; z-index: 1080; width: min(420px, calc(100vw - 32px)); height: min(600px, calc(100vh - 120px));
           display: none; flex-direction: column; background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .9rem;
           box-shadow: 0 12px 40px rgba(0,0,0,.25); overflow: hidden; }
#aiPanel.show { display: flex; }
@media (max-width: 576px) { #aiPanel { right: 0; bottom: 0; width: 100vw; height: 100dvh; border-radius: 0; } }
</style>
<script src="{{ asset('js/ai-chat.js') }}?v={{ filemtime(public_path('js/ai-chat.js')) }}"></script>
@unless (request()->routeIs('ai.index'))
<button id="aiFab" type="button" title="{{ __('ai.title') }}" aria-label="{{ __('ai.title') }}"><i class="bi bi-stars"></i></button>
<div id="aiPanel" role="dialog" aria-label="{{ __('ai.title') }}">
    <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
        <i class="bi bi-stars text-primary"></i><strong class="small">{{ __('ai.title') }}</strong>
        <a href="{{ route('ai.index') }}" class="ms-auto small text-decoration-none" title="{{ __('ai.full_page') }}"><i class="bi bi-arrows-angle-expand"></i></a>
        <button type="button" class="btn-close btn-sm" data-ai-close aria-label="{{ __('ai.close') }}"></button>
    </div>
    <div class="flex-grow-1 overflow-auto p-3" data-ai-messages>
        <div class="ai-msg ai-msg-assistant"><div class="ai-bubble">{{ __('ai.hello') }}</div></div>
    </div>
    <div class="border-top p-2 d-flex gap-2">
        <textarea class="form-control form-control-sm" rows="1" data-ai-input placeholder="{{ __('ai.placeholder') }}"></textarea>
        <button type="button" class="btn btn-primary btn-sm" data-ai-send aria-label="{{ __('ai.send') }}"><i class="bi bi-send"></i></button>
    </div>
</div>
<script>
(function () {
    var panel = document.getElementById('aiPanel');
    aiChat(panel);
    document.getElementById('aiFab').addEventListener('click', function () { panel.classList.toggle('show'); if (panel.classList.contains('show')) panel.querySelector('[data-ai-input]').focus(); });
    panel.querySelector('[data-ai-close]').addEventListener('click', function () { panel.classList.remove('show'); });
})();
</script>
@endunless
@endif
