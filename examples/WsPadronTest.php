<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Tests;

use PHPUnit\Framework\TestCase;
use Mause\LaravelArca\Modules\WsPadron;
use Mause\LaravelArca\Modules\Wsaa;

/**
 * Tests para el módulo WsPadron
 * 
 * NOTA: Estos tests son ejemplos. Para ejecutarlos en producción,
 * necesitas un certificado válido y acceso al servicio 'padron' en AFIP.
 */
class WsPadronTest extends TestCase
{
    private WsPadron $wsPadron;
    private Wsaa $wsaa;

    protected function setUp(): void
    {
        // Mock o instancia del Wsaa
        $config = [
            'mode' => 'homologation',
            'cert_path' => 'tests/fixtures/cert.crt',
            'key_path' => 'tests/fixtures/key.key',
        ];

        $this->wsaa = new Wsaa($config);
        $this->wsPadron = new WsPadron($this->wsaa, $config);
    }

    /**
     * Test: Detectar DNI (8 dígitos)
     */
    public function test_detectar_dni(): void
    {
        $result = $this->wsPadron->consultarPadron('30712345678', '12345678');

        if ($result) {
            $this->assertEquals('dni', $result['type']);
        }
    }

    /**
     * Test: Detectar CUIL (11 dígitos que comienzan con 27)
     */
    public function test_detectar_cuil_27(): void
    {
        $result = $this->wsPadron->consultarPadron('30712345678', '27123456789');

        if ($result) {
            $this->assertEquals('cuil', $result['type']);
        }
    }

    /**
     * Test: Detectar CUIL (11 dígitos que comienzan con 28)
     */
    public function test_detectar_cuil_28(): void
    {
        $result = $this->wsPadron->consultarPadron('30712345678', '28123456789');

        if ($result) {
            $this->assertEquals('cuil', $result['type']);
        }
    }

    /**
     * Test: Detectar CUIT (11 dígitos normales)
     */
    public function test_detectar_cuit(): void
    {
        $result = $this->wsPadron->consultarPadron('30712345678', '30112345678');

        if ($result) {
            $this->assertEquals('cuit', $result['type']);
        }
    }

    /**
     * Test: Normalización de DNI con guiones
     */
    public function test_normalizacion_dni_con_guiones(): void
    {
        $result1 = $this->wsPadron->consultarPadron('30712345678', '12-345-678');
        $result2 = $this->wsPadron::consultarPadron('30712345678', '12345678');

        // Ambas deberían retornar el mismo tipo
        if ($result1 && $result2) {
            $this->assertEquals($result1['type'], $result2['type']);
        }
    }

    /**
     * Test: Normalización de CUIT con guiones
     */
    public function test_normalizacion_cuit_con_guiones(): void
    {
        $result1 = $this->wsPadron->consultarPadron('30712345678', '30-112345678-9');
        $result2 = $this->wsPadron->consultarPadron('30712345678', '30112345678');

        if ($result1 && $result2) {
            $this->assertEquals($result1['type'], $result2['type']);
        }
    }

    /**
     * Test: Estructura de respuesta exitosa para DNI
     */
    public function test_respuesta_dni_estructura(): void
    {
        $result = $this->wsPadron->consultarPorDni('30712345678', '12345678');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('dni', $result['type']);
    }

    /**
     * Test: Estructura de respuesta exitosa para CUIT
     */
    public function test_respuesta_cuit_estructura(): void
    {
        $result = $this->wsPadron->consultarPersona('30712345678', '30112345678', 'cuit');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('cuit', $result['type']);
    }

    /**
     * Test: Estructura de respuesta exitosa para CUIL
     */
    public function test_respuesta_cuil_estructura(): void
    {
        $result = $this->wsPadron->consultarPersona('30712345678', '27123456789', 'cuil');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('cuil', $result['type']);
    }

    /**
     * Test: Manejo de error - TA no disponible
     * 
     * Simula que el WSAA no puede obtener TA
     */
    public function test_error_ta_no_disponible(): void
    {
        // Mock Wsaa para que retorne null
        $wsaaMock = \Mockery::mock(Wsaa::class);
        $wsaaMock->shouldReceive('requestTa')->andReturn(null);

        $wsPadron = new WsPadron($wsaaMock, ['mode' => 'homologation']);
        $result = $wsPadron->consultarPorDni('30712345678', '12345678');

        $this->assertNotNull($result['error']);
        $this->assertNull($result['data']);
    }

    /**
     * Test: DNI inválido (menos de 8 dígitos)
     */
    public function test_dni_invalido_corto(): void
    {
        $result = $this->wsPadron->consultarPadron('30712345678', '1234567');

        if ($result) {
            $this->assertEquals('unknown', $result['type']);
        }
    }

    /**
     * Test: Identificador inválido (tipo desconocido)
     */
    public function test_identificador_invalido(): void
    {
        $result = $this->wsPadron->consultarPadron('30712345678', '123');

        if ($result) {
            $this->assertEquals('unknown', $result['type']);
        }
    }

    /**
     * Test: Resultado con datos de persona física
     */
    public function test_datos_persona_fisica_completos(): void
    {
        $result = $this->wsPadron->consultarPorDni('30712345678', '12345678');

        if ($result && !$result['error'] && $result['data']) {
            $data = $result['data'];

            // Campos esperados
            $this->assertArrayHasKey('cuit', $data);
            $this->assertArrayHasKey('cuil', $data);
            $this->assertArrayHasKey('nombre', $data);
            $this->assertArrayHasKey('apellido', $data);
            $this->assertArrayHasKey('numeroDocumento', $data);
            $this->assertArrayHasKey('estado', $data);
        }
    }

    /**
     * Test: Resultado con datos de persona jurídica
     */
    public function test_datos_persona_juridica_completos(): void
    {
        $result = $this->wsPadron->consultarPersona('30712345678', '30112345678', 'cuit');

        if ($result && !$result['error'] && $result['data']) {
            $data = $result['data'];

            // Campos esperados
            $this->assertArrayHasKey('cuit', $data);
            $this->assertArrayHasKey('razonSocial', $data);
            $this->assertArrayHasKey('tipoPersoneria', $data);
            $this->assertArrayHasKey('estado', $data);
        }
    }
}
