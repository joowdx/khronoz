import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';

void createInertiaApp({
    // Laravel Head resolves the document head server side and shares it as a
    // `head` prop; Inertia adopts those elements and keeps them in sync on
    // later visits, so no client-side <Head> component is needed. Laravel Head
    // owns the title too, so there is deliberately no `title` callback here:
    // one would append a second, double-suffixed <title> to every page.
    serverHead: true,

    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./pages/**/*.tsx'),
        ),

    setup({ el, App, props }) {
        if (!el) {
            return;
        }

        // The root element carries this flag when Inertia SSR produced the
        // markup, in which case React must hydrate rather than re-render.
        if (el.dataset.serverRendered === 'true') {
            hydrateRoot(el, <App {...props} />);
        } else {
            createRoot(el).render(<App {...props} />);
        }
    },

    progress: {
        color: '#4f46e5',
    },
});
