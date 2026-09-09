<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased" data-mode="light" data-accent="violet">
    <head>
        <meta charset="utf-8">

        {{-- Stamp the resolved colour mode on <html> before the first paint,
             so a dark-mode user never sees a white flash. Runs synchronously
             and ahead of the stylesheet; use-appearance.ts owns the same
             `appearance` key once React is running. --}}
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('appearance');
                    var mode = stored === 'light' || stored === 'dark'
                        ? stored
                        : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                    document.documentElement.dataset.mode = mode;
                } catch (e) {
                    /* Private mode can throw on localStorage; the markup's own
                       data-mode="light" default already stands. */
                }
            })();
        </script>

        {{-- Laravel Head renders the resolved title, meta, Open Graph and
             schema tags. Inertia adopts them and keeps them in sync. --}}
        @head

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])

        <x-inertia::head />
    </head>
    <body class="min-h-svh bg-background text-foreground">
        <x-inertia::app />
    </body>
</html>
