import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';

function progressColor(): string {
    const fallback = '#6d28d9';

    if (typeof document === 'undefined') {
        return fallback;
    }

    const token = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();

    return token === '' ? fallback : token;
}

void createInertiaApp({
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

        if (el.dataset.serverRendered === 'true') {
            hydrateRoot(el, <App {...props} />);
        } else {
            createRoot(el).render(<App {...props} />);
        }
    },

    progress: {
        color: progressColor(),
    },
});
