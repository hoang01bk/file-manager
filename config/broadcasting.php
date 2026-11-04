<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'reverb'),
    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', 'file-manager'),
            'secret' => env('REVERB_APP_SECRET', hash('sha256', env('APP_KEY', '').':office-reverb')),
            'app_id' => env('REVERB_APP_ID', 'file-manager'),
            'options' => [
                'host' => env('REVERB_HOST', 'reverb'), 'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => 'http', 'useTLS' => false,
            ],
            'client_options' => ['timeout' => 3, 'connect_timeout' => 1],
        ],
        'null' => ['driver' => 'null'],
        'log' => ['driver' => 'log'],
    ],
];
