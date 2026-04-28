<?php

declare(strict_types=1);

return [
    'mode' => env('ARCA_MODE', 'homologation'),

    'wsaa' => [
        'homologation_url' => env('ARCA_WSAA_HOMO_URL', 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'),
        'production_url' => env('ARCA_WSAA_PROD_URL', 'https://wsaa.afip.gov.ar/ws/services/LoginCms'),
    ],

    // Templates multiempresa. Deben incluir "%s" para interpolar el CUIT emisor.
    'cert_path_pattern' => env('ARCA_CERT_PATH_PATTERN', storage_path('app/public/%s/cert.crt')),
    'key_path_pattern' => env('ARCA_KEY_PATH_PATTERN', storage_path('app/public/%s/key.key')),

    // Compatibilidad con instalación single-tenant.
    'cert_path' => env('ARCA_CERT_PATH', storage_path('app/arca/arca.crt')),
    'key_path' => env('ARCA_KEY_PATH', storage_path('app/arca/arca.key')),
    'key_passphrase' => env('ARCA_KEY_PASSPHRASE'),
];
