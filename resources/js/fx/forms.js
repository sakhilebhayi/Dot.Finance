/*
 * Small form behaviours, all opt-in by data attribute.
 *   data-fx-autosubmit        a <select> that submits its form when changed (a <noscript> button is the fallback).
 *   data-fx-type-filter       a <select> of categories that only shows categories of the chosen transaction type.
 */
document.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-fx-autosubmit]');
    if (select && select.form) select.form.submit();
});

const wireTypeFilter = (category) => {
    const type = document.getElementById(category.dataset.fxTypeFilter);
    if (!type) return;

    const sync = () => {
        Array.from(category.options).forEach((option) => {
            if (!option.dataset.type) return;
            const match = option.dataset.type === type.value;
            option.hidden = !match;
            option.disabled = !match;
            if (!match && option.selected) category.value = '';
        });
    };

    type.addEventListener('change', sync);
    sync();
};

document.querySelectorAll('[data-fx-type-filter]').forEach(wireTypeFilter);
