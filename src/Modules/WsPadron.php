<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Modules;

use Illuminate\Support\Facades\Log;
use Mause\LaravelArca\Contracts\WsaaInterface;

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
    /**
     * Endpoint oficial del servicio PersonaServiceA4 (ws_sr_constancia_inscripcion).
     * Usado para consultas de personas jurídicas por CUIT.
     * Fuente: https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA4?wsdl
     */
    private string $wsA4Url;

    /**
     * Endpoint oficial del servicio PersonaServiceA13 (ws_sr_padron_a13).
     * Usado para consultas por CUIL y por DNI (getIdPersonaListByDocumento).
     * Fuente: https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?wsdl
     */
    private string $wsA13Url;

    private WsaaInterface $wsaa;

    private string $mode;

    /** @var array{cuit: string, cuil: string, dni: string|null} */
    private array $wsnMap;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(WsaaInterface $wsaa, array $config = [])
    {
        $this->wsaa = $wsaa;
        $this->mode = $config['mode'] ?? 'homologation';
        $this->wsA4Url = $this->mode === 'production'
            ? 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA4'
            : 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA4';
        $this->wsA13Url = $this->mode === 'production'
            ? 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13'
            : 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13';

        $padronServices = $config['padron']['services'] ?? [];
        $this->wsnMap = [
            'cuit' => (string) ($padronServices['cuit'] ?? 'ws_sr_padron_a4'),
            'cuil' => (string) ($padronServices['cuil'] ?? 'ws_sr_padron_a13'),
            'dni'  => (string) ($padronServices['dni'] ?? 'ws_sr_padron_a13'),
        ];
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
     * Consultar por DNI usando ws_sr_padron_a13.
     *
     * Flujo oficial de dos pasos:
     * 1. getIdPersonaListByDocumento(documento) → lista de idPersona (CUIT/CUIL)
     * 2. getPersona(idPersona) → datos completos de la persona
     *
     * Endpoint: PersonaServiceA13 (aws.afip.gov.ar/sr-padron/webservices/personaServiceA13)
     * WSN:      ws_sr_padron_a13
     *
     * @return array{
     *     type: 'dni',
     *     data: array<string,mixed>|null,
     *     error: string|null
     * }
     */
    public function consultarPorDni(string|int $companyCuit, string|int $numeroDni): array
    {
        $wsn = $this->resolveWsn('dni');
        $ta = $this->wsaa->requestTa($companyCuit, $wsn);

        if (!$ta) {
            Log::warning('WsPadron: No se pudo obtener TA para consulta DNI', [
                'wsn' => $wsn,
                'ambiente' => $this->mode,
                'cuit_representante' => $companyCuit,
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
        $endpoint = $this->wsA13Url . '?wsdl';

        try {
            $client = new \SoapClient(
                $endpoint,
                    ['exceptions' => true, 'trace' => true, 'soap_version' => SOAP_1_1]
            );

            // Paso 1: obtener lista de idPersona (CUIT/CUIL) a partir del DNI.
            $listResponse = $client->getIdPersonaListByDocumento([
                'token'            => $ta['token'],
                'sign'             => $ta['sign'],
                'cuitRepresentada' => (int) $normalizedCuit,
                'documento'        => $normalizedDni,
            ]);

            $idPersonaList = $this->extractIdPersonaList($listResponse);

            if (empty($idPersonaList)) {
                return [
                    'type'  => 'dni',
                    'data'  => null,
                    'error' => 'DNI no encontrado en el padrón',
                ];
            }

            // Paso 2: obtener datos completos para cada idPersona.
            $personas = [];
            foreach ($idPersonaList as $idPersona) {
                $personaResponse = $client->getPersona([
                    'token'            => $ta['token'],
                    'sign'             => $ta['sign'],
                    'cuitRepresentada' => (int) $normalizedCuit,
                    'idPersona'        => (int) $idPersona,
                ]);
                $parsed = $this->parsePersonaA13Response($personaResponse);
                if ($parsed !== null) {
                    $personas[] = $parsed;
                }
            }

            return [
                'type'  => 'dni',
                'data'  => count($personas) === 1 ? $personas[0] : $personas,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('WsPadron: Error en consultarPorDni', [
                'exception'        => $e->getMessage(),
                'wsn'              => $wsn,
                'endpoint'         => $endpoint,
                'ambiente'         => $this->mode,
                'cuit_representante' => $companyCuit,
                'dni'              => $numeroDni,
            ]);

            return [
                'type'  => 'dni',
                'data'  => null,
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
        $wsn = $this->resolveWsn($personType);
        $ta = $this->wsaa->requestTa($companyCuit, $wsn);

        if (!$ta) {
            Log::warning('WsPadron: No se pudo obtener TA para consulta de persona', [
                'wsn' => $wsn,
                'ambiente' => $this->mode,
                'cuit_representante' => $companyCuit,
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

        // Ambos servicios exponen getPersona(token, sign, cuitRepresentada, idPersona).
        // En el padrón de ARCA el idPersona de una persona es su propio CUIT/CUIL numérico.
        // Para CUIL usar PersonaServiceA13 (personas físicas / ws_sr_padron_a13)
        // Para CUIT usar PersonaServiceA4 (personas jurídicas / ws_sr_constancia_inscripcion)
        $endpoint = $personType === 'cuil'
            ? $this->wsA13Url . '?wsdl'
            : $this->wsA4Url . '?wsdl';

        try {
            $client = new \SoapClient(
                $endpoint,
                    ['exceptions' => true, 'trace' => true, 'soap_version' => SOAP_1_1]
            );

            $result = $client->getPersona([
                'token'            => $ta['token'],
                'sign'             => $ta['sign'],
                'cuitRepresentada' => (int) $normalizedCuit,
                'idPersona'        => (int) $normalizedIdentifier,
            ]);

            $parsedData = $this->parsePersonaA13Response($result);

            return [
                'type' => $personType,
                'data' => $parsedData,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('WsPadron: Error en consultarPersona', [
                'exception' => $e->getMessage(),
                'wsn' => $wsn,
                'endpoint' => $endpoint,
                'ambiente' => $this->mode,
                'cuit_representante' => $companyCuit,
                'consulta' => $cuitOCuil,
                'tipo' => $personType,
            ]);

            return [
                'type' => $personType,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resuelve el WSN (Web Service Name) oficial de ARCA para la operación indicada.
     *
     * El WSN se usa como elemento <service> del TRA enviado a WSAA. Debe coincidir
     * exactamente con el servicio autorizado en el certificado. Un valor incorrecto
     * provoca el error "Computador no autorizado a acceder al servicio".
     *
     * @throws \RuntimeException si el WSN no está configurado para la operación solicitada
     */
    private function resolveWsn(string $operationType): string
    {
        $wsn = $this->wsnMap[$operationType] ?? null;

        if ($wsn === null || $wsn === '') {
            throw new \RuntimeException(sprintf(
                'WsPadron: WSN no configurado para la operación "%s". '
                . 'Defina padron.services.%s en arca.php con el WSN oficial de ARCA '
                . 'antes de usar este tipo de consulta (ver catalogo.asp).',
                $operationType,
                $operationType
            ));
        }

        return $wsn;
    }

    /**
     * Extrae la lista de idPersona de la respuesta de getIdPersonaListByDocumento.
     *
     * @param mixed $response
     * @return array<int,int|string>
     */
    private function extractIdPersonaList($response): array
    {
        if (!is_object($response)) {
            return [];
        }

        $data = $this->objectToArray($response);

        // idPersonaListReturn.idPersona puede ser escalar o array
        $raw = $data['idPersonaListReturn']['idPersona'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter($raw, static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Parsear respuesta de getPersona del servicio PersonaServiceA13.
     *
     * Campos del WSDL oficial: idPersona, nombre, apellido, numeroDocumento,
     * tipoDocumento, tipoClave, tipoPersona, estadoClave, domicilio[], razonSocial.
     *
     * @param mixed $response
     * @return array<string,mixed>|null
     */
    private function parsePersonaA13Response($response): ?array
    {
        if (!is_object($response)) {
            return null;
        }

        $data = $this->objectToArray($response);
        $persona = $data['personaReturn']['persona'] ?? $data;

        return [
            'idPersona'       => $persona['idPersona'] ?? null,
            'tipoClave'       => $persona['tipoClave'] ?? null,
            'nombre'          => $persona['nombre'] ?? null,
            'apellido'        => $persona['apellido'] ?? null,
            'razonSocial'     => $persona['razonSocial'] ?? null,
            'tipoPersona'     => $persona['tipoPersona'] ?? null,
            'tipoDocumento'   => $persona['tipoDocumento'] ?? null,
            'numeroDocumento' => $persona['numeroDocumento'] ?? null,
            'estado'          => $persona['estadoClave'] ?? null,
            'domicilio'       => $persona['domicilio'] ?? null,
        ];
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
