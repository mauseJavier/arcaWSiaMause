<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Modules;

use Illuminate\Support\Facades\Log;

/**
 * WS Padrones - Consulta de datos de personas (CUIT, CUIL, DNI).
 * 
 * Detecta automáticamente el tipo de consulta:
 * - DNI: 8 dígitos
 * - CUIL: 11 dígitos que comienzan con 27 o 28
 * - CUIT: 11 dígitos (por defecto para jurídicas)
 */
final class WsPadron
{
    private string $wsPadronUrl;

    private Wsaa $wsaa;

    private string $mode;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(Wsaa $wsaa, array $config = [])
    {
        $this->wsaa = $wsaa;
        $this->mode = $config['mode'] ?? 'homologation';
        $this->wsPadronUrl = $this->mode === 'production'
            ? 'https://padse.afip.gov.ar/ws/padron'
            : 'https://padsehomo.afip.gov.ar/ws/padron';
    }

    /**
     * Consulta automática detectando el tipo (DNI, CUIT o CUIL).
     *
     * @param string|int $companyCuit CUIT de la empresa consultante
     * @param string|int $identifier Identificador a consultar (DNI, CUIT o CUIL)
     * @return array{
     *     type: string,
     *     data: array<string,mixed>|null,
     *     error: string|null
     * }|null
     */
    public function consultarPadron(string|int $companyCuit, string|int $identifier): ?array
    {
        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $identificationType = $this->detectIdentifierType($normalizedIdentifier);

        match ($identificationType) {
            'dni' => $result = $this->consultarPorDni($companyCuit, $normalizedIdentifier),
            'cuil' => $result = $this->consultarPersona($companyCuit, $normalizedIdentifier, 'cuil'),
            'cuit' => $result = $this->consultarPersona($companyCuit, $normalizedIdentifier, 'cuit'),
            default => $result = ['type' => 'unknown', 'data' => null, 'error' => 'Tipo de identificador no válido'],
        };

        return $result;
    }

    /**
     * Consultar por DNI (servicios/personafisica).
     *
     * @return array{
     *     type: 'dni',
     *     data: array<string,mixed>|null,
     *     error: string|null
     * }
     */
    public function consultarPorDni(string|int $companyCuit, string|int $numeroDni): array
    {
        $ta = $this->wsaa->requestTa($companyCuit, 'padron');

        if (!$ta) {
            Log::warning('WsPadron: No se pudo obtener TA para consulta DNI', [
                'cuit' => $companyCuit,
                'dni' => $numeroDni,
            ]);

            return [
                'type' => 'dni',
                'data' => null,
                'error' => 'No se pudo obtener Ticket de Acceso',
            ];
        }

        $normalizedCuit = $this->normalizeCuit($companyCuit);
        $normalizedDni = $this->normalizeIdentifier($numeroDni);

        try {
            $client = new \SoapClient(
                $this->wsPadronUrl . '/personafisica?wsdl',
                ['exceptions' => true, 'trace' => true, 'soap_version' => SOAP_1_2]
            );

            $result = $client->consultarPersonaFisica([
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentante' => (int) $normalizedCuit,
                'numeroDni' => (int) $normalizedDni,
            ]);

            $parsedData = $this->parsePersonaFisicaResponse($result);

            return [
                'type' => 'dni',
                'data' => $parsedData,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('WsPadron: Error en consultarPorDni', [
                'exception' => $e->getMessage(),
                'dni' => $numeroDni,
                'cuit' => $companyCuit,
            ]);

            return [
                'type' => 'dni',
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Consultar persona jurídica o física por CUIT/CUIL (padron/v1/persona o /a13/persona).
     *
     * @param 'cuit'|'cuil' $personType
     * @return array{
     *     type: 'cuit'|'cuil',
     *     data: array<string,mixed>|null,
     *     error: string|null
     * }
     */
    public function consultarPersona(string|int $companyCuit, string|int $cuitOCuil, string $personType = 'cuit'): array
    {
        $ta = $this->wsaa->requestTa($companyCuit, 'padron');

        if (!$ta) {
            Log::warning('WsPadron: No se pudo obtener TA para consulta de persona', [
                'cuit' => $companyCuit,
                'consulta' => $cuitOCuil,
                'tipo' => $personType,
            ]);

            return [
                'type' => $personType,
                'data' => null,
                'error' => 'No se pudo obtener Ticket de Acceso',
            ];
        }

        $normalizedCuit = $this->normalizeCuit($companyCuit);
        $normalizedIdentifier = $this->normalizeCuit($cuitOCuil);

        // Para CUIL usar servicio A13 (personas físicas)
        // Para CUIT usar servicio v1 (personas jurídicas)
        $endpoint = $personType === 'cuil'
            ? $this->wsPadronUrl . '/a13/persona?wsdl'
            : $this->wsPadronUrl . '/v1/persona?wsdl';

        try {
            $client = new \SoapClient(
                $endpoint,
                ['exceptions' => true, 'trace' => true, 'soap_version' => SOAP_1_2]
            );

            $result = $client->consultarPersona([
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentante' => (int) $normalizedCuit,
                'cuitPersonaConsultada' => (int) $normalizedIdentifier,
            ]);

            $parsedData = $personType === 'cuil'
                ? $this->parsePersonaFisicaResponse($result)
                : $this->parsePersonaJuridicaResponse($result);

            return [
                'type' => $personType,
                'data' => $parsedData,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('WsPadron: Error en consultarPersona', [
                'exception' => $e->getMessage(),
                'identifier' => $cuitOCuil,
                'type' => $personType,
                'cuit' => $companyCuit,
            ]);

            return [
                'type' => $personType,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parsear respuesta de persona jurídica.
     *
     * @param mixed $response
     * @return array<string,mixed>|null
     */
    private function parsePersonaJuridicaResponse($response): ?array
    {
        if (!is_object($response)) {
            return null;
        }

        $data = $this->objectToArray($response);

        return [
            'idPersona' => $data['idPersona'] ?? null,
            'cuit' => $data['cuit'] ?? null,
            'razonSocial' => $data['razonSocial'] ?? null,
            'tipoPersoneria' => $data['tipoPersoneria'] ?? null,
            'estado' => $data['estado'] ?? null,
            'domicilio' => $data['domicilio'] ?? null,
            'inscripcionesIva' => $data['inscripcionesIva'] ?? null,
            'domicilioFiscal' => $data['domicilioFiscal'] ?? null,
        ];
    }

    /**
     * Parsear respuesta de persona física (CUIL/DNI).
     *
     * @param mixed $response
     * @return array<string,mixed>|null
     */
    private function parsePersonaFisicaResponse($response): ?array
    {
        if (!is_object($response)) {
            return null;
        }

        $data = $this->objectToArray($response);

        return [
            'idPersona' => $data['idPersona'] ?? null,
            'cuit' => $data['cuit'] ?? null,
            'cuil' => $data['cuil'] ?? null,
            'nombre' => $data['nombre'] ?? null,
            'apellido' => $data['apellido'] ?? null,
            'tipoDocumento' => $data['tipoDocumento'] ?? null,
            'numeroDocumento' => $data['numeroDocumento'] ?? null,
            'estado' => $data['estado'] ?? null,
            'domicilio' => $data['domicilio'] ?? null,
            'impuestos' => $data['impuestos'] ?? null,
            'monotributo' => $data['monotributo'] ?? null,
            'empleador' => $data['empleador'] ?? null,
        ];
    }

    /**
     * Detectar tipo de identificador.
     *
     * @return 'dni'|'cuil'|'cuit'|'unknown'
     */
    private function detectIdentifierType(string $identifier): string
    {
        $cleanIdentifier = preg_replace('/\D/', '', $identifier);

        if (strlen($cleanIdentifier) === 8) {
            return 'dni';
        }

        if (strlen($cleanIdentifier) === 11) {
            // CUIL comienza con 27 o 28
            if (str_starts_with($cleanIdentifier, '27') || str_starts_with($cleanIdentifier, '28')) {
                return 'cuil';
            }

            return 'cuit';
        }

        return 'unknown';
    }

    /**
     * Normalizar identificador (remover caracteres especiales).
     */
    private function normalizeIdentifier(string|int $identifier): string
    {
        $identifier = (string) $identifier;

        return preg_replace('/\D/', '', $identifier) ?: $identifier;
    }

    /**
     * Normalizar CUIT (remover guiones).
     */
    private function normalizeCuit(string|int $cuit): string
    {
        $cuit = (string) $cuit;

        return preg_replace('/[^\d]/', '', $cuit) ?: $cuit;
    }

    /**
     * Convertir objeto SOAP a array recursivamente.
     *
     * @param mixed $obj
     * @return array<string,mixed>
     */
    private function objectToArray($obj): array
    {
        if (is_object($obj)) {
            $obj = get_object_vars($obj);
        }

        if (!is_array($obj)) {
            return [];
        }

        $result = [];
        foreach ($obj as $key => $value) {
            if (is_object($value) || is_array($value)) {
                $result[$key] = $this->objectToArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
