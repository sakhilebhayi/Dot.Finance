{{-- The one confirm dialog of the page. Any form with data-fx-confirm="Message" (and optionally data-fx-confirm-label) asks here
     first, in place of window.confirm(); the message and the button label come from that form. --}}
<dialog id="fx-confirm" class="fx-dialog" aria-labelledby="fx-confirm-title">
    <div class="fx-dialog__inner">
        <h2 class="fx-dialog__title" id="fx-confirm-title">Are you sure?</h2>
        <p data-confirm-message></p>
        <div class="fx-dialog__actions">
            <button type="button" class="fx-btn" data-fx-dialog-close>Cancel</button>
            <button type="button" class="fx-btn fx-btn--danger" data-confirm-go>Confirm</button>
        </div>
    </div>
</dialog>
