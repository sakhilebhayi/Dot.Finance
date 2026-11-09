/*
 * Manual journal form: shows running totals and the difference while typing. A convenience only; the server is the
 * one that checks debits equal credits. Amounts are summed as whole cents by reading the digits (no floating point).
 */
const toCents = (text) => {
    const clean = String(text || '').replace(/[\s,]/g, '');
    const match = /^(\d*)(?:\.(\d{0,2}))?\d*$/.exec(clean);
    if (!match || clean === '') return 0;
    return parseInt(match[1] || '0', 10) * 100 + parseInt((match[2] || '').padEnd(2, '0') || '0', 10);
};

const format = (cents) => {
    const whole = Math.floor(cents / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${whole}.${String(cents % 100).padStart(2, '0')}`;
};

document.querySelectorAll('form[data-fx-journal]').forEach((form) => {
    const debit = form.querySelector('[data-fx-total="debit"]');
    const credit = form.querySelector('[data-fx-total="credit"]');
    const note = form.querySelector('[data-fx-balance]');
    if (!debit || !credit || !note) return;

    const sum = (side) => Array.from(form.querySelectorAll(`[data-fx-side="${side}"]`)).reduce((total, input) => total + toCents(input.value), 0);

    const update = () => {
        const d = sum('debit');
        const c = sum('credit');
        debit.textContent = format(d);
        credit.textContent = format(c);
        if (d === 0 && c === 0) note.textContent = '';
        else if (d === c) note.textContent = 'Debits equal credits.';
        else note.textContent = `Out by ${format(Math.abs(d - c))}: ${d > c ? 'credits are short' : 'debits are short'}.`;
    };

    form.addEventListener('input', update);
    update();
});
