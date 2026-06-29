import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        // Fonts werden selbst gehostet (@fontsource-variable/* in resources/css/app.css).
        // KEINE Remote-Font-Auflösung hier – sonst lädt `vite build` zur Build-Zeit
        // aus dem Netz und schlägt offline/in der CI fehl (fetch failed / ECONNRESET).
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
