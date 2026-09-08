import { fileURLToPath, URL } from 'node:url';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { defineConfig } from 'vite';
import inertia from '@inertiajs/vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
            fonts: [
                bunny('Public Sans', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
        react(),
        // Serves /__inertia_ssr from the dev server, so `artisan dev` renders
        // server side through live modules instead of a stale bundle.
        inertia(),
        // Generates typed resources/js/{actions,routes} wrappers from the
        // Laravel route list; enforce: 'pre' makes its position here safe.
        wayfinder({ formVariants: true }),
    ],
    server: {
        port: Number(process.env.VITE_PORT ?? 43173),
        strictPort: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
