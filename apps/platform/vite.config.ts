import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    /*
     * The empty prefix loads unprefixed variables too. Vite only exposes VITE_*
     * to the bundle, but this config runs in Node, where the Sentry upload
     * credentials have to be readable — they must never reach the browser.
     */
    const env = loadEnv(mode, process.cwd(), '');

    /*
     * Uploading is opt-in per environment: locally none of these are set, so the
     * plugin drops out rather than failing the build on a missing token.
     */
    const uploadsSourceMaps = Boolean(
        env.SENTRY_AUTH_TOKEN && env.SENTRY_ORG && env.SENTRY_PROJECT,
    );

    /*
     * Symbolication matches maps to events by release, so an upload under a
     * different (or auto-detected) release than the one the bundle reports at
     * runtime resolves nothing. Fail the build instead of shipping a Sentry
     * project full of minified stack traces that look fine until you need one.
     */
    if (uploadsSourceMaps && !env.VITE_SENTRY_RELEASE) {
        throw new Error(
            'VITE_SENTRY_RELEASE must be set when uploading source maps, so the uploaded maps match the release the bundle reports.',
        );
    }

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
                fonts: [
                    // Brand face from qompose.nl — Poppins carries body and display.
                    bunny('Poppins', {
                        weights: [400, 500, 600, 700],
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
            react(),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
            // Must stay last: it reads the finished bundle and its source maps.
            ...(uploadsSourceMaps
                ? [
                      sentryVitePlugin({
                          org: env.SENTRY_ORG,
                          project: env.SENTRY_PROJECT,
                          authToken: env.SENTRY_AUTH_TOKEN,
                          release: { name: env.VITE_SENTRY_RELEASE },
                          sourcemaps: {
                              /*
                               * Deleted once uploaded. public/build is served
                               * publicly, and a .map there hands the whole
                               * frontend source to anyone who asks for it.
                               */
                              filesToDeleteAfterUpload: [
                                  'public/build/**/*.map',
                              ],
                          },
                          telemetry: false,
                      }),
                  ]
                : []),
        ],
        build: {
            // Only worth generating when something consumes them; see above for
            // why they never survive the build.
            sourcemap: uploadsSourceMaps,
        },
    };
});
