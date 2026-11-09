/*
 * Small form behaviours, all opt-in by data attribute.
 *   data-fx-autosubmit        a <select> that submits its form when changed (a <noscript> button is the fallback).
 *   data-fx-type-filter       a <select> of categories that only shows categories of the chosen transaction type.
 *   data-fx-collapse-mobile   a <details> that starts closed on a phone (open when data-active="1").
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

// details[data-fx-collapse-mobile]: closed on a phone unless a filter is active (data-active). Without scripting it stays open.
if (window.matchMedia('(max-width: 719.98px)').matches) {
    document.querySelectorAll('details[data-fx-collapse-mobile]').forEach((details) => {
        if (details.dataset.active !== '1') details.open = false;
    });
}

// form[data-fx-custom-dates]: typing a From/To date means "Custom dates", so the Period choice follows the dates.
document.querySelectorAll('form[data-fx-custom-dates]').forEach((form) => {
    const preset = form.elements.preset;
    if (!preset) return;
    ['from', 'to'].forEach((name) => {
        form.elements[name]?.addEventListener('change', () => {
            if (Array.from(preset.options).some((option) => option.value === 'custom')) preset.value = 'custom';
        });
    });
});
