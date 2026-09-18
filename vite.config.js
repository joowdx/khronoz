import { fileURLToPath, URL } from 'node:url';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { defineConfig } from 'vite';
import inertia from '@inertiajs/vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ isSsrBuild }) => ({
    // Without noExternal, Vite externalises react, react-dom/server,
    // @inertiajs/react and every Radix package out of the SSR bundle as bare
    // imports. The build still succeeds; the container dies at runtime with
    // ERR_MODULE_NOT_FOUND while /up stays green. With it, the only bare
    // specifiers left are Node builtins, which is what lets the production image
    // ship the node binary with no node_modules beside it.
    ssr: isSsrBuild ? { noExternal: true } : undefined,
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
                bunny('Plus Jakarta Sans', {
                    weights: [400, 500, 600, 700, 800],
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
}));
