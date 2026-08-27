import { configureEcho } from '@laravel/echo-react';

/*
 * Side-effect module: imported only by Echo consumers so Vite keeps
 * laravel-echo / pusher-js out of the app entry chunk (auth, portal, legal).
 * Keys come from VITE_REVERB_* (.env).
 */
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
