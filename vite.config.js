import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        // Fonts and the stylesheet are served from this origin only (CSP default-src 'self'): never inline an
        // asset as a data: URI, because font-src and style-src are narrower than img-src.
        assetsInlineLimit: 0,
    },
});
