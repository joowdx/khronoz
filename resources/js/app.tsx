import { createInertiaApp, router, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { toast } from 'sonner';
import { Toaster } from '@/components/ui/sonner';

const removeFlashListener = router.on('flash', ({ detail: { flash } }) => {
    if (typeof flash.success === 'string') toast.success(flash.success);
    if (typeof flash.error === 'string') toast.error(flash.error);
});

import.meta.hot?.dispose(removeFlashListener);

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

        const application = (
            <>
                <App {...props} />
                <Toaster position="bottom-right" />
            </>
        );

        if (el.dataset.serverRendered === 'true') {
            hydrateRoot(el, application);
        } else {
            createRoot(el).render(application);
        }
    },

    progress: {
        color: progressColor(),
    },
});
