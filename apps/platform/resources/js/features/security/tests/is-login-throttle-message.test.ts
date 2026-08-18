import { describe, expect, it } from 'vitest';
import { isLoginThrottleMessage } from '@/features/security/is-login-throttle-message';

describe('isLoginThrottleMessage', () => {
    it.each([
        ['Too many login attempts. Please try again in 60 seconds.'],
        ['Te veel inlogpogingen.'],
        ['Please try again in 5 seconds.'],
        ['Probeer het opnieuw over 5 seconden.'],
        ['TOO MANY ATTEMPTS'],
    ])('flags a Fortify throttle message: %s', (message) => {
        expect(isLoginThrottleMessage(message)).toBe(true);
    });

    it.each([
        ['These credentials do not match our records.'],
        ['The email field is required.'],
        [''],
    ])('does not flag a non-throttle message: %s', (message) => {
        expect(isLoginThrottleMessage(message)).toBe(false);
    });
});
