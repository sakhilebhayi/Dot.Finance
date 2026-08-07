<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4 bg-[var(--paper)] grid-paper">
    <div>
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-8 sm:px-10 bg-[var(--paper-deep)] border border-[var(--line)] shadow-sm overflow-hidden sm:rounded-2xl">
        {{ $slot }}
    </div>
</div>
