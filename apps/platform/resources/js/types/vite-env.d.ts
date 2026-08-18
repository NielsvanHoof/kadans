/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
    /*
     * Browser error reporting. Optional throughout: an empty DSN leaves the
     * SDK uninitialised, which is the intended state locally and in CI.
     * config/services.php reads the same VITE_SENTRY_DSN server-side so the CSP
     * can allow the ingest origin.
     */
    readonly VITE_SENTRY_DSN?: string;
    readonly VITE_SENTRY_ENVIRONMENT?: string;
    readonly VITE_SENTRY_RELEASE?: string;
    readonly VITE_SENTRY_TRACES_SAMPLE_RATE?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
