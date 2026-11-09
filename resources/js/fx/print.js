/*
 * A button marked data-fx-print opens the browser's print dialog (where "Save as PDF" is the PDF export). It is a
 * progressive extra: the report is a plain page that prints the same without it.
 */
document.querySelectorAll('[data-fx-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});
