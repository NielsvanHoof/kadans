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
            // Ratchet: set just under the suite's measured line %, so a real
            // regression trips it but ordinary churn does not.
            // Raise deliberately when coverage improves; never lower without a task.
            //
            // 41 -> 49 after features/clients went from zero tests to full line
            // coverage (measured 49.86%). The gap left is deliberate headroom, not
            // slack: pinning the exact figure makes every unrelated refactor a
            // threshold failure, and a gate people routinely edit downward to get
            // green stops being a gate.
            thresholds: {
                lines: 49,
            },
        },
    },
});
