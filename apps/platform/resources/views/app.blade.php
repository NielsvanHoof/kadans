<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Matches --background in resources/css/app.css so the mobile toolbar blends into the page. --}}
        <meta name="theme-color" content="#F8FAFC" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0F141A" media="(prefers-color-scheme: dark)">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script @if (Vite::cspNonce()) nonce="{{ Vite::cspNonce() }}" @endif>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style @if (Vite::cspNonce()) nonce="{{ Vite::cspNonce() }}" @endif>
            html {
                background-color: oklch(0.9842 0.0034 247.9);
            }

            html.dark {
                background-color: oklch(0.1889 0.0145 253.2);
            }
        </style>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
