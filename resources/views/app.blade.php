<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
    <head>
        <meta charset="utf-8">

        {{-- Laravel Head renders the resolved title, meta, Open Graph and
             schema tags. Inertia adopts them and keeps them in sync. --}}
        @head

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])

        <x-inertia::head />
    </head>
    <body class="bg-white text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <x-inertia::app />
    </body>
</html>
