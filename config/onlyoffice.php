<?php

return [
    'public_url' => env('ONLYOFFICE_PUBLIC_URL', '/onlyoffice'),
    'internal_url' => env('ONLYOFFICE_INTERNAL_URL', 'http://onlyoffice'),
    'storage_url' => env('ONLYOFFICE_STORAGE_URL', 'http://webserver'),
    'jwt_secret' => env('ONLYOFFICE_JWT_SECRET', ''),
    'session_hours' => 12,
    'max_file_size' => 100 * 1024 * 1024,
];
