import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Call only after authentication. Each feature owns and leaves its subscriptions.
export function createEcho() {
    return new Echo({
        broadcaster: 'reverb',
        client: new Pusher(import.meta.env.VITE_REVERB_APP_KEY, {
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            enabledTransports: ['ws', 'wss'],
            cluster: '',
            channelAuthorization: { endpoint: '/broadcasting/auth', transport: 'ajax' },
        }),
    });
}
