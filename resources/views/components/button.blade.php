<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-[#e8af39] dark:bg-[#e8af39] border border-transparent rounded-md font-semibold text-xs text-[#241a10] dark:text-[#241a10] uppercase tracking-widest hover:bg-[#c98a1f] dark:hover:bg-[#c98a1f] focus:bg-[#c98a1f] dark:focus:bg-[#c98a1f] active:bg-[#c98a1f] dark:active:bg-[#c98a1f] focus:outline-none focus:ring-2 focus:ring-[#e8af39] focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
