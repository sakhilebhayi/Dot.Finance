// Command centre: the accounts ledger picks an account into the panel beside it, moves with the arrow keys, and sorts inside each
// run of account rows. Progressive: the page is complete without this file (the panel shows the first account and every row is a
// link). The words come from data-* the server already wrote, so nothing is calculated here.
const ledger = document.querySelector('[data-fxd-ledger]');
const stage = document.querySelector('[data-fxd-stage]');

if (ledger) {
    const wide = window.matchMedia('(min-width: 1024px)');
    const rows = () => Array.from(ledger.querySelectorAll('tr[data-fxd-pick]'));
    const set = (key, value) => {
        const el = stage && stage.querySelector('[data-s="' + key + '"]');
        if (el) el.textContent = value;
    };

    const pick = (row) => {
        rows().forEach((r) => r.removeAttribute('aria-selected'));
        row.setAttribute('aria-selected', 'true');
        if (!stage) return;
        const d = row.dataset;
        set('name', d.name);
        set('type', d.type + (d.inst ? ', ' + d.inst : ''));
        set('cur', d.cur);
        set('open', d.open);
        set('n', d.n);
        set('last', d.last);
        set('bal', d.bal);
        const entries = stage.querySelector('[data-s-href="entries"]');
        if (entries) entries.setAttribute('href', d.entries);
        const edit = stage.querySelector('[data-s-href="edit"]');
        if (edit) {
            if (d.edit) { edit.setAttribute('href', d.edit); edit.hidden = false; } else { edit.hidden = true; }
        }
        const body = stage.querySelector('[data-fxd-settle]');
        if (body) {
            body.classList.remove('is-settling');
            void body.offsetWidth;
            body.classList.add('is-settling');
        }
    };

    ledger.addEventListener('click', (e) => {
        const row = e.target.closest('tr[data-fxd-pick]');
        if (!row || !wide.matches || e.target.closest('a')) return;
        pick(row);
    });

    ledger.addEventListener('keydown', (e) => {
        const row = e.target.closest('tr[data-fxd-pick]');
        if (!row || e.target !== row) return;
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            pick(row);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            const all = rows();
            const next = all[all.indexOf(row) + (e.key === 'ArrowDown' ? 1 : -1)];
            if (next) { e.preventDefault(); next.focus(); }
        }
    });

    ledger.querySelectorAll('button[data-fxd-sort]').forEach((button) => {
        button.addEventListener('click', () => {
            const th = button.closest('th');
            const key = button.dataset.fxdSort;
            const dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
            ledger.querySelectorAll('thead th').forEach((h) => h.removeAttribute('aria-sort'));
            th.setAttribute('aria-sort', dir);
            const tbody = ledger.querySelector('tbody');
            // A run is the account rows between two rule rows, so a currency's cash accounts and its cards sort apart.
            let run = [];
            const flush = () => {
                if (run.length > 1) {
                    const anchor = run[run.length - 1].nextSibling;
                    run.sort((a, b) => {
                        const x = key === 'bal' ? Number(a.dataset.sortBal) : a.dataset.sortName;
                        const y = key === 'bal' ? Number(b.dataset.sortBal) : b.dataset.sortName;
                        const c = x < y ? -1 : x > y ? 1 : 0;
                        return dir === 'ascending' ? c : -c;
                    });
                    run.forEach((r) => tbody.insertBefore(r, anchor));
                }
                run = [];
            };
            Array.from(tbody.children).forEach((tr) => {
                if (tr.matches('[data-fxd-pick]')) run.push(tr); else flush();
            });
            flush();
        });
    });
}
