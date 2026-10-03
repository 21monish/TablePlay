import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const reverbKey = document.querySelector('meta[name="tableplay-reverb-key"]')?.content
    ?? import.meta.env.VITE_REVERB_APP_KEY;
const reverbPort = Number(document.querySelector('meta[name="tableplay-reverb-port"]')?.content
    ?? import.meta.env.VITE_REVERB_PORT
    ?? 8080);

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: reverbKey,
    wsHost: window.location.hostname,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
});
