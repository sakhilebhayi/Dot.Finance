/*
 * The header search (the shared dot-ui pill). Enter and the arrow submit the GET form to the transactions list, exactly as before; typing also
 * asks the server for suggestions in the OPEN ledger (BooksSearch, JSON) and dot-ui's results controller prints them and drives the keyboard.
 * Nothing is built from the response with innerHTML: the controller writes text nodes only (strict CSP).
 *
 * Who owns which key: Ctrl K / Cmd K is the quick-actions bar (command.js), so the pill's hint is "/" and the pill binds no chord of its own.
 * "/" focuses the pill (on a phone it opens dot-ui's search sheet). Keys are left alone while someone is typing.
 */
const start = () => {
    const input = document.getElementById('fx-search');
    const root = input?.closest('.dsr');
    const url = input?.form?.dataset.fxSearch;
    const results = window.DotSearchResults;

    if (!input || !root || !url || !results) return;

    results.attach(root, {
        debounce: 250,
        minChars: 2,
        empty: 'Nothing in these books matches. Enter searches the transactions.',
        search: async (query, { signal }) => {
            const response = await fetch(`${url}?${new URLSearchParams({ q: query })}`, {
                signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error(`search ${response.status}`);

            const body = await response.json();

            return (body.groups ?? []).map((group) => ({
                head: group.head || null,
                rows: group.rows.map((row) => ({ href: row.href, label: row.label, code: row.code, tag: row.tag, action: row.action === true })),
            }));
        },
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
        if (event.target.closest?.('input:not([readonly]), textarea, select, [contenteditable]')) return;

        event.preventDefault();
        if (input.getClientRects().length === 0) document.querySelector('[data-dh-search-toggle]')?.click();
        else input.focus();
    });
};

// dot-ui's scripts are deferred like this one and run after it (document order), so wait for DOMContentLoaded, which fires after all of them.
if (document.readyState === 'complete') start();
else document.addEventListener('DOMContentLoaded', start);
