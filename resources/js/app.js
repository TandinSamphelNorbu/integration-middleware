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
            card.append(element('p', 'mt-4 text-2xl font-semibold tracking-tight', `Nu. ${plan.Price ?? plan.totalAmount ?? '—'}`));
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

const form = document.querySelector('#lookup-form');
if (form) {
    const source = document.querySelector('#plan-source');
    source.addEventListener('change', () => {
        document.querySelector('#subscriber-type').disabled = source.value === 'fwa';
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button');
        const status = document.querySelector('#lookup-status');
        const results = document.querySelector('#plan-results');
        const panel = document.querySelector('#results-panel');
        const count = document.querySelector('#result-count');
        const selectedSource = source.value;
        const values = new FormData(form);
        const url = new URL(selectedSource === 'catalog' ? form.dataset.catalogUrl : form.dataset.fwaUrl);
        url.search = new URLSearchParams(values).toString();
        button.disabled = true;
        panel.setAttribute('aria-busy', 'true');
        results.replaceChildren();
        count.textContent = 'Loading';
        status.textContent = 'Looking up eligible plans…';
        try {
            const data = await request(url);
            const total = renderPlans(data, selectedSource, results);
            count.textContent = `${total} plans`;
            status.textContent = `Results for ${values.get('service_id')} · Primary offering ${data.poId ?? data.primary_offering_id ?? '—'}`;
        } catch (error) {
            count.textContent = 'Lookup failed';
            status.textContent = error.message;
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
            status.textContent = data.plans_synced !== undefined ? `${data.plans_synced} plans synced at ${data.synced_at}.` : `${data.plans_cached} plans and ${data.student_numbers_cached} student numbers cached.`;
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
});
