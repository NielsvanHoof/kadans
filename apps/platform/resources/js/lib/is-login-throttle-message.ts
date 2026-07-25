/**
 * Detect Fortify/Laravel auth throttle messages on login field errors.
 */
export function isLoginThrottleMessage(message: string): boolean {
    const normalized = message.toLowerCase();

    return (
        normalized.includes('too many') ||
        normalized.includes('te veel') ||
        normalized.includes('try again in') ||
        normalized.includes('probeer het opnieuw')
    );
}
