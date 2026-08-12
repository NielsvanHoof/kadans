import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

// Skipped under `@vitest-environment node`, which SSR tests use to assert that
// a page renders with no browser globals at all.
if (typeof window !== 'undefined') {
    // jsdom does not implement matchMedia; SortableList and appearance hooks need it.
    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: vi.fn().mockImplementation((query: string) => ({
            matches: false,
            media: query,
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })),
    });
}
