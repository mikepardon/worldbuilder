<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom Domain Target
    |--------------------------------------------------------------------------
    |
    | The public IPv4 address customers point their domain's A record at. Shown
    | in the world's custom-domain setup and used to verify that a domain is
    | correctly pointed at us before it's marked as connected.
    |
    */

    'ip' => env('CUSTOM_DOMAIN_IP', ''),

];
