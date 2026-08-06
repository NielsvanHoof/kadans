import { describe, expect, it } from 'vitest';
import { errorPageHomeRoute } from '@/pages/error-home-route';

describe('errorPageHomeRoute', () => {
    it('sends authenticated users to the dashboard', () => {
        expect(errorPageHomeRoute(true).url).toBe('/');
    });

    it('sends guests to login', () => {
        expect(errorPageHomeRoute(false).url).toBe('/login');
    });
});
