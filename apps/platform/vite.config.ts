import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import {
    local,
    type LocalVariantDefinition,
} from 'laravel-vite-plugin/fonts';
import { fileURLToPath } from 'node:url';
import { defineConfig, lazyPlugins, loadEnv } from 'vite-plus';

import { frontendLint } from './vite.lint.ts';

const vendoredVariants = (
    slug: string,
    weights: number[],
): LocalVariantDefinition[] =>
    weights.map((weight) => ({
        weight,
        style: 'normal',
        src: [
            `resources/fonts/${slug}-${weight}.woff2`,
            `resources/fonts/${slug}-${weight}.woff`,
        ],
    }));

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

    /*
     * Vitest sets VITEST=true. lazyPlugins still loads plugins for `vp test`,
     * and Wayfinder shells out to artisan — keep the test graph on react()
     * only, same as the old standalone vitest.config.ts.
     */
    const isVitest = process.env.VITEST === 'true';

    return {
        plugins: isVitest
            ? [react()]
            : lazyPlugins(() => [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/app.tsx'],
                      refresh: true,
                      fonts: [
                          // Brand face from qompose.nl — Poppins carries body and display.
                          local('Poppins', {
                              variants: vendoredVariants('poppins', [
                                  400, 500, 600, 700,
                              ]),
                              optimizedFallbacks: false,
                          }),
                          // Data/meta face for timestamps, counters, and dense document metadata.
                          local('IBM Plex Mono', {
                              variants: vendoredVariants('ibm-plex-mono', [
                                  400, 500,
                              ]),
                              optimizedFallbacks: false,
                          }),
                      ],
                  }),
                  inertia({ ssr: false }),
                  react(),
                  // Babel 8 is ESM-only and used here only for the React Compiler.
                  // Vite/rolldown already transpile; there is no babel.config.
                  babel({ presets: [reactCompilerPreset()] }),
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
              ]),
        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },
        server: {
            watch: {
                ignored: [
                    '**/.agents/**',
                    '**/.claude/**',
                    '**/.cursor/**',
                    '**/.junie/**',
                    '**/vendor/**',
                ],
            },
        },
        build: {
            // Only worth generating when something consumes them; see above for
            // why they never survive the build.
            sourcemap: uploadsSourceMaps,
        },
        lint: frontendLint,
        fmt: {
            printWidth: 80,
            tabWidth: 4,
            singleQuote: true,
            semi: true,
            singleAttributePerLine: false,
            htmlWhitespaceSensitivity: 'css',
            // Prettier only ran on resources/. Keep that scope so Oxfmt does
            // not rewrite PHP, lang JSON, or infra.
            ignorePatterns: [
                '*',
                '!resources/',
                '!resources/**',
                'resources/js/components/ui/*',
                'resources/views/mail/*',
                'resources/js/actions/**',
                'resources/js/routes/**',
                'resources/js/wayfinder/**',
                'resources/js/types/generated/**',
                'resources/js/features/**/generated*',
            ],
            sortTailwindcss: {
                functions: ['clsx', 'cn', 'cva'],
                entryPoint: 'resources/css/app.css',
            },
        },
        test: {
            environment: 'jsdom',
            globals: true,
            setupFiles: ['./resources/js/test/setup.ts'],
            include: ['resources/js/**/*.test.{ts,tsx}'],
            restoreMocks: true,
            coverage: {
                provider: 'v8',
                // Include uncovered app sources so the % reflects the real gap.
                include: ['resources/js/**/*.{ts,tsx}'],
                exclude: [
                    // Wayfinder / transformer output — not hand-maintained.
                    'resources/js/actions/**',
                    'resources/js/routes/**',
                    'resources/js/wayfinder/**',
                    'resources/js/types/generated/**',
                    'resources/js/features/**/generated*',
                    // Vendored shadcn primitives — tested selectively, not owned.
                    'resources/js/components/ui/**',
                    // Test harness itself.
                    'resources/js/**/*.test.{ts,tsx}',
                    'resources/js/**/tests/**',
                    'resources/js/test/**',
                ],
                reporter: ['text', 'text-summary'],
                // Ratchet: set just under the suite's measured line %, so a real
                // regression trips it but ordinary churn does not.
                // Raise deliberately when coverage improves; never lower without a task.
                thresholds: {
                    lines: 49,
                },
            },
        },
    };
});
