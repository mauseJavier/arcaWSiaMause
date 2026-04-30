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

    /*
    |--------------------------------------------------------------------------
    | Padrones - WSN oficiales por tipo de operación
    |--------------------------------------------------------------------------
    |
    | El WSN (Web Service Name) que se envía en el TRA debe coincidir
    | exactamente con el servicio al que está autorizado el certificado en ARCA.
    | Un valor incorrecto produce: "Computador no autorizado a acceder al servicio".
    |
    | Catálogo oficial: https://www.afip.gob.ar/ws/documentacion/catalogo.asp
    |
    */
    'padron' => [
        'services' => [
            // Consulta jurídica por CUIT  → endpoint padron/v1/persona
            // ws_sr_padron_a4 = PersonaServiceA4 (personas jurídicas por CUIT)
            'cuit' => env('ARCA_PADRON_SERVICE_CUIT', 'ws_sr_padron_a4'),

            // Consulta persona física por CUIL → endpoint PersonaServiceA13
            'cuil' => env('ARCA_PADRON_SERVICE_CUIL', 'ws_sr_padron_a13'),

            // Consulta por DNI → endpoint personaServiceA13 (getIdPersonaListByDocumento)
            // Usa el mismo WSN que CUIL; opera con getIdPersonaListByDocumento → getPersona.
            'dni' => env('ARCA_PADRON_SERVICE_DNI', 'ws_sr_padron_a13'),
        ],
    ],
];
