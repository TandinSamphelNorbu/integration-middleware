import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

class Element {
    constructor(tag) {
        this.tagName = tag;
        this.children = [];
        this.attributes = {};
        this.events = {};
        this.hidden = false;
        this.className = '';
        this.ownText = '';
        this.classList = {
            toggle: (name, enabled) => {
                const classes = new Set(this.className.split(' '));
                if (enabled) classes.add(name);
                else classes.delete(name);
                this.className = [...classes].join(' ');
            },
        };
    }
    set textContent(value) { this.ownText = String(value); this.children = []; }
    get textContent() { return this.ownText + this.children.map(child => child.textContent).join(' '); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.ownText = ''; this.children = children; }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener(name, handler) { this.events[name] = handler; }
    click() { this.events.click(); }
    get innerHTML() { throw new Error('Catalog names must be rendered as text.'); }
    set innerHTML(value) { throw new Error('Catalog names must be rendered as text.'); }
}

function allNodes(element) {
    return [element, ...element.children.flatMap(allNodes)];
}

function render(functionName, data, source) {
    const target = new Element('div');
    const context = vm.createContext({
        document: { createElement: tag => new Element(tag), querySelector: () => null, querySelectorAll: () => [] },
        target, data, source,
        fetch: () => { throw new Error('Selecting plans must not send requests.'); },
    });
    vm.runInContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), context);
    const count = vm.runInContext(functionName === 'renderPlans' ? 'renderPlans(data, source, target)' : `${functionName}(data, target)`, context);
    return { target, count };
}

test('Normal ILL cards display bandwidth, canonical service type, and distinct IDs; selection stays local', () => {
    const { target, count } = render('renderIllPlans', {
        subscription: 'Postpaid', base_plan: { id: '109', name: 'ILL Main Offering' },
        addons: [
            { id: 34, crm_offering_id: 28, name: '<script>20 Mbps Standard</script>', bandwidth: 20, service_type: 'Standard' },
            { id: 56, crm_offering_id: 673, name: '50 Mbps Premium', bandwidth: 50, service_type: 'Premium' },
        ],
    });
    assert.equal(count, 2);
    assert.match(target.textContent, /20 Mbps/);
    assert.match(target.textContent, /Service type Standard/);
    assert.match(target.textContent, /Plan ID 34/);
    assert.match(target.textContent, /CRM offering ID 28/);
    assert.ok(allNodes(target).some(node => node.tagName === 'h3' && node.textContent === '<script>20 Mbps Standard</script>'));
    const buttons = allNodes(target).filter(node => node.tagName === 'button');
    buttons[0].click();
    assert.equal(buttons[0].attributes['aria-pressed'], 'true');
    const summary = allNodes(target).find(node => node.attributes['aria-live'] === 'polite');
    assert.equal(summary.hidden, false);
    assert.match(summary.textContent, /Plan ID 34/);
    assert.match(summary.textContent, /No service change has been submitted/);
    buttons[1].click();
    assert.equal(buttons[0].attributes['aria-pressed'], 'false');
    assert.equal(buttons[1].attributes['aria-pressed'], 'true');
    assert.match(summary.textContent, /Plan ID 56/);
    assert.doesNotMatch(summary.textContent, /Plan ID 34/);
});

test('Postpaid FWA target cards select table Id while displaying CBS and CRM IDs as metadata', () => {
    const { target, count } = render('renderPostpaidPlans', {
        subscription: 'Postpaid', BasePlan: '5G Unlimited', bandwidth: 'Current_Postpaid',
        currentBasePlan: { Id: 1801771352, Name: 'Current_Postpaid', price: 'Nu. 1477', data_cap: '300 GB' },
        basePlanOfferings: [{ Id: 27, id: 27, CBSId: 1201771411, CRMOfferingId: 921, Name: '5GHome 1777_Postpaid', '5GOr4G': '5G ILL', MaxGB: '240', MaxSpeed: '30 Mbps' }],
        addOnOfferings: [{ Id: 1304191889, Name: 'Booster', price: 150, data_cap: '10 GB' }],
        usage: [{ planName: 'Current_Postpaid', remainingAmount: '164.64 GB' }],
    });
    assert.equal(count, 2);
    assert.match(target.textContent, /Data Cap 240 GB/);
    assert.match(target.textContent, /Maximum Speed 30 Mbps/);
    assert.match(target.textContent, /CBS ID 1201771411/);
    assert.match(target.textContent, /CRM offering ID 921/);
    assert.match(target.textContent, /164.64 GB/);
    const buttons = allNodes(target).filter(node => node.tagName === 'button');
    assert.equal(buttons.length, 1);
    buttons[0].click();
    const summary = allNodes(target).find(node => node.attributes['aria-live'] === 'polite');
    assert.match(summary.textContent, /Plan ID 27/);
    assert.doesNotMatch(summary.textContent, /Plan ID (1201771411|921|1801771352)/);
});

test('Empty Normal ILL catalog displays a controlled empty state without selection buttons', () => {
    const { target, count } = render('renderIllPlans', { base_plan: { id: '109', name: 'ILL Main Offering' }, addons: [] });
    assert.equal(count, 0);
    assert.match(target.textContent, /No selectable ILL plans are available/);
    assert.equal(allNodes(target).filter(node => node.tagName === 'button').length, 0);
});

test('Mobile and prepaid FWA render their existing prices and details without target selection', () => {
    const mobile = render('renderPlans', { dataPlans: [{ Category: 'Normal', Plans: [{ Name: 'Mobile plan', Price: 100, DataBucket: '10 GB', Validity: '30 days' }] }] }, 'catalog');
    assert.equal(mobile.count, 1);
    assert.match(mobile.target.textContent, /Nu. 100/);
    assert.match(mobile.target.textContent, /30 days/);
    const prepaid = render('renderPlans', { plan_type: '5G', GST: '5%', plans: [{ plan_name: 'Prepaid FWA', totalAmount: 105, amount: 100, gstAmount: 5, data_cap: '10 GB', max_speed: '15 Mbps' }] }, 'fwa');
    assert.equal(prepaid.count, 1);
    assert.match(prepaid.target.textContent, /Nu. 105/);
    assert.match(prepaid.target.textContent, /Base price 100/);
    assert.match(prepaid.target.textContent, /GST 5/);
    for (const { target } of [mobile, prepaid]) assert.equal(allNodes(target).filter(node => node.tagName === 'button').length, 0);
});
