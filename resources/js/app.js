import './bootstrap';
import QRCode from 'qrcode';

document.querySelectorAll('[data-pairing-qr], [data-connection-qr]').forEach((canvas) => {
    QRCode.toCanvas(canvas, canvas.dataset.payload || '', {
        width: 240, margin: 1,
        color: { dark: '#143c35', light: '#ffffff' },
        errorCorrectionLevel: 'M',
    }).catch(() => window.TablePlay?.toast('Could not render this pairing QR.', 'danger'));
});

document.querySelectorAll('[data-copy-payload]').forEach((button) => button.addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(button.dataset.copyPayload || '');
        window.TablePlay?.toast('Setup code copied. Paste it into the app on the restaurant network.', 'success');
    } catch {
        window.TablePlay?.toast('Could not copy automatically. Select and copy the setup code manually.', 'danger');
    }
}));

document.querySelectorAll('[data-expires-at]').forEach((node) => {
    const expiresAt = Date.parse(node.dataset.expiresAt || '');
    if (!Number.isFinite(expiresAt)) return;
    const update = () => {
        const seconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        node.textContent = seconds > 0
            ? `Expires in ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
            : 'Expired — generate a new QR';
        node.classList.toggle('is-expired', seconds === 0);
    };
    update();
    window.setInterval(update, 1000);
});

const root = document.documentElement;
const savedTheme = localStorage.getItem('tableplay-theme');
const preferredDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
root.dataset.theme = savedTheme || (preferredDark ? 'dark' : 'light');

const renderThemeIcon = () => {
    const target = document.querySelector('[data-theme-icon]');
    if (!target) return;
    target.innerHTML = root.dataset.theme === 'dark'
        ? '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>'
        : '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>';
};
renderThemeIcon();

document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('tableplay-theme', root.dataset.theme);
    renderThemeIcon();
});

const sidebar = document.querySelector('.sidebar');
const closeSidebar = () => {
    sidebar?.classList.remove('is-open');
    document.body.classList.remove('sidebar-open');
};
document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => {
    sidebar?.classList.add('is-open');
    document.body.classList.add('sidebar-open');
});
document.querySelectorAll('[data-sidebar-close], .sidebar .nav-link').forEach((node) => node.addEventListener('click', closeSidebar));
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeSidebar(); });

const clock = document.querySelector('[data-live-clock]');
const updateClock = () => {
    if (!clock) return;
    clock.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date());
};
updateClock();
setInterval(updateClock, 1000);

document.querySelectorAll('[data-alert-close]').forEach((button) => button.addEventListener('click', () => button.closest('.alert')?.remove()));

const toastStack = document.querySelector('[data-toast-stack]');
const dismissToast = (toast) => {
    if (!toast || toast.classList.contains('is-leaving')) return;
    window.clearTimeout(toast._dismissTimer);
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 220);
};
const scheduleToast = (toast) => {
    const timeout = Number(toast.dataset.timeout || 0);
    if (timeout <= 0) return;
    if (toast._remaining === undefined) {
        toast._remaining = timeout;
        toast.style.setProperty('--toast-duration', `${timeout}ms`);
    }
    window.clearTimeout(toast._dismissTimer);
    toast._timerStarted = Date.now();
    toast._dismissTimer = window.setTimeout(() => dismissToast(toast), toast._remaining);
};
const bindToast = (toast) => {
    if (!toast || toast.dataset.toastBound !== undefined) return;
    toast.dataset.toastBound = '';
    toast.querySelector('[data-toast-close]')?.addEventListener('click', () => dismissToast(toast));
    toast.addEventListener('mouseenter', () => {
        if (toast._remaining !== undefined) toast._remaining = Math.max(0, toast._remaining - (Date.now() - toast._timerStarted));
        window.clearTimeout(toast._dismissTimer);
    });
    toast.addEventListener('mouseleave', () => scheduleToast(toast));
    scheduleToast(toast);
};
document.querySelectorAll('[data-toast]').forEach(bindToast);

const showToast = (message, type = 'success', title = null, timeout = null) => {
    if (!toastStack) return null;
    const safeType = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
    const defaults = {
        success: ['Done', 4800, '✓'],
        danger: ['Action failed', 9000, '!'],
        warning: ['Attention', 7000, '!'],
        info: ['TablePlay', 6000, 'i'],
    };
    const toast = document.createElement('article');
    toast.className = `toast toast--${safeType}`;
    toast.dataset.toast = '';
    toast.dataset.timeout = String(timeout ?? defaults[safeType][1]);
    toast.setAttribute('role', safeType === 'danger' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast__icon toast__icon--text';
    icon.textContent = defaults[safeType][2];
    icon.setAttribute('aria-hidden', 'true');
    const content = document.createElement('div');
    content.className = 'toast__content';
    const heading = document.createElement('strong');
    heading.textContent = title || defaults[safeType][0];
    const text = document.createElement('p');
    text.textContent = message;
    content.append(heading, text);
    const close = document.createElement('button');
    close.className = 'toast__close';
    close.type = 'button';
    close.dataset.toastClose = '';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';
    const progress = document.createElement('span');
    progress.className = 'toast__progress';
    progress.setAttribute('aria-hidden', 'true');
    toast.append(icon, content, close, progress);
    toastStack.append(toast);
    bindToast(toast);
    return toast;
};

const confirmationDialog = document.querySelector('[data-confirmation-dialog]');
const confirmationTitle = confirmationDialog?.querySelector('[data-confirmation-title]');
const confirmationMessage = confirmationDialog?.querySelector('[data-confirmation-message]');
const confirmationAccept = confirmationDialog?.querySelector('[data-confirmation-accept]');
let confirmationTarget = null;

document.querySelectorAll('[data-confirm]').forEach((node) => node.addEventListener('click', (event) => {
    if (node.dataset.confirmed !== undefined) {
        delete node.dataset.confirmed;
        return;
    }
    event.preventDefault();
    if (!confirmationDialog?.showModal) return;
    confirmationTarget = node;
    const destructive = node.dataset.confirmTone === 'danger' || node.classList.contains('button--danger');
    confirmationDialog.classList.toggle('is-danger', destructive);
    confirmationTitle.textContent = node.dataset.confirmTitle || (destructive ? 'Confirm this change' : 'Continue with this action?');
    confirmationMessage.textContent = node.dataset.confirm;
    confirmationAccept.textContent = node.dataset.confirmButton || node.textContent.trim() || 'Confirm';
    confirmationAccept.classList.toggle('button--danger', destructive);
    confirmationDialog.showModal();
}));

confirmationDialog?.querySelectorAll('[data-confirmation-cancel]').forEach((button) => button.addEventListener('click', () => confirmationDialog.close()));
confirmationAccept?.addEventListener('click', () => {
    const target = confirmationTarget;
    confirmationTarget = null;
    confirmationDialog.close();
    if (!target) return;
    target.dataset.confirmed = '';
    target.click();
});
confirmationDialog?.addEventListener('close', () => { confirmationTarget = null; });

document.querySelectorAll('form').forEach((form) => form.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !form.checkValidity()) return;
    const button = event.submitter;
    if (!button || button.dataset.noBusy !== undefined) return;
    button.setAttribute('aria-busy', 'true');
    button.disabled = true;
    button.insertAdjacentHTML('afterbegin', '<span class="button__spinner" aria-hidden="true"></span>');
}));

document.querySelectorAll('[data-secret-toggle]').forEach((button) => button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    button.setAttribute('aria-pressed', String(!showing));
    const label = showing ? 'Show' : 'Hide';
    const subject = input.name === 'pin' ? 'PIN' : 'password';
    button.setAttribute('aria-label', `${label} ${subject}`);
    button.title = `${label} ${subject}`;
    const text = button.querySelector('[data-secret-label]');
    if (text) text.textContent = label;
}));

document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.dialogOpen);
    if (dialog?.showModal) dialog.showModal();
}));
document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
document.querySelectorAll('dialog.modal-dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
}));

const connectionChip = document.querySelector('.network-chip');
const connectionLabel = document.querySelector('[data-connection-label]');
const setConnection = (state, label) => {
    if (!connectionChip) return;
    connectionChip.classList.remove('connected', 'disconnected');
    connectionChip.classList.add(state);
    if (connectionLabel) connectionLabel.textContent = label;
};

const pusherConnection = window.Echo?.connector?.pusher?.connection;
if (pusherConnection) {
    pusherConnection.bind('connected', () => setConnection('connected', 'Real-time online'));
    pusherConnection.bind('disconnected', () => setConnection('disconnected', 'Real-time offline'));
    pusherConnection.bind('unavailable', () => setConnection('disconnected', 'Reconnecting'));
    pusherConnection.bind('failed', () => setConnection('disconnected', 'Connection failed'));
} else {
    setConnection('disconnected', 'Polling mode');
}

window.TablePlay = { setConnection, toast: showToast };

const helpAssistant = document.querySelector('[data-help-assistant]');
if (helpAssistant) {
    const panel = helpAssistant.querySelector('[data-help-panel]');
    const launcher = helpAssistant.querySelector('[data-help-open]');
    const closeButton = helpAssistant.querySelector('[data-help-close]');
    const messages = helpAssistant.querySelector('[data-help-messages]');
    const suggestions = helpAssistant.querySelector('[data-help-suggestions]');
    const form = helpAssistant.querySelector('[data-help-form]');
    const input = helpAssistant.querySelector('[data-help-input]');
    const endpoint = helpAssistant.dataset.endpoint;

    const scrollMessages = () => requestAnimationFrame(() => messages?.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' }));
    const openHelp = () => {
        panel.hidden = false;
        launcher.setAttribute('aria-expanded', 'true');
        helpAssistant.classList.add('is-open');
        requestAnimationFrame(() => input?.focus());
    };
    const closeHelp = () => {
        panel.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        helpAssistant.classList.remove('is-open');
        launcher.focus();
    };

    const userMessage = (message) => {
        const article = document.createElement('article');
        article.className = 'help-message help-message--user';
        const bubble = document.createElement('div');
        bubble.className = 'help-bubble';
        const text = document.createElement('p');
        text.textContent = message;
        bubble.append(text);
        article.append(bubble);
        messages.append(article);
        scrollMessages();
    };

    const showTyping = () => {
        const article = document.createElement('article');
        article.className = 'help-message help-message--bot help-message--typing';
        article.dataset.helpTyping = '';
        article.innerHTML = '<span class="help-message__avatar"><svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="13" rx="4"/><path d="M9 12h.01M15 12h.01M9 16h6M12 7V4m-2 0h4"/></svg></span><div class="help-bubble"><span class="typing-dots"><i></i><i></i><i></i></span></div>';
        messages.append(article);
        scrollMessages();
    };

    const renderSuggestions = (items = []) => {
        suggestions.innerHTML = '';
        items.slice(0, 4).forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.helpPrompt = item;
            button.textContent = item;
            suggestions.append(button);
        });
        messages.append(suggestions);
    };

    const botMessage = (data) => {
        messages.querySelector('[data-help-typing]')?.remove();
        const article = document.createElement('article');
        article.className = 'help-message help-message--bot';

        const avatar = document.createElement('span');
        avatar.className = 'help-message__avatar';
        avatar.innerHTML = '<svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="13" rx="4"/><path d="M9 12h.01M15 12h.01M9 16h6M12 7V4m-2 0h4"/></svg>';

        const bubble = document.createElement('div');
        bubble.className = 'help-bubble';
        const title = document.createElement('strong');
        title.textContent = data.title || 'TablePlay Guide';
        const reply = document.createElement('p');
        reply.textContent = data.reply || 'I could not load that guidance.';
        bubble.append(title, reply);

        if (Array.isArray(data.steps) && data.steps.length) {
            const list = document.createElement('ol');
            data.steps.forEach((step) => {
                const item = document.createElement('li');
                item.textContent = step;
                list.append(item);
            });
            bubble.append(list);
        }

        if (data.action?.url && data.action?.label) {
            const action = document.createElement('a');
            action.className = 'help-bubble__action';
            action.href = data.action.url;
            action.textContent = data.action.label;
            action.setAttribute('aria-label', `${data.action.label} and close help`);
            bubble.append(action);
        }

        article.append(avatar, bubble);
        messages.append(article);
        renderSuggestions(data.suggestions || []);
        scrollMessages();
    };

    const ask = async (question) => {
        const message = question.trim();
        if (message.length < 2) return;
        openHelp();
        input.value = '';
        userMessage(message);
        suggestions.innerHTML = '';
        showTyping();
        form.classList.add('is-sending');
        input.disabled = true;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ message }),
            });
            if (!response.ok) throw new Error(`Help request failed with ${response.status}`);
            botMessage(await response.json());
        } catch (error) {
            botMessage({
                title: 'Local guide unavailable',
                reply: 'I could not reach the TablePlay help service. Refresh this page and confirm the Laravel server is still running.',
                steps: [],
                suggestions: ['How do I get started?'],
            });
        } finally {
            form.classList.remove('is-sending');
            input.disabled = false;
            input.focus();
        }
    };

    launcher?.addEventListener('click', () => panel.hidden ? openHelp() : closeHelp());
    closeButton?.addEventListener('click', closeHelp);
    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        ask(input.value);
    });
    helpAssistant.addEventListener('click', (event) => {
        const prompt = event.target.closest('[data-help-prompt]');
        if (prompt) ask(prompt.dataset.helpPrompt || prompt.textContent);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) closeHelp();
    });
}
