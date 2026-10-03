import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const reverbKey = document.querySelector('meta[name="tableplay-reverb-key"]')?.content
    ?? import.meta.env.VITE_REVERB_APP_KEY;
const reverbPort = Number(document.querySelector('meta[name="tableplay-reverb-port"]')?.content
    ?? import.meta.env.VITE_REVERB_PORT
    ?? 8080);
const reverbHost = document.querySelector('meta[name="tableplay-reverb-host"]')?.content
    || window.location.hostname;
const reverbScheme = document.querySelector('meta[name="tableplay-reverb-scheme"]')?.content
    || window.location.protocol.replace(':', '');
const secureReverb = reverbScheme === 'https' || reverbScheme === 'wss';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: reverbKey,
    wsHost: reverbHost,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: secureReverb,
    enabledTransports: ['ws', 'wss'],
});
