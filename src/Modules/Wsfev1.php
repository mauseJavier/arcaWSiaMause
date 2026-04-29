<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Modules;

/**
 * WSFEv1 - WebService de Factura Electrónica V1.
 * Maneja operaciones de facturación (comprobantes A, B, C, M).
 */
final class Wsfev1
{
    private string $wsfev1Url;

    private Wsaa $wsaa;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(Wsaa $wsaa, array $config = [])
    {
        $this->wsaa = $wsaa;
        $mode = $config['mode'] ?? 'homologation';
        $this->wsfev1Url = $mode === 'production'
            ? 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'
            : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
    }

    /**
     * Obtiene el último número de comprobante autorizado.
     *
     * @return array{cbte_nro: int, error: string|null}|null
     */
    public function getLastAuthorizedNumber(string|int $companyCuit, int $ptoVta, int $cbteType): ?array
    {
        $ta = $this->wsaa->requestTa($companyCuit, 'wsfe');
        if (!$ta) {
            return ['error' => 'No se pudo obtener TA'];
        }

        $normalizedCuit = $this->normalizeCuit($companyCuit);

        try {
            $client = new \SoapClient(
                $this->wsfev1Url . '?wsdl',
                ['exceptions' => true, 'trace' => true]
            );

            $result = $client->FECompUltimoAutorizado([
                'Auth' => [
                    'Token' => $ta['token'],
                    'Sign' => $ta['sign'],
                    'Cuit' => (int) $normalizedCuit,
                ],
                'PtoVta' => $ptoVta,
                'CbteTipo' => $cbteType,
            ]);

            if (isset($result->FECompUltimoAutorizadoResult->CbteNro)) {
                return [
                    'cbte_nro' => (int) $result->FECompUltimoAutorizadoResult->CbteNro,
                    'error' => null,
                ];
            }

            return ['error' => 'Sin resultado'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene tipos de comprobantes disponibles.
     *
     * @return array<array{id: int, name: string}>|null
     */
    public function getInvoiceTypes(string|int $companyCuit): ?array
    {
        $ta = $this->wsaa->requestTa($companyCuit, 'wsfe');
        if (!$ta) {
            return null;
        }

        $normalizedCuit = $this->normalizeCuit($companyCuit);

        try {
            $client = new \SoapClient(
                $this->wsfev1Url . '?wsdl',
                ['exceptions' => true, 'trace' => true]
            );

            $result = $client->FEParamGetTiposCbte([
                'Auth' => [
                    'Token' => $ta['token'],
                    'Sign' => $ta['sign'],
                    'Cuit' => (int) $normalizedCuit,
                ],
            ]);

            $types = [];
            $cbteTipos = null;
            if (isset($result->FEParamGetTiposCbteResult->ResultGet->CbteTipo)) {
                $cbteTipos = $result->FEParamGetTiposCbteResult->ResultGet->CbteTipo;
            } elseif (isset($result->FEParamGetTiposCbteResult->CbteTipo)) {
                // Compatibilidad con respuestas antiguas.
                $cbteTipos = $result->FEParamGetTiposCbteResult->CbteTipo;
            }

            if ($cbteTipos !== null) {
                foreach ((array) $cbteTipos as $type) {
                    $types[] = [
                        'id' => (int) $type->Id,
                        'name' => (string) $type->Desc,
                    ];
                }
            }

            return $types;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Solicita CAE para un comprobante.
     *
     * @param array<string,mixed> $invoice
     *
     * @return array{cae: string, expiration: string, error: string|null}|null
     */
    public function requestCae(string|int $companyCuit, array $invoice): ?array
    {
        $ta = $this->wsaa->requestTa($companyCuit, 'wsfe');
        if (!$ta) {
            return ['error' => 'No se pudo obtener TA'];
        }

        $normalizedCuit = $this->normalizeCuit($companyCuit);

        try {
            $client = new \SoapClient(
                $this->wsfev1Url . '?wsdl',
                ['exceptions' => true, 'trace' => true]
            );

            $result = $client->FECAESolicitar([
                'Auth' => [
                    'Token' => $ta['token'],
                    'Sign' => $ta['sign'],
                    'Cuit' => (int) $normalizedCuit,
                ],
                'FeCAEReq' => $invoice,
            ]);

            if (isset($result->FECAESolicitarResult->FeDetResp->FECAEDetResponse)) {
                $response = $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse;

                return [
                    'cae' => (string) $response->CAE,
                    'expiration' => (string) $response->CAEFchVto,
                    'error' => null,
                ];
            }

            return ['error' => 'Sin CAE en respuesta'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function normalizeCuit(string|int $companyCuit): string
    {
        $normalizedCuit = preg_replace('/\D+/', '', (string) $companyCuit) ?? '';

        if ($normalizedCuit === '') {
            throw new \RuntimeException('CUIT emisor inválido.');
        }

        return $normalizedCuit;
    }
}
