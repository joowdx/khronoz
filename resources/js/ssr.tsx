import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { renderToString } from 'react-dom/server';
import { Toaster } from '@/components/ui/sonner';

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,

            serverHead: true,

            setup: ({ App, props }) => (
                <>
                    <App {...props} />
                    <Toaster position="bottom-right" />
                </>
            ),

            resolve: (name) =>
                resolvePageComponent<ResolvedComponent>(
                    `./pages/${name}.tsx`,
                    import.meta.glob<ResolvedComponent>('./pages/**/*.tsx'),
                ),
        }),
    Number(process.env.INERTIA_SSR_PORT ?? 43714),
);
