/*
 * Toasts: a status message from the server (session 'status') is shown in a polite live region and goes away
 * by itself after eight seconds, or sooner when dismissed. Nothing here is the only way to learn something:
 * the same message is in the page as text for anyone who misses it.
 */
document.querySelectorAll('[data-fx-toast]').forEach((toast) => {
    const remove = () => toast.remove();
    toast.querySelector('[data-fx-toast-close]')?.addEventListener('click', remove);
    window.setTimeout(remove, 8000);
});
