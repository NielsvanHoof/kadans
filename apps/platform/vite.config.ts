import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                // Body face — dense forms, tables, and everything under 20px.
                bunny('Inter', {
                    weights: [400, 500, 600],
                    optimizedFallbacks: false,
                }),
                // Display face — headings and the wordmark only, hence 600/700.
                bunny('Schibsted Grotesk', {
                    weights: [600, 700],
                    optimizedFallbacks: false,
                }),
                // Data/meta face for timestamps, counters, and dense document metadata.
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
