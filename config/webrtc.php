<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ICE servers
    |--------------------------------------------------------------------------
    |
    | Passed to the browser's RTCPeerConnection for the battle-room voice/video
    | mesh. A public STUN server is always included; a TURN server (needed for
    | strict/symmetric NATs) is added when WEBRTC_TURN_URL is set. TURN
    | credentials are delivered to the client (browsers need them to connect) —
    | prefer short-lived credentials from your TURN provider in production.
    |
    */
    'ice_servers' => array_values(array_filter([
        ['urls' => env('WEBRTC_STUN_URL', 'stun:stun.l.google.com:19302')],

        filled(env('WEBRTC_TURN_URL')) ? array_filter([
            'urls' => env('WEBRTC_TURN_URL'),
            'username' => env('WEBRTC_TURN_USERNAME'),
            'credential' => env('WEBRTC_TURN_CREDENTIAL'),
        ], fn ($value) => $value !== null) : null,
    ])),
];
