import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

/**
 * Frontend unit tests.
 *
 * Deliberately separate from vite.config.ts: the app build pulls in the Laravel,
 * Inertia, Tailwind, and Wayfinder plugins, none of which should run for tests
 * (Wayfinder in particular shells out to artisan).
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
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
            // Ratchet: set at the suite's measured line % after shared-trunk tests.
            // Raise deliberately when coverage improves; never lower without a task.
            thresholds: {
                lines: 36,
            },
        },
    },
});
