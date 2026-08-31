import { describe, expect, it } from 'vite-plus/test';
import { errorPageHomeRoute } from '@/lib/error-page-home-route';

describe('errorPageHomeRoute', () => {
    it('sends authenticated users to the dashboard', () => {
        expect(errorPageHomeRoute(true).url).toBe('/');
    });

    it('sends guests to login', () => {
        expect(errorPageHomeRoute(false).url).toBe('/login');
    });
});
