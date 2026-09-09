import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';

/**
 * Read the `--primary` token instead of hardcoding the light palette's hex
 * value, so the progress bar tracks whichever accent and mode are active
 * rather than always painting one of them. getPropertyValue never throws, but
 * can come back empty — before the stylesheet has loaded, or with no
 * `document` at all — so fall back to the shipped default, violet at light.
 */
function progressColor(): string {
    const fallback = '#6d28d9';

    if (typeof document === 'undefined') {
        return fallback;
    }

    const token = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();

    return token === '' ? fallback : token;
}

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
        color: progressColor(),
    },
});
