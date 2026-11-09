/*
 * Shell: below 1024px the sidebar is a bottom sheet opened from "More". Focus is moved in, kept in, and
 * returned; Escape and the scrim close it. Above 1024px it is simply the sidebar and none of this applies.
 */
const sheet = document.getElementById('app-sidebar');
const scrim = document.querySelector('[data-shell-backdrop]');
const media = window.matchMedia('(max-width: 1023.98px)');
const root = document.documentElement;
const openers = Array.from(document.querySelectorAll('[data-shell-menu]'));
const closers = Array.from(document.querySelectorAll('[data-shell-close]'));

if (sheet && scrim && openers.length) {
    const isOpen = () => sheet.classList.contains('is-open');

    const set = (open, returnFocus) => {
        if (open && !media.matches) return;

        sheet.classList.toggle('is-open', open);
        scrim.classList.toggle('is-open', open);
        root.classList.toggle('is-locked', open);
        openers.forEach((button) => button.setAttribute('aria-expanded', open ? 'true' : 'false'));

        if (open) {
            (closers[0] ?? sheet).focus();
        } else if (returnFocus) {
            openers.find((button) => button.offsetParent !== null)?.focus();
        }
    };

    openers.forEach((button) => button.addEventListener('click', () => set(!isOpen(), true)));
    closers.forEach((button) => button.addEventListener('click', () => set(false, true)));
    scrim.addEventListener('click', () => set(false, true));

    // Following a link inside the sheet is a navigation: nothing to restore.
    sheet.addEventListener('click', (event) => {
        if (event.target.closest('a[href]') && isOpen()) set(false, false);
    });

    document.addEventListener('keydown', (event) => {
        if (!isOpen()) return;

        if (event.key === 'Escape') {
            set(false, true);
            return;
        }

        if (event.key !== 'Tab') return;

        const focusable = Array.from(sheet.querySelectorAll('a[href], button:not([disabled]), summary')).filter((el) => el.offsetParent !== null);
        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const onChange = () => {
        if (!media.matches && isOpen()) set(false, false);
    };

    if (media.addEventListener) media.addEventListener('change', onChange);
    else media.addListener(onChange);

    window.addEventListener('pagehide', () => set(false, false));
}
