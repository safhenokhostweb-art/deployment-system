@props(['id', 'formId', 'title', 'body' => null, 'confirmLabel' => null, 'cancelLabel' => null, 'danger' => true])
<dialog id="{{ $id }}" class="confirm-dialog">
    <h3>{{ $title }}</h3>
    @if ($body)
    <p class="muted">{{ $body }}</p>
    @endif
    {{-- Phase 4-I: richer dialogs (e.g. rollback from/to) pass structured
         slot content instead of a single :body string — every pre-4-I
         call site only ever used :body and never passed children, so
         $slot is empty for them and this renders nothing extra. --}}
    {{ $slot }}
    <div class="confirm-dialog-actions">
        <button type="button" class="btn" onclick="document.getElementById('{{ $id }}').close()">{{ $cancelLabel ?? __('ui.common.cancel_action') }}</button>
        <button type="button" class="btn {{ $danger ? 'btn-danger' : 'btn-primary' }}" onclick="document.getElementById('{{ $formId }}').submit()">{{ $confirmLabel ?? __('ui.common.confirm_action') }}</button>
    </div>
</dialog>
