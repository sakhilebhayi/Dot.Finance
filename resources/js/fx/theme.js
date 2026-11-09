/*
 * Theme: follows the system until the person chooses. The choice (light or dark) is kept in localStorage and
 * mirrored to data-theme on <html>; choosing "match system" removes both. The first paint is handled by a tiny
 * nonce'd inline script in the layout head, so there is no flash of the wrong theme.
 */
const KEY = 'dot-finance-theme';
const root = document.documentElement;

const read = () => {
    try {
        const stored = window.localStorage.getItem(KEY);

        return stored === 'light' || stored === 'dark' ? stored : null;
    } catch {
        return null;
    }
};

const write = (value) => {
    try {
        if (value) window.localStorage.setItem(KEY, value);
        else window.localStorage.removeItem(KEY);
    } catch {
        // Storage can be blocked; the theme then lasts for this page only.
    }
};

const label = (mode) => ({ light: 'Light', dark: 'Night', null: 'Match system' }[mode] ?? 'Match system');

const paint = (mode) => {
    if (mode) root.setAttribute('data-theme', mode);
    else root.removeAttribute('data-theme');

    document.querySelectorAll('[data-fx-theme]').forEach((button) => {
        button.setAttribute('aria-label', `Theme: ${label(mode)}. Press to change.`);
        button.setAttribute('title', `Theme: ${label(mode)}`);
        button.querySelectorAll('[data-theme-icon]').forEach((icon) => {
            icon.hidden = icon.dataset.themeIcon !== String(mode);
        });
    });
};

let mode = read();
paint(mode);

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fx-theme]');
    if (!button) return;

    mode = mode === null ? 'light' : mode === 'light' ? 'dark' : null;
    write(mode);
    paint(mode);
});
