@php
    $role = auth()->user()->role?->name ?? 'staff';
    $suggestions = match($role) {
        'admin' => ['Pair a tablet', 'Add a menu item', 'Check system health', 'Create staff account'],
        'counter' => ['Confirm an order', 'Generate a bill', 'Handle waiter request', 'Extend game time'],
        'kitchen' => ['Start preparing an order', 'Mark food ready', 'Enable kitchen sound', 'What does overdue mean?'],
        default => ['How do I get started?', 'How does TablePlay work?'],
    };
@endphp

<div class="help-assistant" data-help-assistant data-endpoint="{{ route('help.chat') }}" data-role="{{ $role }}">
    <aside class="help-panel" data-help-panel hidden role="dialog" aria-modal="false" aria-labelledby="help-title">
        <header class="help-panel__header">
            <span class="help-bot-avatar"><x-icon name="bot" :size="22" /></span>
            <span class="help-panel__identity"><strong id="help-title">TablePlay Guide</strong><small><i></i> Local help &middot; {{ str($role)->headline() }}</small></span>
            <button class="icon-button help-panel__close" type="button" data-help-close aria-label="Close help"><x-icon name="close" :size="18" /></button>
        </header>
        <div class="help-messages" data-help-messages aria-live="polite">
            <article class="help-message help-message--bot">
                <span class="help-message__avatar"><x-icon name="bot" :size="16" /></span>
                <div class="help-bubble"><strong>How can I help?</strong><p>I can guide you through TablePlay step by step. This assistant works locally without internet.</p></div>
            </article>
            <div class="help-suggestions" data-help-suggestions>
                @foreach($suggestions as $suggestion)<button type="button" data-help-prompt="{{ $suggestion }}">{{ $suggestion }}</button>@endforeach
            </div>
        </div>
        <form class="help-composer" data-help-form autocomplete="off">
            <label class="sr-only" for="help-question">Ask how to use TablePlay</label>
            <input id="help-question" data-help-input maxlength="500" placeholder="Ask how to use TablePlay…" required>
            <button type="submit" data-no-busy aria-label="Send question"><x-icon name="send" :size="18" /></button>
        </form>
        <div class="help-panel__footer"><x-icon name="health" :size="13" /> Offline guide &middot; No customer data is sent outside this laptop</div>
    </aside>
    <button class="help-launch" type="button" data-help-open aria-expanded="false" aria-label="Open TablePlay help">
        <span class="help-launch__icon"><x-icon name="chat" :size="23" /></span>
        <span class="help-launch__copy"><strong>Need help?</strong><small>Ask TablePlay Guide</small></span>
    </button>
</div>
