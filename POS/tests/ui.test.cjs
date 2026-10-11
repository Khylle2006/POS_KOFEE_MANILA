const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
    constructor() {
        this.attributes = {};
        this.value = '';
        this.disabled = false;
        this.hidden = false;
        this.inert = false;
        this.style = {};
        this.textContent = '';
        this.innerHTML = '';
        const classes = new Set();
        this.classList = {
            contains: name => classes.has(name),
            add: (...names) => names.forEach(name => classes.add(name)),
            remove: (...names) => names.forEach(name => classes.delete(name)),
            toggle: (name, force = !classes.has(name)) => {
                if (force) classes.add(name); else classes.delete(name);
                return force;
            },
        };
        this.children = {};
        this.events = {};
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    getAttribute(name) { return this.attributes[name]; }
    querySelector(selector) { return this.children[selector] || null; }
    querySelectorAll(selector) { return this.children[selector] || []; }
    focus() { this.document.activeElement = this; }
    getClientRects() { return [1]; }
    closest() { return null; }
    contains(element) { return element === this; }
    addEventListener(name, callback) { this.events[name] = callback; }
}

function harness(file, options = {}) {
    const elements = new Map();
    const groups = {};
    const events = {};
    const windowEvents = {};
    const storage = new Map();
    const document = {
        activeElement: null,
        getElementById(id) { return elements.get(id) || null; },
        querySelector(selector) { return groups[selector]?.[0] || null; },
        querySelectorAll(selector) { return groups[selector] || []; },
        createElement() {
            const element = new Element();
            // Match browser text escaping used by the catalog renderer.
            Object.defineProperty(element, 'innerHTML', { get() {
                return String(this.textContent).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            } });
            return element;
        },
        addEventListener(name, callback) { (events[name] ||= []).push(callback); },
    };
    const make = id => {
        const element = new Element();
        element.document = document;
        elements.set(id, element);
        return element;
    };
    document.body = make('body');
    document.documentElement = make('html');
    const window = {
        innerWidth: options.width || 1280,
        location: { search: '' },
        addEventListener(name, callback) { (windowEvents[name] ||= []).push(callback); },
    };
    const browserStorage = {
        getItem: key => storage.get(key) || null,
        setItem: (key, value) => storage.set(key, value),
    };
    const context = vm.createContext({ document, window, console, localStorage: browserStorage,
        sessionStorage: browserStorage, AbortSignal, URLSearchParams, setTimeout, clearTimeout,
        fetch: options.fetch || (async () => { throw new Error('Offline'); }),
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../js', file), 'utf8'), context);
    return { context, document, window, elements, groups, events, windowEvents, storage, make };
}

function catalogHarness(options) {
    const h = harness('menu.js', options);
    ['menu-grid', 'catalog-status', 'reset-menu-search', 'menu-search', 'order-items',
        'review-order-btn', 'clear-order-btn', 'desktop-ticket-count', 'mobile-ticket-count',
        'subtotal', 'tax', 'total', 'mobile-cart-bar', 'mobile-cart-toggle', 'order-panel',
        'mobile-cart-backdrop', 'kofee-topbar'].forEach(h.make);
    h.groups['.menu-left'] = [h.make('catalog')];
    return h;
}

const products = [
    { id: 1, name: 'Spanish Latte', category_name: 'Ice Coffee', price_small: '95', price_large: '110', stock: 5 },
    { id: 2, name: 'Classic Milk Tea', category_name: 'Milk Tea', price_small: '80', price_large: '95', stock: 3 },
    { id: 3, name: 'Sold Out Latte', category_name: 'Ice Coffee', price_small: '90', price_large: '105', stock: 0 },
];

test('catalog search finds products in other categories and clearing restores the selected category', () => {
    const h = catalogHarness();
    h.context.populateMenuData(products);
    h.context.filterProducts('milk');
    assert.match(h.elements.get('menu-grid').innerHTML, /Classic Milk Tea/);
    assert.doesNotMatch(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.match(h.elements.get('catalog-status').textContent, /1 drink found across all categories/);
    h.context.resetProductSearch();
    assert.match(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.equal(h.elements.get('reset-menu-search').hidden, true);
});

test('category selection clears an active search and exposes selection to assistive technology', () => {
    const h = catalogHarness();
    const iced = h.make('iced');
    const tea = h.make('tea');
    h.groups['.cat-tab'] = [iced, tea];
    h.context.populateMenuData(products);
    h.context.filterProducts('Latte');
    h.context.switchCat(tea, 'milk-tea');
    assert.match(h.elements.get('menu-grid').innerHTML, /Classic Milk Tea/);
    assert.doesNotMatch(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.equal(tea.getAttribute('aria-pressed'), 'true');
    assert.equal(iced.getAttribute('aria-pressed'), 'false');
});

test('All Drinks shows every category and returning to it preserves size selection', () => {
    const h = catalogHarness();
    const all = h.make('all');
    const tea = h.make('tea');
    h.groups['.cat-tab'] = [all, tea];
    h.context.populateMenuData(products);
    assert.match(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.match(h.elements.get('menu-grid').innerHTML, /Classic Milk Tea/);
    h.context.switchCat(tea, 'milk-tea');
    h.context.switchSize(h.make('large-size'), 'large');
    h.context.switchCat(all, 'all');
    const html = h.elements.get('menu-grid').innerHTML;
    assert.match(html, /Spanish Latte/);
    assert.match(html, /Classic Milk Tea/);
    assert.match(html, /22oz Up Size/);
    assert.match(html, /₱110\.00/);
    assert.equal(all.getAttribute('aria-pressed'), 'true');
    assert.equal(tea.getAttribute('aria-pressed'), 'false');
});

test('catalog card add affordance has one keyboard target and reduced motion prevents tilting', () => {
    const h = catalogHarness();
    const card = h.make('card');
    h.groups['.menu-card:not(.sold-out)'] = [card];
    h.window.matchMedia = query => ({ matches: query === '(prefers-reduced-motion: reduce)' });
    h.context.populateMenuData(products);
    const html = h.elements.get('menu-grid').innerHTML;
    assert.match(html, /<span class="add-cart-chip" aria-hidden="true">\+ Add<\/span>/);
    assert.doesNotMatch(html, /<button[^>]*class="add-cart-chip"/);
    assert.equal(card.events.mousemove, undefined);
    h.window.matchMedia = () => ({ matches: false });
    h.context.renderGrid();
    assert.equal(typeof card.events.mousemove, 'function');
});

test('products are native buttons; sold-out buttons offer an availability notice', () => {
    const h = catalogHarness();
    h.context.populateMenuData(products);
    const html = h.elements.get('menu-grid').innerHTML;
    assert.match(html, /<button type="button" class="menu-card"/);
    assert.match(html, /class="menu-card sold-out" onclick="addToOrder\(3\)"/);
    assert.match(html, /sold out\. Show availability notice/);
    assert.match(html, /regular 16 ounces/);
});

test('product names and image paths cannot break out of HTML attributes', () => {
    const h = catalogHarness();
    h.context.populateMenuData([{ ...products[0], name: '"><img src=x onerror=alert(1)>', image_path: '" onerror="alert(1)' }]);
    const html = h.elements.get('menu-grid').innerHTML;
    assert.doesNotMatch(html, /<img src=x/);
    assert.match(html, /&quot;&gt;&lt;img/);
    assert.match(html, /&quot; onerror=&quot;/);
});

test('failed catalog load shows a retry action and retry recovers', async () => {
    let offline = true;
    const h = catalogHarness({ fetch: async () => {
        if (offline) throw new Error('Offline');
        return { ok: true, json: async () => products };
    } });
    await h.context.loadMenu();
    assert.match(h.elements.get('menu-grid').innerHTML, /Try again/);
    assert.equal(h.elements.get('menu-grid').getAttribute('aria-busy'), 'false');
    offline = false;
    await h.context.loadMenu();
    assert.match(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.doesNotMatch(h.elements.get('catalog-status').textContent, /Offline/);
});

test('invalid catalog response is handled as a loading failure', async () => {
    const h = catalogHarness({ fetch: async () => ({ ok: true, json: async () => ({ error: 'Invalid' }) }) });
    await h.context.loadMenu();
    assert.equal(h.elements.get('catalog-status').textContent, 'Catalog unavailable');
});

test('cached catalog stays visible with an offline indication', async () => {
    const h = catalogHarness();
    h.storage.set('kofee_pos_menu_cache', JSON.stringify(products));
    await h.context.loadMenu();
    assert.match(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.match(h.elements.get('catalog-status').textContent, /Offline catalog/);
});

test('unavailable browser storage does not prevent a fresh catalog from loading', async () => {
    const h = catalogHarness({ fetch: async () => ({ ok: true, json: async () => products }) });
    h.context.localStorage.getItem = () => { throw new Error('Storage unavailable'); };
    h.context.localStorage.setItem = () => { throw new Error('Storage unavailable'); };
    await h.context.loadMenu();
    assert.match(h.elements.get('menu-grid').innerHTML, /Spanish Latte/);
    assert.doesNotMatch(h.elements.get('catalog-status').textContent, /Offline/);
});

test('empty order disables actions; a populated order retains VAT-inclusive totals', () => {
    const h = catalogHarness();
    h.context.updateTotals();
    assert.equal(h.elements.get('review-order-btn').disabled, true);
    assert.equal(h.elements.get('mobile-cart-bar').inert, true);
    vm.runInContext("orderItems = [{ id: 1, name: 'Latte', price: 112, qty: 2 }]; updateTotals();", h.context);
    assert.equal(h.elements.get('review-order-btn').disabled, false);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '2 items');
    assert.equal(h.elements.get('subtotal').textContent, '₱200.00');
    assert.equal(h.elements.get('tax').textContent, '₱24.00');
    assert.equal(h.elements.get('total').textContent, '₱224.00');
});

test('Escape never changes the desktop sidebar preference', () => {
    const h = harness('sidebar-navigation.js');
    const panel = h.make('main-sidebar');
    h.make('sidebar-backdrop');
    h.context.toggleSidebar(false);
    h.events.keydown.forEach(callback => callback({ key: 'Escape' }));
    assert.equal(h.document.documentElement.classList.contains('sidebar-minimized'), false);
    assert.equal(panel.classList.contains('open'), false);
    assert.equal(h.storage.has('kfs_sidebar_minimized'), false);
});

test('mobile navigation closes on Escape and returns focus to its trigger', () => {
    const h = harness('sidebar-navigation.js', { width: 390 });
    const panel = h.make('main-sidebar');
    const trigger = h.make('sidebar-menu-btn');
    h.make('sidebar-backdrop');
    panel.children['button, a'] = h.make('first-control');
    h.context.toggleSidebar(true);
    assert.equal(panel.inert, false);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    h.events.keydown.forEach(callback => callback({ key: 'Escape' }));
    assert.equal(panel.inert, true);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(h.document.activeElement, trigger);
});

test('closed sidebar sections cannot receive keyboard focus', () => {
    const h = harness('sidebar-navigation.js');
    const group = h.make('cat-group-orders');
    const body = h.make('category-body');
    const header = h.make('category-header');
    group.children['.kfs-cat-body'] = body;
    group.children['.kfs-cat-header'] = header;
    h.context.setSidebarCategory(group, false);
    assert.equal(body.inert, true);
    h.context.toggleSidebarCategory('orders');
    assert.equal(body.inert, false);
    assert.equal(header.getAttribute('aria-expanded'), 'true');
});

test('resizing an open mobile menu to desktop restores access to the page', () => {
    const h = harness('sidebar-navigation.js', { width: 390 });
    const panel = h.make('main-sidebar');
    const main = h.make('page');
    h.make('sidebar-backdrop');
    h.make('sidebar-menu-btn');
    h.groups['.page, .pages, .kfs-approvals-wrap, body > main, #kofee-topbar'] = [main];
    h.context.toggleSidebar(true);
    assert.equal(main.inert, true);
    h.window.innerWidth = 1024;
    h.context.syncSidebarViewport();
    assert.equal(main.inert, false);
    assert.equal(panel.inert, false);
    assert.equal(panel.classList.contains('open'), false);
});

test('mobile current-order drawer hides background controls and restores focus', () => {
    const h = catalogHarness({ width: 390 });
    const close = h.make('close-cart');
    h.elements.get('order-panel').children['.op-mobile-close-btn'] = close;
    h.context.openMobileCart();
    assert.equal(h.groups['.menu-left'][0].inert, true);
    assert.equal(h.document.activeElement, close);
    h.context.closeMobileCart();
    assert.equal(h.groups['.menu-left'][0].inert, false);
    assert.equal(h.elements.get('order-panel').inert, true);
    assert.equal(h.document.activeElement, h.elements.get('mobile-cart-toggle'));
});

test('password visibility toggles without submitting the sign-in form', () => {
    const h = harness('auth-ui.js');
    const button = h.make('toggle-password');
    const field = h.make('password');
    field.type = 'password';
    h.events.DOMContentLoaded.forEach(callback => callback());
    button.events.click();
    assert.equal(field.type, 'text');
    assert.equal(button.getAttribute('aria-label'), 'Hide password');
    button.events.click();
    assert.equal(field.type, 'password');
    assert.equal(button.getAttribute('aria-pressed'), 'false');
});

test('clicking the storefront menu icon keeps the menu open; Escape closes it', () => {
    const h = harness('auth-ui.js');
    const toggle = h.make('navToggle');
    const links = h.make('public-navigation');
    const icon = h.make('detached-icon');
    h.groups['.nav-links'] = [links];
    const html = fs.readFileSync(path.join(__dirname, '../index.html'), 'utf8');
    const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
    vm.runInContext(script, h.context);
    toggle.events.click();
    h.events.click.forEach(callback => callback({ target: icon, composedPath: () => [icon, toggle] }));
    assert.equal(links.classList.contains('open'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    h.events.keydown.forEach(callback => callback({ key: 'Escape' }));
    assert.equal(links.classList.contains('open'), false);
    assert.equal(h.document.activeElement, toggle);
});

test('storefront drink preview confines keyboard focus and Escape restores the trigger', () => {
    const h = harness('auth-ui.js');
    h.window.matchMedia = () => ({ matches: true });
    h.document.body.children = [h.make('storefront')];
    const modal = h.make('menuPreviewModal');
    modal.hidden = true;
    const close = h.make('close-preview');
    const dismiss = h.make('dismiss-preview');
    modal.children['.preview-close-btn'] = close;
    modal.children['button, a[href]'] = [close, dismiss];
    h.make('previewNotes').appendChild = () => {};
    ['previewTitle', 'previewCategory', 'previewPrice', 'previewDesc', 'previewImage',
        'previewRoast', 'previewOrigin', 'previewServing', 'previewRecipe', 'toastContainer'].forEach(h.make);
    const trigger = h.make('preview-trigger');
    trigger.focus();
    const html = fs.readFileSync(path.join(__dirname, '../index.html'), 'utf8');
    vm.runInContext([...html.matchAll(/<script>([\s\S]*?)<\/script>/g)][1][1], h.context);
    h.context.openMenuPreview(null, 'Caramel Macchiato');
    assert.equal(modal.hidden, false);
    assert.equal(h.document.body.children[0].inert, true);
    assert.equal(h.elements.get('previewTitle').textContent, 'Caramel Macchiato');
    assert.equal(h.document.activeElement, close);
    let prevented = false;
    h.events.keydown.forEach(callback => callback({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } }));
    assert.equal(prevented, true);
    assert.equal(h.document.activeElement, dismiss);
    h.events.keydown.forEach(callback => callback({ key: 'Escape' }));
    assert.equal(modal.hidden, true);
    assert.equal(h.document.body.children[0].inert, false);
    assert.equal(h.document.activeElement, trigger);
});

function stockCartHarness() {
    const requests = [];
    const warnings = [];
    const h = catalogHarness({ fetch: async (url, options) => {
        const body = JSON.parse(options.body);
        requests.push(body);
        const needed = (body.items || []).reduce((sum, item) =>
            sum + item.qty * (item.id === 2 ? 4 : item.size === 'large' ? 3 : 2), 0);
        return { json: async () => ({ success: needed <= 10, error: 'Insufficient milk.' }) };
    } });
    h.context.Swal = { fire: options => warnings.push(options) };
    h.context.populateMenuData(products);
    return { ...h, requests, warnings };
}

test('catalog add and cart plus stop at ingredient stock without changing the cart on rejection', async () => {
    const h = stockCartHarness();
    for (let i = 0; i < 5; i++) await h.context.addToOrder(1);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '5 items');
    await h.context.addToOrder(1);
    await h.context.changeQty(0, 1);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '5 items');
    assert.equal(h.requests.at(-1).items[0].qty, 6);
    assert.equal(h.warnings.length, 2);
    assert.equal(h.warnings[0].title, 'Not enough stock');
    assert.match(h.warnings[0].text, /Spanish Latte cannot be added to checkout/);
    await h.context.changeQty(0, -1);
    await h.context.changeQty(0, 1);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '5 items');
});

test('sold-out product activation shows a warning without adding or requesting stock', async () => {
    const h = stockCartHarness();
    await h.context.addToOrder(3);
    assert.equal(h.requests.length, 0);
    assert.equal(h.warnings[0].title, 'Not enough stock');
    assert.match(h.elements.get('order-items').innerHTML, /order-empty/);
    assert.doesNotMatch(h.elements.get('order-items').innerHTML, /order-item-row/);
});

test('inventory authorization failures show the actual error and preserve the cart', async () => {
    const h = stockCartHarness();
    await h.context.addToOrder(1);
    h.context.fetch = async () => ({ ok: false, json: async () => ({
        success: false, code: 'SHIFT_REQUIRED', error: 'Clock in before taking orders.'
    }) });
    await h.context.changeQty(0, 1);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '1 item');
    assert.equal(h.warnings.at(-1).icon, 'error');
    assert.equal(h.warnings.at(-1).text, 'Clock in before taking orders.');
});

test('a malformed inventory response cannot increase the cart', async () => {
    const h = stockCartHarness();
    await h.context.addToOrder(1);
    h.context.fetch = async () => ({ json: async () => { throw new SyntaxError('Invalid JSON'); } });
    await h.context.addToOrder(1);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '1 item');
    assert.equal(h.warnings.at(-1).icon, 'error');
});

test('adding another drink or size includes shared ingredients from the existing cart', async () => {
    const h = stockCartHarness();
    for (let i = 0; i < 4; i++) await h.context.addToOrder(1);
    await h.context.addToOrder(2);
    assert.equal(h.requests.at(-1).items.length, 2);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '4 items');
    h.context.switchSize(h.make('large-size'), 'large');
    await h.context.addToOrder(1);
    assert.equal(h.requests.at(-1).items[1].size, 'large');
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '4 items');
});

test('failed inventory checks leave the cart unchanged and surface an error', async () => {
    const h = stockCartHarness();
    await h.context.addToOrder(1);
    h.context.fetch = async () => { throw new Error('Network unavailable'); };
    await h.context.changeQty(0, 1);
    assert.equal(h.elements.get('desktop-ticket-count').textContent, '1 item');
    assert.match(h.warnings.at(-1).text, /Unable to check inventory/);
});

test('receipt shows the saved order time in Manila rather than the current browser time', () => {
    const h = catalogHarness();
    h.make('r-time');
    h.make('receipt-overlay');
    h.context.showReceipt(42, 100, 12, 112, [], { orderTime: '2026-10-09 13:24:35' });
    assert.equal(h.elements.get('r-time').textContent, h.context.formatReceiptOrderTime('2026-10-09T05:24:35Z'));
    assert.match(h.elements.get('r-time').textContent, /Oct 9, 2026/);
    assert.match(h.elements.get('r-time').textContent, /1:24:35 PM/);
    assert.equal(h.context.formatReceiptOrderTime('invalid'), 'Unavailable');
    assert.equal(h.context.formatReceiptOrderTime(null), 'Unavailable');
});

test('cash checkout passes the saved timestamp to the receipt', async () => {
    const h = catalogHarness({ fetch: async () => ({
        text: async () => JSON.stringify({ success: true, order_id: 42, placed_at: '2026-10-09 13:24:35' })
    }) });
    h.make('cash-tendered').value = '112';
    h.make('confirm-overlay');
    h.make('receipt-overlay');
    h.make('r-time');
    vm.runInContext('pendingCheckout = { subtotal: 100, vat: 12, total: 112, snapshot: [], typeMap: {} };', h.context);
    await h.context.submitConfirmedOrder();
    assert.equal(h.elements.get('r-time').textContent, h.context.formatReceiptOrderTime('2026-10-09 13:24:35'));
});

test('PayMongo polling, manual check, and redirect use the order placement time', async () => {
    for (const flow of ['poll', 'manual', 'redirect']) {
        const h = catalogHarness({ fetch: async () => ({ json: async () => ({
            success: true, paid: true, status: 'pending', order: {
                id: 42, total: 112, items: [], placed_at: '2026-10-09 13:24:35', created_at: '2026-10-09 13:24:00'
            }
        }) }) });
        h.make('confirm-overlay');
        h.make('receipt-overlay');
        h.make('r-time');
        h.context.Swal = { fire() {} };
        h.context.clearInterval = () => {};
        vm.runInContext('payMongoOrderId = 42;', h.context);
        if (flow === 'poll') {
            let poll;
            h.context.setInterval = callback => { poll = callback; return 1; };
            h.context.startPayMongoPolling(42);
            await poll();
        } else if (flow === 'manual') {
            h.context.checkPayMongoStatusManual();
            await new Promise(resolve => setImmediate(resolve));
        } else {
            h.window.location.search = '?paymongo_success=1&order_id=42';
            h.window.history = { replaceState() {} };
            h.document.body.appendChild = () => {};
            h.events.DOMContentLoaded.at(-1)();
            await new Promise(resolve => setImmediate(resolve));
        }
        assert.equal(h.elements.get('r-time').textContent, h.context.formatReceiptOrderTime('2026-10-09 13:24:35'), flow);
    }
});

test('paid PayMongo orders render Pending with only Done and complete only on staff confirmation', async () => {
    const updates = [];
    const order = { id: 42, payment_method: 'paymongo', status: 'pending', total_amount: '112', created_at: '2026-10-09 13:24:35', items: 'Coffee x1' };
    const h = catalogHarness({ fetch: async (url, options) => {
        if (options?.method === 'POST') {
            updates.push(JSON.parse(options.body));
            return { json: async () => ({ success: true }) };
        }
        return { json: async () => [order] };
    } });
    ['orders-grid', 'pending-meta', 'confirm-overlay', 'conf-icon', 'conf-title', 'conf-message', 'conf-ok'].forEach(h.make);
    h.context.setInterval = () => 1;
    const page = fs.readFileSync(path.join(__dirname, '../php/pending_orders.php'), 'utf8');
    vm.runInContext(page.match(/<script>([\s\S]*?)<\/script>/)[1], h.context);
    await h.context.loadOrders();
    const grid = h.elements.get('orders-grid');
    assert.match(grid.innerHTML, /status-pending/);
    assert.match(grid.innerHTML, /btn-complete/);
    assert.doesNotMatch(grid.innerHTML, /btn-cancel|askConfirm\(42, 'cancelled'\)/);
    assert.equal(updates.length, 0);
    h.context.askConfirm(42, 'completed');
    await h.context.confirmAction();
    assert.deepEqual(updates, [{ order_id: 42, status: 'completed' }]);
    assert.match(grid.innerHTML, /status-completed/);
});
