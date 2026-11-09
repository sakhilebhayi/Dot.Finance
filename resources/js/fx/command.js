/*
 * Command bar: Ctrl/Cmd+K (or the button) opens a dialog listing quick actions and every place in the app.
 * Typing filters it; arrow keys move; Enter follows the highlighted link. The list is rendered by the server
 * (only destinations that exist and that this person may use), so nothing here can invent a route.
 */
const dialog = document.getElementById('fx-command');

if (dialog && typeof dialog.showModal === 'function') {
    const input = dialog.querySelector('[data-command-input]');
    const options = Array.from(dialog.querySelectorAll('[data-command-option]'));
    const groups = Array.from(dialog.querySelectorAll('[data-command-group]'));
    const empty = dialog.querySelector('[data-command-empty]');
    const searchLink = dialog.querySelector('[data-command-search]');
    const searchTerm = dialog.querySelector('[data-command-search-term]');
    let active = -1;

    const visible = () => options.filter((option) => !option.closest('li').hidden);

    const highlight = (index) => {
        const list = visible();
        list.forEach((option) => option.removeAttribute('data-active'));
        if (!list.length) {
            active = -1;
            return;
        }
        active = (index + list.length) % list.length;
        list[active].setAttribute('data-active', 'true');
        list[active].scrollIntoView({ block: 'nearest' });
    };

    const filter = () => {
        const term = input.value.trim().toLowerCase();

        options.forEach((option) => {
            const hit = term === '' || (option.dataset.label ?? option.textContent).toLowerCase().includes(term);
            option.closest('li').hidden = !hit;
        });

        groups.forEach((group) => {
            const items = Array.from(group.querySelectorAll('[data-command-option]'));
            group.hidden = items.every((option) => option.closest('li').hidden);
        });

        if (searchLink) {
            const hasTerm = term !== '';
            searchLink.closest('li').hidden = !hasTerm;
            if (hasTerm) {
                const url = new URL(searchLink.dataset.base, window.location.origin);
                url.searchParams.set('search', input.value.trim());
                searchLink.setAttribute('href', url.pathname + url.search);
                searchTerm.textContent = input.value.trim();
            }
        }

        const any = visible().length > 0 || (searchLink && !searchLink.closest('li').hidden);
        if (empty) empty.hidden = any;
        highlight(0);
    };

    const open = () => {
        if (!dialog.open) dialog.showModal();
        input.value = '';
        filter();
        input.focus();
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-fx-command-open]')) {
            event.preventDefault();
            open();
        }
        if (event.target === dialog) dialog.close();
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            if (dialog.open) dialog.close();
            else open();
        }
    });

    input.addEventListener('input', filter);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight(active + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight(active - 1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const list = visible();
            const target = list[active] ?? (searchLink && !searchLink.closest('li').hidden ? searchLink : null);
            if (target) target.click();
        }
    });

    dialog.addEventListener('click', (event) => {
        if (event.target.closest('a[href]')) dialog.close();
    });
}
