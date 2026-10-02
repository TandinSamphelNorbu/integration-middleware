async function request(url, method = 'GET') {
    const response = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        credentials: 'same-origin',
    });
    if (response.status === 401 || response.status === 419) {
        throw new Error('Your session has expired. Reload the page and sign in again.');
    }
    if (response.status === 429) throw new Error('Too many requests. Please wait a minute and try again.');
    const data = await response.json().catch(() => null);
    if (response.status >= 500) throw new Error('The service is unavailable. Please try again shortly.');
    if (!response.ok || !data || data.success === false) throw new Error(data?.message || 'The request could not be completed.');
    return data;
}

function element(tag, classes, text) {
    const node = document.createElement(tag);
    node.className = classes;
    if (text !== undefined) node.textContent = text;
    return node;
}

function renderPlans(data, source, target) {
    const groups = source === 'catalog' ? (data.dataPlans || []).map(group => ({ name: group.Category, plans: group.Plans })) : [{ name: `${data.plan_type} broadband · GST ${data.GST}`, plans: data.plans || [] }];
    let count = 0;
    for (const group of groups) {
        if (!group.plans?.length) continue;
        target.append(element('h3', 'mb-4 mt-2 text-sm font-semibold text-slate-600', group.name));
        const grid = element('div', 'mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3');
        for (const plan of group.plans) {
            count++;
            const card = element('article', 'rounded-xl border border-slate-200 p-5');
            card.append(element('h4', 'font-semibold', plan.Name ?? plan.plan_name));
            const price = plan.Price ?? plan.totalAmount;
            card.append(element('p', 'mt-4 text-2xl font-semibold tracking-tight', `Nu. ${price ?? '—'}`));
            const details = source === 'catalog' ? [['Data', plan.DataBucket], ['Validity', plan.Validity]] : [['Data', plan.data_cap], ['Max speed', plan.max_speed], ['Base price', plan.amount], ['GST', plan.gstAmount]];
            const list = element('dl', 'mt-4 space-y-2 text-sm');
            for (const [label, value] of details) {
                const row = element('div', 'flex justify-between gap-3');
                row.append(element('dt', 'text-slate-500', label), element('dd', 'text-right', value ?? '—'));
                list.append(row);
            }
            card.append(list);
            grid.append(card);
        }
        target.append(grid);
    }
    if (!count) target.append(element('p', 'py-10 text-center text-sm text-slate-500', 'No eligible plans were found for this subscriber.'));
    return count;
}

function formatPostpaidPrice(price) {
    if (price === null || price === undefined || String(price).trim() === '') return '—';
    const value = String(price).trim();
    return /^\d+(?:\.\d+)?$/.test(value) ? `Nu. ${value}` : value;
}

function appendDetails(target, details) {
    const list = element('dl', 'mt-4 space-y-2 text-sm');
    for (const [label, value] of details) {
        const row = element('div', 'flex justify-between gap-3');
        row.append(element('dt', 'text-slate-500', label), element('dd', 'text-right', value ?? '—'));
        list.append(row);
    }
    target.append(list);
}

function postpaidPlanCard(plan, includeSpeeds = true) {
    const card = element('article', 'rounded-xl border border-slate-200 p-5');
    card.append(element('h4', 'font-semibold', plan.Name ?? 'Unnamed plan'));
    const isTarget = plan.id !== undefined;
    const details = isTarget
        ? [['Plan ID', plan.id], ['Category', plan['5GOr4G']], ['Data Cap', `${plan.MaxGB} GB`], ['Maximum Speed', plan.MaxSpeed], ['CBS ID', plan.CBSId], ['CRM offering ID', plan.CRMOfferingId]]
        : [['Price', formatPostpaidPrice(plan.price)], ['Data Cap', plan.data_cap]];
    if (!isTarget && includeSpeeds) details.push(['Maximum Speed', plan.max_speed], ['Default Speed', plan.default_speed]);
    appendDetails(card, details);
    return card;
}

function planSelection(target) {
    const summary = element('section', 'mb-6 rounded-xl border border-teal-200 bg-teal-50 p-5');
    summary.setAttribute('aria-live', 'polite');
    summary.hidden = true;
    target.append(summary);
    const choices = [];
    return (card, name, details) => {
        const button = element('button', 'button-secondary mt-5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700', 'Select plan');
        button.type = 'button';
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', `Select ${name}`);
        choices.push({ card, button });
        button.addEventListener('click', () => {
            for (const choice of choices) {
                const selected = choice.button === button;
                choice.button.setAttribute('aria-pressed', String(selected));
                choice.button.textContent = selected ? 'Selected' : 'Select plan';
                choice.card.classList.toggle('border-teal-600', selected);
                choice.card.classList.toggle('border-slate-200', !selected);
            }
            summary.replaceChildren(element('h3', 'font-semibold text-teal-900', 'Selected plan'), element('p', 'mt-2 text-sm', name));
            appendDetails(summary, details);
            summary.append(element('p', 'mt-4 text-sm text-teal-900', 'Selection is for review only. No service change has been submitted.'));
            summary.hidden = false;
        });
        card.append(button);
    };
}

function renderPostpaidPlans(data, target) {
    const section = title => {
        const node = element('section', 'mb-6');
        node.append(element('h3', 'mb-4 mt-2 text-sm font-semibold text-slate-600', title));
        target.append(node);
        return node;
    };
    appendDetails(section('Subscriber Summary'), [
        ['Subscriber Type', data.subscription],
        ['Base Plan', data.BasePlan],
        ['Current Plan', data.currentBasePlan?.Name || data.bandwidth || '—'],
    ]);

    const current = section('Current Plan Details');
    if (data.currentBasePlan) current.append(postpaidPlanCard(data.currentBasePlan));
    else current.append(element('p', 'text-sm text-slate-500', 'No current plan details available.'));

    const usageSection = section('Usage');
    const usage = Array.isArray(data.usage) ? data.usage : [];
    if (!usage.length) usageSection.append(element('p', 'text-sm text-slate-500', 'No usage information available.'));
    for (const entry of usage) {
        const card = element('article', 'mb-4 rounded-xl border border-slate-200 p-5');
        appendDetails(card, [
            ['Plan', entry.planName || entry.type || '—'],
            ['Type', entry.type],
            ['Initial Allowance', entry.initialAmount],
            ['Remaining Allowance', entry.remainingAmount],
        ]);
        usageSection.append(card);
    }

    let count = 0;
    for (const [title, offerings, includeSpeeds, emptyMessage] of [
        ['Available Base Plans', data.basePlanOfferings, true, 'No alternative base plans available.'],
        ['Add-ons', data.addOnOfferings, false, 'No add-ons available.'],
    ]) {
        const plans = Array.isArray(offerings) ? offerings : [];
        const group = section(`${title} (${plans.length})`);
        count += plans.length;
        if (!plans.length) {
            group.append(element('p', 'text-sm text-slate-500', emptyMessage));
            continue;
        }
        const selectPlan = includeSpeeds ? planSelection(group) : null;
        const grid = element('div', 'mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3');
        for (const plan of plans) {
            const card = postpaidPlanCard(plan, includeSpeeds);
            if (selectPlan && plan.id !== undefined) selectPlan(card, plan.Name, [['Plan ID', plan.id], ['Category', plan['5GOr4G']], ['CBS ID', plan.CBSId], ['CRM offering ID', plan.CRMOfferingId]]);
            grid.append(card);
        }
        group.append(grid);
    }
    return count;
}

function renderIllPlans(data, target) {
    if (!data.base_plan || !Array.isArray(data.addons)) throw new Error('Unexpected ILL response.');
    appendDetails(target, [['Subscriber type', data.subscription], ['Base plan', data.base_plan.name], ['Base plan ID', data.base_plan.id]]);
    target.append(element('h3', 'mb-4 mt-6 font-semibold', `Available ILL Plans (${data.addons.length})`));
    const selectPlan = planSelection(target);
    const grid = element('div', 'mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3');
    for (const addon of data.addons) {
        const card = element('article', 'rounded-xl border border-slate-200 p-5');
        card.append(element('h3', 'font-semibold', addon.name));
        const details = [['Plan ID', addon.id], ['Bandwidth', `${addon.bandwidth} Mbps`], ['Service type', addon.service_type], ['CRM offering ID', addon.crm_offering_id]];
        appendDetails(card, details);
        selectPlan(card, addon.name, details);
        grid.append(card);
    }
    target.append(grid);
    if (!data.addons.length) target.append(element('p', 'py-10 text-center text-sm text-slate-500', 'No selectable ILL plans are available for this subscriber.'));
    return data.addons.length;
}

const form = document.querySelector('#lookup-form');
if (form) {
    const source = document.querySelector('#plan-source');
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button');
        const status = document.querySelector('#lookup-status');
        const results = document.querySelector('#plan-results');
        const panel = document.querySelector('#results-panel');
        const count = document.querySelector('#result-count');
        const selectedSource = source.value;
        const values = new FormData(form);
        const url = new URL(selectedSource === 'ill' ? form.dataset.illUrl : selectedSource === 'fwa' ? form.dataset.fwaUrl : form.dataset.catalogUrl);
        url.search = new URLSearchParams({ service_id: values.get('service_id') }).toString();
        button.disabled = true;
        panel.setAttribute('aria-busy', 'true');
        results.replaceChildren();
        count.textContent = 'Loading';
        status.textContent = 'Looking up eligible plans…';
        try {
            const data = await request(url);
            const isPostpaid = selectedSource === 'fwa' && data.subscription === 'Postpaid';
            if (selectedSource === 'fwa' && !isPostpaid && !(data.plan_type && data.primary_offering_id && Array.isArray(data.plans))) {
                throw new Error('Unexpected FWA response.');
            }
            const total = selectedSource === 'ill' ? renderIllPlans(data, results) : isPostpaid ? renderPostpaidPlans(data, results) : renderPlans(data, selectedSource, results);
            count.textContent = `${total} plans`;
            status.textContent = selectedSource === 'ill'
                ? `Results for ${values.get('service_id')} · ${data.base_plan.name} · Normal ILL plans`
                : isPostpaid
                ? `Results for ${values.get('service_id')} · ${data.subscription} · ${data.currentBasePlan?.Name || data.bandwidth || 'Current plan unavailable'}`
                : `Results for ${values.get('service_id')} · Primary offering ${data.poId ?? data.primary_offering_id ?? '—'}`;
        } catch (error) {
            count.textContent = 'Lookup failed';
            status.textContent = selectedSource === 'ill' ? 'Unable to retrieve ILL offerings. Check the service number and ILL eligibility, then try again.' : error.message;
        } finally {
            button.disabled = false;
            panel.setAttribute('aria-busy', 'false');
        }
    });
}

document.querySelectorAll('[data-action-url]').forEach(button => {
    button.addEventListener('click', async () => {
        if (!window.confirm(button.dataset.confirm)) return;
        const status = button.parentElement.querySelector('.action-status');
        button.disabled = true;
        status.textContent = 'Working…';
        try {
            const data = await request(button.dataset.actionUrl, 'POST');
            status.textContent = data.offerings_cached !== undefined
                ? `${data.offerings_cached} ILL offerings cached successfully.`
                : data.plans_synced !== undefined ? `${data.plans_synced} plans synced at ${data.synced_at}.` : `${data.plans_cached} plans and ${data.student_numbers_cached} student numbers cached.`;
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
});
