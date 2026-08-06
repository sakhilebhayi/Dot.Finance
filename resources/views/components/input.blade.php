@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-gray-300 dark:border-gray-300 bg-white dark:bg-white text-gray-900 dark:text-gray-900 focus:border-[#e8af39] dark:focus:border-[#e8af39] focus:ring-[#e8af39] dark:focus:ring-[#e8af39] rounded-md shadow-sm']) !!}>
