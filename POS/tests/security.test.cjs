const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const path = require('node:path');

function browser(fetch, values = new Map()) {
    const listeners = new Map();
    const context = {
        URL, Headers, Response, Request, crypto: webcrypto,
        HTMLFormElement: class {},
        eventHandlers: listeners,
        location: new URL('https://example.test/POS/php/menu.php'),
        document: {
            currentScript: { src: 'https://example.test/POS/js/security.js' },
            querySelector: () => ({ content: 'csrf-test-token' }),
            addEventListener: (type, handler) => listeners.set(type, handler),
            body: { append() {} },
            createElement() {
                const handlers = new Map();
                const input = { value: 'a sufficiently long password', focus() {} };
                return {
                    querySelector(selector) {
                        if (selector === 'input') return input;
                        return { addEventListener: (type, handler) => handlers.set(type, handler) };
                    },
                    addEventListener() {}, remove() {},
                    showModal() { queueMicrotask(() => handlers.get('submit')({ preventDefault() {} })); },
                };
            },
        },
        sessionStorage: { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) },
        window: { fetch: async (input, options) => {
            if (input instanceof Request) {
                options = { headers: input.headers, method: input.method, body: await input.text() };
            }
            return fetch(input, options);
        } },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/security.js'), 'utf8'), context);
    return context;
}

test('overlapping operations preserve independent retry keys and store only body hashes', async () => {
    const calls = [];
    const values = new Map();
    const context = browser(async (input, options) => {
        calls.push(options);
        if (options.body.includes('operation-B')) return new Response('{"success":true}');
        throw new Error('lost response');
    }, values);
    const a = { method: 'POST', body: '{"private":"operation-A"}' };
    const b = { method: 'POST', body: '{"private":"operation-B"}' };
    await assert.rejects(context.window.fetch('../api/payroll.php', a));
    await context.window.fetch('../api/payroll.php', b);
    await assert.rejects(context.window.fetch('../api/payroll.php', a));
    assert.equal(calls[0].headers.get('Idempotency-Key'), calls[2].headers.get('Idempotency-Key'));
    assert.notEqual(calls[0].headers.get('Idempotency-Key'), calls[1].headers.get('Idempotency-Key'));
    assert.doesNotMatch(values.get('kofee-order-operations'), /private|operation-A|operation-B/);
    const reloaded = browser(async (input, options) => {
        assert.equal(options.headers.get('Idempotency-Key'), calls[0].headers.get('Idempotency-Key'));
        throw new Error('still offline');
    }, values);
    await assert.rejects(reloaded.window.fetch('../api/payroll.php', a));
});

test('Request bodies survive password confirmation and successful retries clear their operation key', async () => {
    const calls = [];
    const context = browser(async (input, options) => {
        if (String(input).includes('reauthenticate.php')) return new Response('{"success":true}');
        calls.push(options);
        return new Response('{"success":true}', { status: calls.length === 1 ? 428 : 200 });
    });
    const request = () => new Request('https://example.test/POS/api/payroll.php', { method: 'POST', body: '{"action":"release"}' });
    await context.window.fetch(request());
    await context.window.fetch(request());
    assert.equal(calls[0].body, calls[1].body);
    assert.equal(calls[0].headers.get('Idempotency-Key'), calls[1].headers.get('Idempotency-Key'));
    assert.notEqual(calls[1].headers.get('Idempotency-Key'), calls[2].headers.get('Idempotency-Key'));
});

test('explicit operation keys and Request headers are preserved', async () => {
    const context = browser(async (input, options) => {
        assert.equal(options.headers.get('Idempotency-Key'), 'explicit-operation-key');
        assert.equal(options.headers.get('X-Custom'), 'retained');
        assert.equal(options.headers.get('X-CSRF-Token'), 'csrf-test-token');
        return new Response('{"success":true}');
    });
    await context.window.fetch(new Request('https://example.test/POS/api/checkout.php', {
        method: 'POST', headers: { 'Idempotency-Key': 'explicit-operation-key', 'X-Custom': 'retained' }, body: '{}',
    }));
});

test('external logout links do not submit a local CSRF token', () => {
    const context = browser(async () => new Response('{}'));
    let intercepted = false;
    context.eventHandlers.get('click')({
        target: { closest: () => ({ href: 'https://external.test/auth/logout.php' }) },
        preventDefault() { intercepted = true; },
    });
    assert.equal(intercepted, false);
});

test('an external form submitter removes session tokens before submission', async () => {
    const context = browser(async () => new Response('{}'));
    let removed = 0;
    const form = Object.assign(new context.HTMLFormElement(), {
        method: 'post', action: 'https://example.test/POS/php/manage_users.php',
        closest: () => null,
        querySelectorAll: () => [{ remove() { removed++; } }],
    });
    await context.eventHandlers.get('submit')({
        target: form,
        submitter: { getAttribute: name => name === 'formaction' ? 'https://external.test/submit' : null },
    });
    assert.equal(removed, 1);
});

test('browser mutations attach CSRF and retain the same checkout key after a lost response', async () => {
    const calls = [];
    const context = browser(async (input, options) => {
        calls.push(options);
        if (calls.length === 1) throw new Error('network timeout');
        return new Response(JSON.stringify({ success: true }), { status: 200 });
    });
    const body = JSON.stringify({ items: [{ id: 1, size: 'small', qty: 1 }] });
    await assert.rejects(context.window.fetch('../api/checkout.php', { method: 'POST', body }));
    await context.window.fetch('../api/checkout.php', { method: 'POST', body });
    assert.equal(calls[0].headers.get('X-CSRF-Token'), 'csrf-test-token');
    assert.equal(calls[0].headers.get('Idempotency-Key'), calls[1].headers.get('Idempotency-Key'));
    await context.window.fetch('../api/checkout.php', { method: 'POST', body });
    assert.notEqual(calls[1].headers.get('Idempotency-Key'), calls[2].headers.get('Idempotency-Key'));
});

test('pending checkout preserves its operation key for reconciliation', async () => {
    const keys = [];
    const context = browser(async (input, options) => {
        keys.push(options.headers.get('Idempotency-Key'));
        return new Response(JSON.stringify({ success: true, pending: true }), { status: 200 });
    });
    await context.window.fetch('../api/create_paymongo_checkout.php', { method: 'POST', body: '{}' });
    await context.window.fetch('../api/create_paymongo_checkout.php', { method: 'POST', body: '{}' });
    assert.equal(keys[0], keys[1]);
});

test('checkout retries retain their key when browser storage is unavailable', async () => {
    const keys = [];
    const context = browser(async (input, options) => {
        keys.push(options.headers.get('Idempotency-Key'));
        throw new Error('connection lost');
    });
    context.sessionStorage.getItem = context.sessionStorage.setItem = () => { throw new Error('storage unavailable'); };
    await assert.rejects(context.window.fetch('../api/checkout.php', { method: 'POST', body: '{}' }));
    await assert.rejects(context.window.fetch('../api/checkout.php', { method: 'POST', body: '{}' }));
    assert.equal(keys[0], keys[1]);
});

test('CSRF tokens are never forwarded to another origin', async () => {
    const context = browser(async (input, options) => {
        assert.equal(options.headers, undefined);
        return new Response('{}');
    });
    await context.window.fetch('https://other.test/api', { method: 'POST', body: '{}' });
});

test('private URLs use the authorized reader', () => {
    const context = browser(async () => new Response('{}'));
    assert.equal(context.window.kofeePrivateFileUrl('uploads/avatars/test.png'), 'https://example.test/POS/php/download_file.php?f=uploads%2Favatars%2Ftest.png');
});

test('demo control keeps checkout submission available and does not forge a payment status', () => {
    const source = fs.readFileSync(path.join(__dirname, '../js/menu.js'), 'utf8');
    assert.match(source, /async function submitConfirmedOrder\(/);
    assert.doesNotMatch(source, /simulate=1/);
});

function storePinBrowser({ secure = true, geolocation = true, leaflet = true, roles = null } = {}) {
    const elements = new Map();
    for (const [id, value] of [['geofence-settings-form', ''], ['setting_lat', '14.3294'], ['setting_lon', '120.9367'], ['setting_radius', '200'], ['detect-store-pin', ''], ['store-pin-status', '']]) {
        elements.set(id, { value, textContent: '', disabled: false, handlers: new Map(),
            classList: { toggle() {} }, addEventListener(type, handler) { this.handlers.set(type, handler); } });
    }
    let checkboxes = [];
    if (roles) {
        checkboxes = roles.map(role => ({ value: role.key, checked: role.checked, dataset: { roleLabel: role.label } }));
        for (const id of ['bypass-roles-modal', 'choose-bypass-roles', 'bypass-roles-summary', 'bypass-roles-empty', 'cancel-bypass-roles', 'save-bypass-roles']) {
            elements.set(id, { handlers: new Map(), children: [], hidden: false, open: false,
                addEventListener(type, handler) { this.handlers.set(type, handler); }, focus() { this.focused = true; },
                replaceChildren(...children) { this.children = children; }, querySelectorAll() { return checkboxes; },
                showModal() { this.open = true; }, close() { this.open = false; this.handlers.get('close')(); } });
        }
    }
    const requests = [];
    const map = { handlers: new Map(), setView() { return this; }, panTo(point) { this.center = Array.from(point); },
        invalidateSize() {}, on(type, handler) { this.handlers.set(type, handler); } };
    const marker = { point: null, handlers: new Map(), addTo() { return this; },
        setLatLng(point) { this.point = Array.from(point); }, getLatLng() { return { lat: this.point[0], lng: this.point[1] }; },
        on(type, handler) { this.handlers.set(type, handler); } };
    const circle = { addTo() { return this; }, setLatLng(point) { this.point = Array.from(point); }, setRadius(meters) { this.radius = meters; } };
    const context = { document: { getElementById: id => elements.get(id), createElement: tag => ({ tagName: tag, textContent: '' }) },
        navigator: geolocation ? { geolocation: { getCurrentPosition(success, failure, options) { requests.push({ success, failure, options }); } } } : {},
        window: { isSecureContext: secure, L: leaflet ? {
            map: () => map, marker: () => marker, circle: () => circle,
            tileLayer: () => ({ addTo() {} }),
        } : undefined },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/login-approval-settings.js'), 'utf8'), context);
    return { context, requests, map, marker, circle, checkboxes, element: id => elements.get(id),
        click(id) { elements.get(id).handlers.get('click')(); },
        detect() { elements.get('detect-store-pin').handlers.get('click')(); },
        input(id, value) { const element = elements.get(id); element.value = value; element.handlers.get('input')(); } };
}

test('current location updates both coordinates and the map preview, including zero coordinates', () => {
    const ui = storePinBrowser();
    ui.context.window.storePinSettings.showMap();
    ui.detect();
    assert.equal(ui.element('detect-store-pin').disabled, true);
    ui.detect();
    assert.equal(ui.requests.length, 1);
    ui.requests[0].success({ coords: { latitude: 0, longitude: 0, accuracy: 42 } });
    assert.equal(ui.element('setting_lat').value, '0.0000000');
    assert.equal(ui.element('setting_lon').value, '0.0000000');
    assert.deepEqual(ui.marker.point, [0, 0]);
    assert.deepEqual(ui.circle.point, [0, 0]);
    assert.match(ui.element('store-pin-status').textContent, /42 m accuracy.*save/i);
    assert.equal(ui.element('detect-store-pin').disabled, false);
});

test('location timeout falls back to approximate location once and recovers the button', () => {
    const ui = storePinBrowser();
    ui.detect();
    ui.requests[0].failure({ code: 3 });
    assert.equal(ui.requests.length, 2);
    assert.equal(ui.requests[0].options.enableHighAccuracy, true);
    assert.equal(ui.requests[1].options.enableHighAccuracy, false);
    assert.equal(ui.requests[1].options.maximumAge, 0);
    ui.requests[1].failure({ code: 3 });
    assert.equal(ui.requests.length, 2);
    assert.equal(ui.element('detect-store-pin').disabled, false);
    assert.equal(ui.element('setting_lat').value, '14.3294');
    assert.match(ui.element('store-pin-status').textContent, /timed out/i);
});

test('permission denial gives actionable feedback without retrying or overwriting the store pin', () => {
    const ui = storePinBrowser();
    ui.detect();
    ui.requests[0].failure({ code: 1 });
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.element('setting_lon').value, '120.9367');
    assert.equal(ui.element('detect-store-pin').disabled, false);
    assert.match(ui.element('store-pin-status').textContent, /site settings.*device location settings/i);
});

test('map clicks, marker dragging, and typed coordinates move the preview and radius', () => {
    const ui = storePinBrowser();
    ui.context.window.storePinSettings.showMap();
    ui.map.handlers.get('click')({ latlng: { lat: 14.4, lng: 480.9 } });
    assert.equal(ui.element('setting_lon').value, '120.9000000');
    ui.marker.point = [14.5, 121];
    ui.marker.handlers.get('dragend')();
    assert.equal(ui.element('setting_lat').value, '14.5000000');
    assert.equal(ui.element('setting_lon').value, '121.0000000');
    ui.input('setting_lat', '14.6');
    ui.input('setting_lon', '121.1');
    ui.input('setting_radius', '350');
    assert.deepEqual(ui.map.center, [14.6, 121.1]);
    assert.deepEqual(ui.circle.point, [14.6, 121.1]);
    assert.equal(ui.circle.radius, 350);
    ui.input('setting_lat', '');
    assert.deepEqual(ui.circle.point, [14.6, 121.1]);
    assert.match(ui.element('store-pin-status').textContent, /latitude from -90/i);
});

test('a late GPS response does not replace a manually chosen new pin', () => {
    const ui = storePinBrowser();
    ui.context.window.storePinSettings.showMap();
    ui.detect();
    ui.map.handlers.get('click')({ latlng: { lat: 14.5, lng: 121 } });
    ui.requests[0].success({ coords: { latitude: 15, longitude: 122, accuracy: 20 } });
    assert.equal(ui.element('setting_lat').value, '14.5000000');
    assert.equal(ui.element('setting_lon').value, '121.0000000');
    assert.match(ui.element('store-pin-status').textContent, /manually selected pin was kept/);
});

test('saving waits for pending location detection so stale coordinates are not submitted', () => {
    const ui = storePinBrowser();
    ui.detect();
    let prevented = false;
    ui.element('geofence-settings-form').handlers.get('submit')({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.match(ui.element('store-pin-status').textContent, /wait for location detection/);
});

test('location remains usable when the map CDN cannot load', () => {
    const ui = storePinBrowser({ leaflet: false });
    ui.context.window.storePinSettings.showMap();
    assert.match(ui.element('store-pin-status').textContent, /map could not load/);
    ui.detect();
    ui.requests[0].success({ coords: { latitude: 14.5, longitude: 121, accuracy: 500 } });
    assert.equal(ui.element('setting_lat').value, '14.5000000');
});

test('insecure contexts and browsers without geolocation offer manual pin selection', () => {
    for (const options of [{ secure: false }, { geolocation: false }]) {
        const ui = storePinBrowser(options);
        ui.detect();
        assert.equal(ui.requests.length, 0);
        assert.equal(ui.element('detect-store-pin').disabled, false);
        assert.match(ui.element('store-pin-status').textContent, /coordinates/);
    }
});

test('a browser location exception and invalid coordinates leave the existing pin intact', () => {
    const ui = storePinBrowser();
    ui.context.navigator.geolocation.getCurrentPosition = () => { throw new Error('Blocked by policy'); };
    ui.detect();
    assert.equal(ui.element('detect-store-pin').disabled, false);
    assert.match(ui.element('store-pin-status').textContent, /browser blocked/);
    const invalid = storePinBrowser();
    invalid.detect();
    invalid.requests[0].success({ coords: { latitude: 91, longitude: 121 } });
    assert.equal(invalid.element('setting_lat').value, '14.3294');
    assert.match(invalid.element('store-pin-status').textContent, /invalid coordinates/);
});

function bypassRolesBrowser() {
    return storePinBrowser({ roles: [
        { key: 'hr', label: 'Human Resources', checked: true },
        { key: 'finance', label: 'Finance', checked: false },
        { key: 'crew', label: 'Store Crew', checked: false },
    ] });
}

test('bypass role modal saves multiple selections and displays every selected label', () => {
    const ui = bypassRolesBrowser();
    ui.click('choose-bypass-roles');
    assert.equal(ui.element('bypass-roles-modal').open, true);
    ui.checkboxes[1].checked = true;
    ui.checkboxes[2].checked = true;
    ui.click('save-bypass-roles');
    assert.equal(ui.element('bypass-roles-modal').open, false);
    assert.deepEqual(ui.element('bypass-roles-summary').children.map(item => item.textContent), ['Human Resources', 'Finance', 'Store Crew']);
    assert.equal(ui.element('bypass-roles-empty').hidden, true);
    assert.equal(ui.element('choose-bypass-roles').focused, true);
    ui.click('choose-bypass-roles');
    assert.equal(ui.checkboxes.every(input => input.checked), true);
});

test('cancel discards checkbox edits while preserving the previous saved modal selection', () => {
    const ui = bypassRolesBrowser();
    ui.click('choose-bypass-roles');
    ui.checkboxes[1].checked = true;
    ui.click('save-bypass-roles');
    ui.click('choose-bypass-roles');
    ui.checkboxes[0].checked = false;
    ui.checkboxes[1].checked = false;
    ui.checkboxes[2].checked = true;
    ui.click('cancel-bypass-roles');
    assert.deepEqual(ui.checkboxes.map(input => input.checked), [true, true, false]);
    assert.deepEqual(ui.element('bypass-roles-summary').children.map(item => item.textContent), ['Human Resources', 'Finance']);
    ui.click('choose-bypass-roles');
    assert.deepEqual(ui.checkboxes.map(input => input.checked), [true, true, false]);
});

test('native Escape dismissal restores the selection and focus without saving changes', () => {
    const ui = bypassRolesBrowser();
    ui.click('choose-bypass-roles');
    ui.checkboxes[0].checked = false;
    ui.checkboxes[2].checked = true;
    ui.element('bypass-roles-modal').close();
    assert.deepEqual(ui.checkboxes.map(input => input.checked), [true, false, false]);
    assert.equal(ui.element('choose-bypass-roles').focused, true);
});

test('saving an empty bypass checklist clears the summary and leaves every role unchecked', () => {
    const ui = bypassRolesBrowser();
    ui.click('choose-bypass-roles');
    ui.checkboxes.forEach(input => { input.checked = false; });
    ui.click('save-bypass-roles');
    assert.deepEqual(ui.element('bypass-roles-summary').children, []);
    assert.equal(ui.element('bypass-roles-empty').hidden, false);
    ui.click('choose-bypass-roles');
    assert.equal(ui.checkboxes.every(input => !input.checked), true);
});

test('an open checklist cannot submit draft bypass roles through the settings form', () => {
    const ui = bypassRolesBrowser();
    ui.click('choose-bypass-roles');
    ui.checkboxes[1].checked = true;
    let prevented = false;
    ui.element('geofence-settings-form').handlers.get('submit')({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    ui.click('save-bypass-roles');
    prevented = false;
    ui.element('geofence-settings-form').handlers.get('submit')({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
});

test('confirmPassword dialog builds self-contained UI with sidebar header and inline styles', async () => {
    let dialogCreated = null;
    const listeners = new Map();
    const context = {
        URL, Headers, Response, Request, crypto: webcrypto,
        HTMLFormElement: class {},
        eventHandlers: listeners,
        location: new URL('https://example.test/POS/php/menu.php'),
        document: {
            currentScript: { src: 'https://example.test/POS/js/security.js' },
            querySelector: () => ({ content: 'csrf-test-token' }),
            addEventListener: (type, handler) => listeners.set(type, handler),
            body: { append(el) { dialogCreated = el; } },
            createElement(tag) {
                const handlers = new Map();
                const input = { value: 'password', focus() {} };
                return {
                    className: '',
                    innerHTML: '',
                    style: {},
                    querySelector(selector) {
                        if (selector === 'input') return input;
                        return { addEventListener: (type, handler) => handlers.set(type, handler) };
                    },
                    addEventListener() {}, remove() {},
                    showModal() { queueMicrotask(() => handlers.get('submit')({ preventDefault() {} })); },
                };
            },
        },
        sessionStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
        window: { fetch: async (input, options) => {
            if (String(input).includes('reauthenticate.php')) return new Response('{"success":true}');
            return new Response('{"success":true}', { status: 428 });
        } },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/security.js'), 'utf8'), context);
    await context.window.fetch('https://example.test/POS/api/payroll.php', { method: 'POST', body: '{}' });
    assert.ok(dialogCreated, 'Dialog should have been created and appended to document.body');
    assert.match(dialogCreated.innerHTML, /kfs-confirm-dialog-card/);
    assert.match(dialogCreated.innerHTML, /#241A2E/, 'Header should include sidebar espresso brand color');
    assert.match(dialogCreated.innerHTML, /kfs-confirm-eye-btn/);
    assert.match(dialogCreated.innerHTML, /kfs-confirm-btn-cancel/);
    assert.match(dialogCreated.innerHTML, /kfs-confirm-btn-submit/);
    assert.match(dialogCreated.style.cssText, /position:fixed/);
    assert.match(dialogCreated.style.cssText, /z-index:10002/);
});

test('request_security.php adds cache-busting version query params to local stylesheets', () => {
    const phpScript = '$html = "<html><head><link rel=\\"stylesheet\\" href=\\"../css/style.css\\"></head><body></body></html>"; require_once "includes/request_security.php"; echo browser_security_output($html);';
    const result = require('node:child_process').execFileSync('php', ['-r', phpScript], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
    assert.match(result, /href=["']\.\.\/css\/style\.css\?v=\d+["']/);
});

