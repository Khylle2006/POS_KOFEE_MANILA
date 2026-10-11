const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const analyticsPhpPath = path.join(__dirname, '..', 'php', 'analytics.php');
const analyticsCssPath = path.join(__dirname, '..', 'css', 'analytics.css');
const getAnalyticsApi = path.join(__dirname, '..', 'api', 'get_analytics.php');

test('analytics.php includes Total Sales, Total Expenses, and Total Profit cards', () => {
    const html = fs.readFileSync(analyticsPhpPath, 'utf8');

    // Hero financial elements
    assert.match(html, /id="s-total-sales"/, 'Should contain s-total-sales ID');
    assert.match(html, /id="s-total-expenses"/, 'Should contain s-total-expenses ID');
    assert.match(html, /id="s-total-profit"/, 'Should contain s-total-profit ID');
    assert.match(html, /id="s-profit-margin"/, 'Should contain s-profit-margin ID');
    assert.match(html, /id="s-profit-status"/, 'Should contain s-profit-status ID');

    // Period controls
    assert.match(html, /data-period="all"/, 'Should have All Time period tab');
    assert.match(html, /data-period="month"/, 'Should have This Month period tab');
    assert.match(html, /data-period="week"/, 'Should have Last 7 Days period tab');

    // Operational elements preserved
    assert.match(html, /id="s-weekly-orders"/, 'Should preserve s-weekly-orders ID');
    assert.match(html, /id="s-cups"/, 'Should preserve s-cups ID');
    assert.match(html, /id="s-best-cat"/, 'Should preserve s-best-cat ID');
    assert.match(html, /id="s-avg-order"/, 'Should contain s-avg-order ID');

    // Breakdown modal
    assert.match(html, /id="btn-expense-breakdown"/, 'Should have expense breakdown button');
    assert.match(html, /id="expense-modal-backdrop"/, 'Should have expense modal backdrop');
    assert.match(html, /id="breakdown-procurement"/, 'Should have procurement row in breakdown');
    assert.match(html, /id="breakdown-payroll"/, 'Should have payroll row in breakdown');
    assert.match(html, /id="breakdown-inventory"/, 'Should have inventory row in breakdown');
});

test('api/get_analytics.php computes sales, expenses, and profit for all time, monthly, and weekly', () => {
    const apiCode = fs.readFileSync(getAnalyticsApi, 'utf8');

    assert.match(apiCode, /'total_sales'/, 'API must return total_sales');
    assert.match(apiCode, /'total_expenses'/, 'API must return total_expenses');
    assert.match(apiCode, /'total_profit'/, 'API must return total_profit');
    assert.match(apiCode, /'profit_margin'/, 'API must return profit_margin');

    assert.match(apiCode, /'weekly_sales'/, 'API must return weekly_sales');
    assert.match(apiCode, /'weekly_expenses'/, 'API must return weekly_expenses');
    assert.match(apiCode, /'weekly_profit'/, 'API must return weekly_profit');

    assert.match(apiCode, /'monthly_sales'/, 'API must return monthly_sales');
    assert.match(apiCode, /'monthly_expenses'/, 'API must return monthly_expenses');
    assert.match(apiCode, /'monthly_profit'/, 'API must return monthly_profit');

    assert.match(apiCode, /'expense_breakdown'/, 'API must return expense_breakdown');
});

test('css/analytics.css includes responsive layout and styling for financial cards', () => {
    const css = fs.readFileSync(analyticsCssPath, 'utf8');

    assert.match(css, /\.analytics-financial-row/, 'CSS must style .analytics-financial-row');
    assert.match(css, /\.stat-profit/, 'CSS must style .stat-profit');
    assert.match(css, /\.stat-profit\.is-negative/, 'CSS must handle negative profit styling');
    assert.match(css, /\.stat-tag/, 'CSS must style stat tags');
    assert.match(css, /\.breakdown-modal/, 'CSS must style expense breakdown modal');
});

test('currency and profit calculation simulator handles zero, positive, and negative values', () => {
    function formatCurrency(val) {
        const num = Number(val) || 0;
        return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    assert.equal(formatCurrency(0), '₱0.00');
    assert.equal(formatCurrency(1250.5), '₱1,250.50');
    assert.equal(formatCurrency(100000), '₱100,000.00');

    // Positive profit test
    const salesPos = 50000;
    const expPos = 20000;
    const profitPos = salesPos - expPos;
    const marginPos = (profitPos / salesPos) * 100;
    assert.equal(profitPos, 30000);
    assert.equal(marginPos, 60);

    // Negative profit test
    const salesNeg = 10000;
    const expNeg = 15000;
    const profitNeg = salesNeg - expNeg;
    const marginNeg = (profitNeg / salesNeg) * 100;
    assert.equal(profitNeg, -5000);
    assert.equal(marginNeg, -50);
    assert.equal('-' + formatCurrency(Math.abs(profitNeg)), '-₱5,000.00');
});

function analyticsHarness() {
    const elements = new Map();
    const windowEvents = new Map();
    const intervals = [];
    const requests = [];
    const element = id => {
        if (!elements.has(id)) {
            const classes = new Set();
            elements.set(id, {
                textContent: '', innerHTML: '', style: {}, events: {}, attributes: {},
                classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
                addEventListener(type, callback) { this.events[type] = callback; },
                getAttribute(name) { return this.attributes[name]; },
                setAttribute(name, value) { this.attributes[name] = value; },
                focus() {},
            });
        }
        return elements.get(id);
    };
    const buttons = ['all', 'month', 'week'].map(period => {
        const button = element(period);
        button.attributes['data-period'] = period;
        return button;
    });
    const document = { hidden: false, getElementById: element,
        querySelectorAll: () => buttons, addEventListener() {} };
    const data = {
        total_sales: 1000, total_expenses: 400, total_profit: 600, profit_margin: 60, total_orders: 10, avg_order_value: 100,
        monthly_sales: 500, monthly_expenses: 150, monthly_profit: 350, monthly_profit_margin: 70, monthly_orders: 5, monthly_avg_order: 100,
        weekly_sales: 200, weekly_expenses: 50, weekly_profit: 150, weekly_profit_margin: 75, weekly_orders: 2, weekly_avg_order: 100,
        cups: 2, best_category: 'Coffee', daily_sales: [], categories: [], top_items: [],
        expense_breakdown: {
            all: { procurement: 100, payroll: 300, inventory: 0, other: 0 },
            monthly: { procurement: 50, payroll: 100, inventory: 0, other: 0 },
            weekly: { procurement: 20, payroll: 30, inventory: 0, other: 0 },
        },
    };
    let fail = false;
    const context = vm.createContext({ document,
        window: { addEventListener: (type, callback) => windowEvents.set(type, callback) },
        setInterval: callback => intervals.push(callback), console: { error() {} },
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { ok: !fail, json: async () => data };
        },
    });
    const scripts = [...fs.readFileSync(analyticsPhpPath, 'utf8').matchAll(/<script>([\s\S]*?)<\/script>/g)];
    vm.runInContext(scripts.at(-1)[1], context);
    return { elements, buttons, document, data, windowEvents, intervals, requests, setFailure: value => { fail = value; } };
}

const settleAnalytics = () => new Promise(resolve => setImmediate(resolve));

test('financial cards and expense breakdown stay consistent across all, monthly, and weekly periods', async () => {
    const h = analyticsHarness();
    await settleAnalytics();
    assert.equal(h.elements.get('s-total-expenses').textContent, '₱400.00');
    for (const [index, total, procurement, payroll] of [[1, '₱150.00', '₱50.00', '₱100.00'], [2, '₱50.00', '₱20.00', '₱30.00']]) {
        h.buttons[index].events.click.call(h.buttons[index]);
        assert.equal(h.elements.get('s-total-expenses').textContent, total);
        assert.equal(h.elements.get('breakdown-total').textContent, total);
        assert.equal(h.elements.get('breakdown-procurement').textContent, procurement);
        assert.equal(h.elements.get('breakdown-payroll').textContent, payroll);
        assert.equal(h.elements.get('breakdown-inventory').textContent, 'Included above');
    }
});

test('returning from payroll or procurement refreshes expenses and the visible page polls for updates', async () => {
    const h = analyticsHarness();
    await settleAnalytics();
    h.data.total_expenses = 600;
    h.data.total_profit = 400;
    h.data.profit_margin = 40;
    h.data.expense_breakdown.all.payroll = 500;
    await h.windowEvents.get('focus')();
    assert.equal(h.elements.get('s-total-expenses').textContent, '₱600.00');
    assert.equal(h.elements.get('s-total-profit').textContent, '₱400.00');
    assert.equal(h.elements.get('breakdown-payroll').textContent, '₱500.00');
    assert.equal(h.requests.at(-1).options.cache, 'no-store');
    h.document.hidden = true;
    h.intervals[0]();
    assert.equal(h.requests.length, 2);
    h.document.hidden = false;
    h.intervals[0]();
    await settleAnalytics();
    assert.equal(h.requests.length, 3);
});

test('failed refreshes report unavailable expenses and a later refresh recovers', async () => {
    const h = analyticsHarness();
    await settleAnalytics();
    h.setFailure(true);
    await h.windowEvents.get('focus')();
    assert.match(h.elements.get('sub-expenses').textContent, /Unable to refresh/);
    assert.equal(h.elements.get('s-total-expenses').textContent, '₱400.00');
    h.setFailure(false);
    await h.windowEvents.get('focus')();
    assert.equal(h.elements.get('sub-expenses').textContent, 'Paid procurement, payroll & operating costs');
});
