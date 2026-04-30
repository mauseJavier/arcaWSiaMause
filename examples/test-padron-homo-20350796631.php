<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Cache\CacheManager;

$companyCuit = '20350796631';
$certBase    = __DIR__ . '/../.docker/laravel-consumer/storage/app/public';

// Este cert fue emitido por "CN=Computadores" (CA de producción de AFIP),
// por lo que debe probarse contra el WSAA de producción (wsaa.afip.gov.ar).
$app = new \Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton('config', fn () => new ConfigRepository([
    'arca' => array_merge(require __DIR__ . '/../config/arca.php', [
        'mode'                => 'production',
        'cert_path_pattern'   => $certBase . '/%s/cert.crt',
        'key_path_pattern'    => $certBase . '/%s/key.key',
    ]),
    'cache'   => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'logging' => ['default' => 'stderr', 'channels' => ['stderr' => ['driver' => 'errorlog']]],
]));
$app->singleton('cache', fn ($a) => new CacheManager($a));
$app->singleton('cache.store', fn ($a) => $a['cache']->driver());
$app->singleton('log', fn ($a) => new \Illuminate\Log\LogManager($a));
Facade::setFacadeApplication($app);

$cfg    = $app['config']->get('arca');
$wsaa   = new \Mause\LaravelArca\Modules\Wsaa($cfg);
$padron = new \Mause\LaravelArca\Modules\WsPadron($wsaa, $cfg);

function sec(string $t): void { echo "\n" . str_repeat('═', 60) . "\n  $t\n" . str_repeat('═', 60) . "\n"; }
function dump(?array $r): void
{
    if ($r === null) { echo "  → NULL\n"; return; }
    if (!empty($r['error'])) { echo "  ✗ ERROR: " . $r['error'] . "\n"; return; }
    echo "  ✓ OK\n";
    printArr($r['data'] ?? $r, '    ');
}
function printArr(mixed $d, string $i = ''): void
{
    if (!is_array($d)) { echo $i . print_r($d, true) . "\n"; return; }
    foreach ($d as $k => $v) {
        if (is_array($v)) { echo "$i$k:\n"; printArr($v, $i . '  '); }
        else { echo "$i$k: " . ($v ?? '(null)') . "\n"; }
    }
}

// 1-3. TAs
foreach (['wsfe', 'ws_sr_constancia_inscripcion', 'ws_sr_padron_a13'] as $wsn) {
    sec("TA — $wsn");
    try {
        $ta = $wsaa->requestTa($companyCuit, $wsn);
        if ($ta) {
            echo "  ✓ token: " . substr($ta['token'], 0, 50) . "…\n";
            echo "  ✓ expires_at: " . $ta['expires_at'] . "\n";
        } else {
            echo "  ✗ No se obtuvo TA\n";
        }
    } catch (\Throwable $e) {
        echo "  ✗ " . $e->getMessage() . "\n";
    }
}

// 4. Consulta CUIT jurídica (AFIP)
// 4. Respuesta raw A4 (CUIT 30678368710)
sec("getPersona RAW — CUIT 30678368710 (PersonaServiceA4)");
try {
    $ta = $wsaa->requestTa($companyCuit, 'ws_sr_constancia_inscripcion');
    if ($ta) {
        $client = new \SoapClient('https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA4?wsdl',
            ['exceptions' => true, 'trace' => true, 'soap_version' => SOAP_1_2]);
        $r = $client->getPersona([
            'token' => $ta['token'], 'sign' => $ta['sign'],
            'cuitRepresentada' => (int) $companyCuit, 'idPersona' => 30678368710,
        ]);
        echo "  ✓ JSON:\n";
        printArr(json_decode(json_encode($r), true), '    ');
    }
} catch (\SoapFault $e) {
    echo "  ✗ SoapFault[" . $e->faultcode . "]: " . $e->getMessage() . "\n";
    if (isset($client)) { echo "  XML: " . $client->__getLastResponse() . "\n"; }
} catch (\Throwable $e) { echo "  ✗ " . get_class($e) . ": " . $e->getMessage() . "\n"; }

// 5. Respuesta raw A13 (CUIL propio)
sec("getPersona RAW — CUIL $companyCuit (PersonaServiceA13)");
try {
    $ta2 = $wsaa->requestTa($companyCuit, 'ws_sr_padron_a13');
    if ($ta2) {
        $client2 = new \SoapClient('https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?wsdl',
            ['exceptions' => true, 'trace' => true, 'soap_version' => SOAP_1_2]);
        $r2 = $client2->getPersona([
            'token' => $ta2['token'], 'sign' => $ta2['sign'],
            'cuitRepresentada' => (int) $companyCuit, 'idPersona' => (int) $companyCuit,
        ]);
        echo "  ✓ JSON:\n";
        printArr(json_decode(json_encode($r2), true), '    ');
    }
} catch (\SoapFault $e) {
    echo "  ✗ SoapFault[" . $e->faultcode . "]: " . $e->getMessage() . "\n";
    if (isset($client2)) { echo "  XML: " . $client2->__getLastResponse() . "\n"; }
} catch (\Throwable $e) { echo "  ✗ " . get_class($e) . ": " . $e->getMessage() . "\n"; }

// 6. consultarPersona via librería (A4)
sec("consultarPersona — CUIT 30678368710 tipo cuit");
try { dump($padron->consultarPersona($companyCuit, '30678368710', 'cuit')); }
catch (\Throwable $e) { echo "  ✗ " . $e->getMessage() . "\n"; }

// 7. consultarPersona via librería (A13 CUIL)
sec("consultarPersona — CUIL $companyCuit tipo cuil");
try { dump($padron->consultarPersona($companyCuit, $companyCuit, 'cuil')); }
catch (\Throwable $e) { echo "  ✗ " . $e->getMessage() . "\n"; }

// 8. Consulta DNI (extraído del CUIT 20-35079663-1)
sec("consultarPorDni — DNI 35079663");
try { dump($padron->consultarPorDni($companyCuit, '35079663')); }
catch (\Throwable $e) { echo "  ✗ " . $e->getMessage() . "\n"; }

echo "\n" . str_repeat('─', 60) . "\n  Fin pruebas CUIT $companyCuit\n" . str_repeat('─', 60) . "\n\n";
