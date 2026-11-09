/*
 * Sales editor behaviours, opt-in by data attribute.
 *   [data-sx-lines] + [data-sx-add-line] + <template data-sx-line-template>   add and remove lines
 *   form[data-sx-editor][data-calc-url]                                       live preview: the SERVER works out every figure
 *
 * The browser never decides a total. It sends what was typed to the calculation endpoint and writes the strings that
 * come back into the page (textContent only: nothing returned is ever parsed as HTML).
 */
const nextIndex = (container) => {
    const used = Array.from(container.querySelectorAll('[name^="lines["]')).map((el) => parseInt(el.name.match(/^lines\[(\d+)\]/)?.[1] ?? '-1', 10));
    return (used.length ? Math.max(...used) : -1) + 1;
};

document.addEventListener('click', (event) => {
    const add = event.target.closest?.('[data-sx-add-line]');
    if (add) {
        const panel = add.closest('.fx-panel__body') || document;
        const container = panel.querySelector('[data-sx-lines]');
        const template = panel.querySelector('template[data-sx-line-template]');
        if (!container || !template) return;
        const html = template.innerHTML.replaceAll('__I__', String(nextIndex(container)));
        container.insertAdjacentHTML('beforeend', html);
        container.lastElementChild?.querySelector('input:not([type=hidden])')?.focus();
        container.closest('form')?.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    const remove = event.target.closest?.('[data-sx-remove-line]');
    if (remove) {
        const line = remove.closest('[data-sx-line]');
        const container = line?.parentElement;
        if (!line || !container) return;
        if (container.querySelectorAll('[data-sx-line]').length <= 1) {
            line.querySelectorAll('input:not([type=hidden])').forEach((el) => { el.value = el.name.endsWith('[quantity]') ? '1' : ''; });
        } else {
            line.remove();
        }
        container.closest('form')?.dispatchEvent(new Event('input', { bubbles: true }));
    }
});

const text = (root, selector, value) => {
    root.querySelectorAll(selector).forEach((el) => { el.textContent = value ?? '–'; });
};

const wireEditor = (form) => {
    const url = form.dataset.calcUrl;
    const status = form.querySelector('[data-sx-status]');
    const checklist = form.querySelector('[data-sx-checklist]');
    let timer = null;
    let controller = null;
    let sequence = 0;

    const render = (data) => {
        const lines = form.querySelectorAll('[data-sx-line]');
        lines.forEach((line, i) => {
            const amount = line.querySelector('[data-sx-line-amount]');
            if (amount) amount.textContent = data.lines?.[i] ? data.lines[i].net : '';
        });
        text(form, '[data-sx="net"]', data.totals.net);
        text(form, '[data-sx="discount"]', data.totals.discount);
        text(form, '[data-sx="tax"]', data.totals.tax);
        text(form, '[data-sx="total"]', data.totals.total);

        const base = form.querySelector('[data-sx-base]');
        if (base) {
            base.hidden = !data.base;
            base.textContent = data.base ? `${data.base.total} in ${data.base.currency} at ${data.base.rate} (${data.base.source}, ${data.base.as_of})` : '';
        }

        const summary = form.querySelector('[data-sx-summary]');
        if (summary) {
            summary.replaceChildren(...data.summary.filter((s) => s.tax !== data.totals.tax || data.summary.length > 1 || data.summary[0].label !== 'No tax').map((s) => {
                const li = document.createElement('li');
                li.textContent = `${s.label}: ${s.net} + ${s.tax} tax`;
                return li;
            }));
        }

        const due = form.querySelector('[data-sx-due]');
        if (due) due.textContent = data.due_date ? `Due ${data.due_date}` : (data.valid_until ? `Valid until ${data.valid_until}` : '');

        const warnings = form.querySelector('[data-sx-warnings]');
        if (warnings) {
            warnings.replaceChildren(...data.warnings.map((w) => { const li = document.createElement('li'); li.textContent = w; return li; }));
        }

        if (checklist) {
            checklist.replaceChildren(...data.checklist.items.map((item) => {
                const li = document.createElement('li');
                li.className = item.ok ? 'is-ok' : (item.blocking ? 'is-bad' : 'is-note');
                const mark = document.createElement('span');
                mark.className = 'sx-checklist__mark';
                mark.textContent = item.ok ? 'Done' : (item.blocking ? 'Fix' : 'Note');
                const body = document.createElement('span');
                const strong = document.createElement('strong');
                strong.textContent = item.label;
                const detail = document.createElement('span');
                detail.className = 'sx-muted';
                detail.textContent = ` ${item.detail}`;
                body.append(strong, detail);
                li.append(mark, body);
                return li;
            }));
        }

        if (status) status.textContent = `Worked out by the server. Rounding: ${data.label.rounding ?? 'none (no VAT)'}.`;
    };

    const run = async () => {
        controller?.abort();
        controller = new AbortController();
        const mine = ++sequence;
        const body = new FormData(form);
        body.delete('_method');
        if (status) status.textContent = 'Working it out…';

        try {
            const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal, credentials: 'same-origin' });
            const data = await response.json().catch(() => null);
            if (mine !== sequence) return;
            if (response.ok && data?.ok) {
                render(data);
            } else if (status) {
                status.textContent = data?.message ?? 'The totals could not be worked out just now. Saving still works; the server will work them out again.';
            }
        } catch (error) {
            if (error.name !== 'AbortError' && status) status.textContent = 'The totals could not be worked out just now. Saving still works; the server will work them out again.';
        }
    };

    const schedule = () => { clearTimeout(timer); timer = setTimeout(run, 350); };
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    run();
};

document.querySelectorAll('form[data-sx-editor]').forEach(wireEditor);
