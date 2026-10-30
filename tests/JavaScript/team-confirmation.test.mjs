import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

// Execute the application's actual handlers against a small event/DOM fixture.
// These are behavior tests, not a substitute for visual checks in a browser.
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const start = app.indexOf('const confirmationDialog =');
const end = app.indexOf("document.querySelectorAll('form').forEach", start);
assert.ok(start >= 0 && end > start, 'Application confirmation handlers must exist');
const handlers = app.slice(start, end);

class Element {
    dataset = {};
    textContent = '';
    listeners = new Map();
    classes = new Set();
    submissions = 0;
    classList = {
        contains: (name) => this.classes.has(name),
        toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name),
    };
    addEventListener(type, listener) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }
    emit(type) {
        const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        for (const listener of this.listeners.get(type) ?? []) listener(event);
        return event;
    }
    click() {
        if (!this.emit('click').defaultPrevented) this.submissions++;
    }
}

function fixture({ nativeDialog = true } = {}) {
    const title = new Element(), message = new Element(), accept = new Element();
    const cancel = new Element(), close = new Element(), dialog = new Element();
    dialog.open = false;
    if (nativeDialog) dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.emit('close'); };
    dialog.querySelector = (selector) => ({
        '[data-confirmation-title]': title,
        '[data-confirmation-message]': message,
        '[data-confirmation-accept]': accept,
    })[selector];
    dialog.querySelectorAll = () => [cancel, close];
    const remove = new Element(), disable = new Element(), enable = new Element();
    Object.assign(remove.dataset, {
        confirm: 'Delete "QA Staff"? Past orders and bills will be kept.',
        confirmTitle: 'Delete staff account?', confirmButton: 'Delete account', confirmTone: 'danger',
    });
    Object.assign(disable.dataset, { confirm: 'Disable QA Staff?', confirmTone: 'danger' });
    disable.textContent = 'Disable';
    Object.assign(enable.dataset, { confirm: 'Enable QA Staff?', confirmTone: 'default' });
    enable.textContent = 'Enable';
    const document = {
        querySelector: () => dialog,
        querySelectorAll: () => [remove, disable, enable],
    };
    vm.runInNewContext(handlers, { document });
    return { dialog, title, message, accept, cancel, close, remove, disable, enable };
}

test('Delete opens the named custom dialog without submitting', () => {
    const ui = fixture();
    ui.remove.click();
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.title.textContent, 'Delete staff account?');
    assert.equal(ui.accept.textContent, 'Delete account');
    assert.equal(ui.message.textContent, ui.remove.dataset.confirm);
    assert.equal(ui.dialog.classes.has('is-danger'), true);
    assert.equal(ui.remove.submissions, 0);
});

for (const control of ['cancel', 'close']) {
    test(`${control} dismisses without deleting or leaving a stale target`, () => {
        const ui = fixture();
        ui.remove.click();
        ui[control].click();
        ui.accept.click();
        assert.equal(ui.dialog.open, false);
        assert.equal(ui.remove.submissions, 0);
    });
}

test('Escape/native dialog close never deletes', () => {
    const ui = fixture();
    ui.remove.click();
    ui.dialog.close();
    ui.accept.click();
    assert.equal(ui.remove.submissions, 0);
});

test('Accept submits only the chosen action exactly once', () => {
    const ui = fixture();
    ui.remove.click();
    ui.accept.click();
    ui.accept.click();
    assert.equal(ui.remove.submissions, 1);
    assert.equal(ui.disable.submissions, 0);
    assert.equal(ui.dialog.open, false);
});

test('Disable and Enable each target their own form and reset danger styling', () => {
    const ui = fixture();
    ui.disable.click();
    assert.equal(ui.accept.textContent, 'Disable');
    assert.equal(ui.accept.classes.has('button--danger'), true);
    ui.accept.click();
    ui.enable.click();
    assert.equal(ui.accept.textContent, 'Enable');
    assert.equal(ui.accept.classes.has('button--danger'), false);
    ui.accept.click();
    assert.equal(ui.disable.submissions, 1);
    assert.equal(ui.enable.submissions, 1);
    assert.equal(ui.remove.submissions, 0);
});

test('Unsupported dialog API fails closed instead of deleting', () => {
    const ui = fixture({ nativeDialog: false });
    ui.remove.click();
    assert.equal(ui.remove.submissions, 0);
});

test('Staff names are rendered as text, not interpreted as HTML', () => {
    const ui = fixture();
    ui.remove.dataset.confirm = 'Delete <img src=x onerror=alert(1)>?';
    ui.remove.click();
    assert.equal(ui.message.textContent, ui.remove.dataset.confirm);
    assert.equal(ui.message.innerHTML, undefined);
});
