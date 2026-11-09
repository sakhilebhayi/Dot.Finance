import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/*
 * Utilities only. The look of Dot.Finance lives in resources/css (tokens as custom properties, then the fx-*
 * component classes); Tailwind is kept for layout helpers and for the stock Jetstream/Livewire views, which are
 * written in utility classes. Colours therefore resolve to the design tokens, so a stock `text-gray-600` or
 * `bg-white` follows the active theme (light, night) instead of its own palette, and `dark:` variants are
 * switched off on purpose: the tokens already flip.
 *
 * @type {import('tailwindcss').Config}
 */
const token = (name) => `rgb(var(--${name}-rgb) / <alpha-value>)`;

export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    darkMode: ['selector', '.fx-never-dark'],

    corePlugins: { preflight: false },

    theme: {
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            inherit: 'inherit',
            // Design tokens by name.
            paper: token('paper'),
            sheet: token('sheet'),
            sunk: token('sunk'),
            ink: token('ink'),
            'ink-2': token('ink-2'),
            'ink-3': token('ink-3'),
            rule: token('rule'),
            'rule-strong': token('rule-strong'),
            tick: token('tick'),
            'tick-bg': token('tick-bg'),
            pencil: token('pencil'),
            'pencil-bg': token('pencil-bg'),
            // Stock palette names, remapped so Jetstream's own markup follows the tokens.
            white: token('sheet'),
            black: token('ink'),
            gray: {
                50: token('paper'),
                100: token('sunk'),
                200: token('rule'),
                300: token('rule-strong'),
                400: token('ink-3'),
                500: token('ink-3'),
                600: token('ink-2'),
                700: token('ink-2'),
                800: token('ink'),
                900: token('ink'),
            },
            red: {
                50: token('pencil-bg'),
                100: token('pencil-bg'),
                200: token('pencil'),
                300: token('pencil'),
                400: token('pencil'),
                500: token('pencil'),
                600: token('pencil'),
                700: token('pencil'),
                800: token('pencil'),
                900: token('pencil'),
            },
            green: {
                50: token('tick-bg'),
                100: token('tick-bg'),
                200: token('tick'),
                300: token('tick'),
                400: token('tick'),
                500: token('tick'),
                600: token('tick'),
                700: token('tick'),
                800: token('tick'),
                900: token('tick'),
            },
            indigo: {
                50: token('sunk'),
                100: token('sunk'),
                200: token('rule-strong'),
                300: token('rule-strong'),
                400: token('ink-2'),
                500: token('ink'),
                600: token('ink'),
                700: token('ink'),
                800: token('ink'),
                900: token('ink'),
            },
        },
        extend: {
            fontFamily: {
                sans: ['var(--font-text)'],
                display: ['var(--font-display)'],
                mono: ['var(--font-figure)'],
            },
        },
    },

    plugins: [forms({ strategy: 'class' }), typography],
};
