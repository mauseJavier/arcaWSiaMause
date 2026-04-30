<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Tests;

use Mause\LaravelArca\Contracts\WsaaInterface;
use Mause\LaravelArca\LaravelArcaServiceProvider;
use Mause\LaravelArca\Modules\WsPadron;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;

final class WsPadronTest extends TestCase
{
    private WsaaInterface&MockInterface $wsaaMock;

    protected function getPackageProviders($app): array
    {
        return [LaravelArcaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->wsaaMock = Mockery::mock(WsaaInterface::class);
    }

    protected function tearDown(): void
    {
        // Registra las expectativas de Mockery como assertions de PHPUnit
        // para evitar "risky test" en tests que sólo verifican interacciones.
        $this->addToAssertionCount(
            Mockery::getContainer()->mockery_getExpectationCount()
        );
        Mockery::close();
        parent::tearDown();
    }

    private function makePadron(array $config = []): WsPadron
    {
        return new WsPadron($this->wsaaMock, array_merge(['mode' => 'homologation'], $config));
    }

    // -------------------------------------------------------------------------
    // Resolución de WSN
    // -------------------------------------------------------------------------

    public function test_wsn_cuit_usa_ws_sr_padron_a4_por_defecto(): void
    {
        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with('30712345678', 'ws_sr_padron_a4')
            ->andReturn(null);

        $this->makePadron()->consultarPersona('30712345678', '30112345678', 'cuit');
    }

    public function test_wsn_cuil_usa_ws_sr_padron_a13_por_defecto(): void
    {
        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with('30712345678', 'ws_sr_padron_a13')
            ->andReturn(null);

        $this->makePadron()->consultarPersona('30712345678', '27123456789', 'cuil');
    }

    public function test_wsn_configurable_desde_arca_config(): void
    {
        $config = ['padron' => ['services' => ['cuit' => 'ws_sr_padron_a100']]];

        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with('30712345678', 'ws_sr_padron_a100')
            ->andReturn(null);

        $this->makePadron($config)->consultarPersona('30712345678', '30112345678', 'cuit');
    }

    // -------------------------------------------------------------------------
    // Consulta DNI — flujo oficial ws_sr_padron_a13 (PersonaServiceA13)
    // -------------------------------------------------------------------------

    public function test_consultar_por_dni_usa_wsn_a13_por_defecto(): void
    {
        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with('30712345678', 'ws_sr_padron_a13')
            ->andReturn(null);

        $result = $this->makePadron()->consultarPorDni('30712345678', '12345678');

        // TA null → error de TA, no de WSN no configurado
        $this->assertSame('No se pudo obtener Ticket de Acceso', $result['error']);
    }

    public function test_consultar_por_dni_con_wsn_personalizado(): void
    {
        $config = ['padron' => ['services' => ['dni' => 'ws_sr_padron_a4']]];

        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with('30712345678', 'ws_sr_padron_a4')
            ->andReturn(null);

        $result = $this->makePadron($config)->consultarPorDni('30712345678', '12345678');

        $this->assertSame('No se pudo obtener Ticket de Acceso', $result['error']);
    }

    // -------------------------------------------------------------------------
    // TA no disponible
    // -------------------------------------------------------------------------

    public function test_consultar_persona_cuit_retorna_error_cuando_ta_falla(): void
    {
        $this->wsaaMock->shouldReceive('requestTa')->andReturn(null);

        $result = $this->makePadron()->consultarPersona('30712345678', '30112345678', 'cuit');

        $this->assertSame('cuit', $result['type']);
        $this->assertNull($result['data']);
        $this->assertSame('No se pudo obtener Ticket de Acceso', $result['error']);
    }

    public function test_consultar_persona_cuil_retorna_error_cuando_ta_falla(): void
    {
        $this->wsaaMock->shouldReceive('requestTa')->andReturn(null);

        $result = $this->makePadron()->consultarPersona('30712345678', '27123456789', 'cuil');

        $this->assertSame('cuil', $result['type']);
        $this->assertNull($result['data']);
        $this->assertSame('No se pudo obtener Ticket de Acceso', $result['error']);
    }

    // -------------------------------------------------------------------------
    // Detección automática en consultarPadron
    // -------------------------------------------------------------------------

    public function test_consultar_padron_auto_detecta_cuil_y_usa_wsn_a13(): void
    {
        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with(Mockery::any(), 'ws_sr_padron_a13')
            ->andReturn(null);

        $result = $this->makePadron()->consultarPadron('30712345678', '27123456789');

        $this->assertSame('cuil', $result['type']);
    }

    public function test_consultar_padron_auto_detecta_cuit_y_usa_wsn_padron_a4(): void
    {
        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with(Mockery::any(), 'ws_sr_padron_a4')
            ->andReturn(null);

        $result = $this->makePadron()->consultarPadron('30712345678', '30112345678');

        $this->assertSame('cuit', $result['type']);
    }

    public function test_consultar_padron_auto_detecta_dni_y_usa_wsn_a13(): void
    {
        $this->wsaaMock
            ->shouldReceive('requestTa')
            ->once()
            ->with(Mockery::any(), 'ws_sr_padron_a13')
            ->andReturn(null);

        $result = $this->makePadron()->consultarPadron('30712345678', '12345678');

        $this->assertSame('dni', $result['type']);
        $this->assertSame('No se pudo obtener Ticket de Acceso', $result['error']);
    }

    // -------------------------------------------------------------------------
    // Estructura de respuesta
    // -------------------------------------------------------------------------

    public function test_respuesta_siempre_tiene_type_data_error(): void
    {
        $this->wsaaMock->shouldReceive('requestTa')->andReturn(null);

        foreach (['cuit', 'cuil'] as $tipo) {
            $result = $this->makePadron()->consultarPersona('30712345678', '30112345678', $tipo);

            $this->assertArrayHasKey('type', $result, "Falta 'type' para $tipo");
            $this->assertArrayHasKey('data', $result, "Falta 'data' para $tipo");
            $this->assertArrayHasKey('error', $result, "Falta 'error' para $tipo");
        }
    }

    public function test_respuesta_dni_tiene_type_data_error(): void
    {
        $this->wsaaMock->shouldReceive('requestTa')->andReturn(null);

        $result = $this->makePadron()->consultarPorDni('30712345678', '12345678');

        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('error', $result);
    }
}
