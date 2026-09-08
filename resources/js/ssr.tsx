import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { renderToString } from 'react-dom/server';

// Only routes that opt in reach this server; see App\Providers\AppServiceProvider
// ::configureInertia(). The port stays in the same 43xxx block as everything
// else so it cannot collide with another project's SSR server.
createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,

            // No `title` callback: Laravel Head resolves the title server
            // side and Inertia adopts that element.
            serverHead: true,

            setup: ({ App, props }) => <App {...props} />,

            resolve: (name) =>
                resolvePageComponent<ResolvedComponent>(
                    `./pages/${name}.tsx`,
                    import.meta.glob<ResolvedComponent>('./pages/**/*.tsx'),
                ),
        }),
    Number(process.env.INERTIA_SSR_PORT ?? 43714),
);
