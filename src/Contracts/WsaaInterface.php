<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Contracts;

interface WsaaInterface
{
    /**
     * Solicita un TA (Ticket de Acceso) al WSAA para el WSN indicado.
     *
     * El parámetro $wsn debe coincidir exactamente con el nombre del web service
     * registrado en el certificado de ARCA (ej. "ws_sr_constancia_inscripcion").
     * Un valor incorrecto produce el error "Computador no autorizado a acceder al servicio".
     *
     * @return array{token: string, sign: string, expires_at: string}|null
     */
    public function requestTa(string|int $companyCuit, string $wsn = 'wsfe'): ?array;
}
