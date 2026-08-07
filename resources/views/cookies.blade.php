<x-guest-layout>
    <div class="pt-4 bg-[var(--paper)] min-h-screen">
        <div class="flex flex-col items-center pt-6 sm:pt-10 px-4 pb-16">
            <div>
                <x-authentication-card-logo />
            </div>

            <div class="w-full sm:max-w-2xl mt-6">
                <p class="font-mono text-[11px] tracking-[0.18em] uppercase text-[var(--ember)] mb-2">Legal</p>
                <h1 class="font-display font-semibold text-2xl text-[var(--ink)] mb-4">Cookie Policy</h1>

                <div class="p-6 sm:p-8 bg-[var(--paper-deep)] border border-[var(--line)] shadow-sm overflow-hidden sm:rounded-2xl prose prose-headings:font-display prose-a:text-[var(--ember)]">
                    {!! $cookies !!}
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
