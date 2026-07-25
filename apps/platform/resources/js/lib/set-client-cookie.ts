/**
 * Set a client cookie that Laravel middleware can read (locale, appearance).
 * Cookie Store API is not universally supported, so document.cookie is intentional.
 */
export function setClientCookie(name: string, value: string, days = 365): void {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    // biome-ignore lint/suspicious/noDocumentCookie: must sync with Laravel middleware
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}
