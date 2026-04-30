<?php

/**
 * Script de prueba manual — WsPadron en homologación.
 *
 * Uso:
 *   php examples/test-padron-homo.php
 *
 * Ejecuta consultas reales contra los servidores de homologación de ARCA
 * usando el CUIT 20358337164 y sus credenciales de prueba.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ─── Bootstrap mínimo de Laravel (sin HTTP/routes) ───────────────────────────

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\Filesystem;

$app = new \Illuminate\Foundation\Application(dirname(__DIR__));

// Bindings mínimos
$app->singleton('config', fn () => new ConfigRepository([
    'arca' => require __DIR__ . '/../config/arca.php',
    'cache' => [
        'default' => 'array',
        'stores'  => ['array' => ['driver' => 'array']],
    ],
    'logging' => [
        'default'  => 'stderr',
        'channels' => [
            'stderr' => [
                'driver' => 'monolog',
                'handler' => \Monolog\Handler\StreamHandler::class,
                'with' => ['stream' => 'php://stderr'],
            ],
        ],
    ],
]));

$app->singleton('files', fn () => new Filesystem());
$app->singleton('cache', fn ($app) => new CacheManager($app));
$app->singleton('cache.store', fn ($app) => $app['cache']->driver());
$app->singleton('log', function ($app) {
    $factory = new \Illuminate\Log\LogManager($app);
    return $factory;
});

Facade::setFacadeApplication($app);

// ─── Configuración de credenciales ───────────────────────────────────────────

$companyCuit = '20358337164';

// Los certs están en examples/20358337164/cert.crt y key.key
// El patrón usa %s que se reemplaza con el CUIT al resolver las rutas.
$certBaseDir = __DIR__;
$app['config']->set('arca.cert_path_pattern', $certBaseDir . '/%s/cert.crt');
$app['config']->set('arca.key_path_pattern',  $certBaseDir . '/%s/key.key');
$app['config']->set('arca.mode', 'homologation');

// ─── Instanciación directa ────────────────────────────────────────────────────

$config = $app['config']->get('arca');
$wsaa   = new \Mause\LaravelArca\Modules\Wsaa($config);
$padron = new \Mause\LaravelArca\Modules\WsPadron($wsaa, $config);

// ─── Helpers de output ────────────────────────────────────────────────────────

function printSection(string $title): void
{
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('═', 60) . "\n";
}

function printResult(?array $result): void
{
    if ($result === null) {
        echo "  → NULL (posiblemente timeout o SOAP error)\n";
        return;
    }
    if (!empty($result['error'])) {
        echo "  ✗ ERROR: " . $result['error'] . "\n";
        return;
    }
    $data = $result['data'] ?? $result;
    echo "  ✓ OK\n";
    printArray($data, '    ');
}

function printArray(mixed $data, string $indent = ''): void
{
    if (!is_array($data)) {
        echo $indent . print_r($data, true) . "\n";
        return;
    }
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            echo $indent . "{$key}:\n";
            printArray($value, $indent . '  ');
        } else {
            echo $indent . "{$key}: " . ($value ?? '(null)') . "\n";
        }
    }
}

// ─── Prueba 1: TA directo ─────────────────────────────────────────────────────

printSection('1. Ticket de Acceso WSAA (ws_sr_constancia_inscripcion)');
try {
    $ta = $wsaa->requestTa($companyCuit, 'ws_sr_constancia_inscripcion');
    if ($ta) {
        echo "  ✓ token: " . substr($ta['token'], 0, 40) . "…\n";
        echo "  ✓ sign:  " . substr($ta['sign'],  0, 40) . "…\n";
        echo "  ✓ expires_at: " . $ta['expires_at'] . "\n";
    } else {
        echo "  ✗ No se obtuvo TA\n";
    }
} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
}

// ─── Prueba 2: TA para ws_sr_padron_a13 ──────────────────────────────────────

printSection('2. Ticket de Acceso WSAA (ws_sr_padron_a13)');
try {
    $ta2 = $wsaa->requestTa($companyCuit, 'ws_sr_padron_a13');
    if ($ta2) {
        echo "  ✓ token: " . substr($ta2['token'], 0, 40) . "…\n";
        echo "  ✓ expires_at: " . $ta2['expires_at'] . "\n";
    } else {
        echo "  ✗ No se obtuvo TA (certificado no autorizado para ws_sr_padron_a13?)\n";
    }
} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
}

// ─── Prueba 3: Consulta CUIT (jurídica) ───────────────────────────────────────

printSection('3. consultarPersona — CUIT 30678368710 (AFIP) tipo cuit');
try {
    $r = $padron->consultarPersona($companyCuit, '30678368710', 'cuit');
    printResult($r);
} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
}

// ─── Prueba 4: Consulta CUIL (persona física) ─────────────────────────────────

printSection('4. consultarPersona — CUIL del propio emisor (' . $companyCuit . ')');
try {
    $r = $padron->consultarPersona($companyCuit, $companyCuit);
    printResult($r);
} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
}

// ─── Prueba 5: Consulta automática ────────────────────────────────────────────

printSection('5. consultarPadron — detección automática CUIT 30678368710');
try {
    $r = $padron->consultarPadron($companyCuit, '30678368710');
    printResult($r);
} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
}

// ─── Prueba 6: Consulta DNI ───────────────────────────────────────────────────

printSection('6. consultarPorDni — DNI del titular del cert (35837164)');
try {
    // DNI de 20358337164 → 35837164 (extraído del CUIT: 20-35837164-4)
    $r = $padron->consultarPorDni($companyCuit, '35837164');
    printResult($r);
} catch (\Throwable $e) {
    echo "  ✗ Excepción: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "  Fin de las pruebas de homologación\n";
echo str_repeat('─', 60) . "\n\n";
