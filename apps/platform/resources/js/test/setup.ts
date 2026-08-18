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

    /*
     * jsdom implements neither ResizeObserver nor layout, so any component
     * that sizes itself from the DOM measures 0x0 and renders nothing —
     * recharts' ResponsiveContainer among them.
     *
     * The stub has to be a real class (the caller uses `new`, and an arrow
     * function is not a constructor) and it has to actually invoke its
     * callback: an observer that never reports a size is indistinguishable
     * from no observer at all, which is what leaves charts blank in tests.
     */
    const OBSERVED_SIZE = { width: 640, height: 320 } as const;

    if (!('ResizeObserver' in window)) {
        Object.defineProperty(window, 'ResizeObserver', {
            writable: true,
            value: class {
                private readonly callback: ResizeObserverCallback;

                constructor(callback: ResizeObserverCallback) {
                    this.callback = callback;
                }

                observe(target: Element) {
                    this.callback(
                        [
                            {
                                target,
                                contentRect: {
                                    ...OBSERVED_SIZE,
                                    top: 0,
                                    left: 0,
                                    bottom: OBSERVED_SIZE.height,
                                    right: OBSERVED_SIZE.width,
                                    x: 0,
                                    y: 0,
                                    toJSON: () => ({}),
                                },
                            } as ResizeObserverEntry,
                        ],
                        this as unknown as ResizeObserver,
                    );
                }

                unobserve() {}
                disconnect() {}
            },
        });
    }
}
