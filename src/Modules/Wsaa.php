<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Modules;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Mause\LaravelArca\Contracts\WsaaInterface;

/**
 * WSAA - WebService de Autenticación y Autorización.
 * Maneja la generación de TRA, firma CMS y solicitud de TA.
 */
final class Wsaa implements WsaaInterface
{
    private string $certPath;

    private string $keyPath;

    private string $certPathPattern;

    private string $keyPathPattern;

    private ?string $keyPassphrase;

    private string $wsaaUrl;

    private string $mode;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->certPath = $config['cert_path'] ?? storage_path('app/arca/arca.crt');
        $this->keyPath = $config['key_path'] ?? storage_path('app/arca/arca.key');
        $this->certPathPattern = $config['cert_path_pattern'] ?? storage_path('app/public/%s/cert.crt');
        $this->keyPathPattern = $config['key_path_pattern'] ?? storage_path('app/public/%s/key.key');
        $this->keyPassphrase = $config['key_passphrase'] ?? null;
        $this->mode = $config['mode'] ?? 'homologation';
        $this->wsaaUrl = $this->mode === 'production'
            ? (string) ($config['wsaa']['production_url'] ?? 'https://wsaa.afip.gov.ar/ws/services/LoginCms')
            : (string) ($config['wsaa']['homologation_url'] ?? 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms');
    }

    /**
     * Genera el TRA (Ticket de Requerimiento de Acceso).
     */
    public function generateTra(string $wsn = 'wsfe'): string
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $generationTime = (clone $now)->modify('-60 seconds');
        $expirationTime = (clone $now)->modify('+60 seconds');

        $tra = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>%d</uniqueId><generationTime>%s</generationTime><expirationTime>%s</expirationTime></header><service>%s</service></loginTicketRequest>',
            time(),
            $generationTime->format('c'),
            $expirationTime->format('c'),
            $wsn
        );

        return $tra;
    }

    /**
     * Genera la clave privada y el CSR para una empresa emisora.
     *
     * Datos mínimos recomendados por ARCA para el CSR:
     * - countryName: normalmente AR
     * - organizationName: razón social o nombre de la empresa
     * - commonName: nombre del sistema o alias del certificado
     * - serialNumber: se completa automáticamente como "CUIT {cuit}"
     *
     * @param array<string,string|null> $distinguishedName
     * @return array{key_path: string, csr_path: string, key: string, csr: string}
     */
    public function createCertificateRequest(
        string|int $companyCuit,
        array $distinguishedName,
        ?string $passphrase = null,
        int $privateKeyBits = 2048
    ): array {
        $normalizedCuit = $this->normalizeCuit($companyCuit);
        [$certPath, $keyPath] = $this->resolveCredentialPaths($normalizedCuit);
        $csrPath = $this->buildCsrPath($normalizedCuit);

        $directory = dirname($keyPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('No se pudo crear el directorio para almacenar credenciales.');
        }

        $dn = $this->buildDistinguishedName($normalizedCuit, $distinguishedName);

        $privateKey = openssl_pkey_new([
            'private_key_bits' => $privateKeyBits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException('No se pudo generar la clave privada: ' . (openssl_error_string() ?: 'error desconocido'));
        }

        $privateKeyOut = '';
        $keyExported = openssl_pkey_export($privateKey, $privateKeyOut, $passphrase ?? null);
        if ($keyExported === false) {
            throw new \RuntimeException('No se pudo exportar la clave privada: ' . (openssl_error_string() ?: 'error desconocido'));
        }

        $csrResource = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
        if ($csrResource === false) {
            throw new \RuntimeException('No se pudo generar el CSR: ' . (openssl_error_string() ?: 'error desconocido'));
        }

        $csrOut = '';
        $csrExported = openssl_csr_export($csrResource, $csrOut, false);
        if ($csrExported === false) {
            throw new \RuntimeException('No se pudo exportar el CSR: ' . (openssl_error_string() ?: 'error desconocido'));
        }

        file_put_contents($keyPath, $privateKeyOut);
        file_put_contents($csrPath, $csrOut);

        // El certPath no se crea acá: lo entrega ARCA luego de subir el CSR.
        if (!file_exists($certPath)) {
            @touch($certPath);
        }

        return [
            'key_path' => $keyPath,
            'csr_path' => $csrPath,
            'key' => $privateKeyOut,
            'csr' => $csrOut,
        ];
    }

    /**
     * Firma el TRA usando PKCS#7 (CMS).
     */
    public function signTra(string $tra, string|int|null $companyCuit = null): ?string
    {
        [$certPath, $keyPath] = $this->resolveCredentialPaths($companyCuit);

        if (!file_exists($keyPath) || !file_exists($certPath)) {
            throw new \RuntimeException('Certificado o clave privada no encontrados.');
        }

        $this->validateCredentials($certPath, $keyPath);

        $tmpIn = tmpfile();
        $tmpOut = tmpfile();

        if (!$tmpIn || !$tmpOut) {
            throw new \RuntimeException('No se pueden crear archivos temporales.');
        }

        fwrite($tmpIn, $tra);
        rewind($tmpIn);

        $openssl = openssl_pkcs7_sign(
            stream_get_meta_data($tmpIn)['uri'],
            stream_get_meta_data($tmpOut)['uri'],
            'file://' . realpath($certPath),
            $this->keyPassphrase ? ['file://' . realpath($keyPath), $this->keyPassphrase] : 'file://' . realpath($keyPath),
            [],
            0
        );

        if (!$openssl) {
            throw new \RuntimeException('Firma CMS falló: ' . openssl_error_string());
        }

        rewind($tmpOut);
        $cms = stream_get_contents($tmpOut);

        fclose($tmpIn);
        fclose($tmpOut);

        return $cms;
    }

    /**
     * Solicita un TA (Ticket de Acceso) al WSAA.
     *
     * @return array{token: string, sign: string, expires_at: string}|null
     */
    public function requestTa(string|int $companyCuit, string $wsn = 'wsfe'): ?array
    {
        $normalizedCuit = $this->normalizeCuit($companyCuit);
        $cacheKey = sprintf('arca.ta.%s.%s.%s', $this->mode, $normalizedCuit, $wsn);

        // Intenta recuperar del cache si existe y no ha expirado
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['expires_at'])) {
                if (strtotime($cached['expires_at']) > time()) {
                    return $cached;
                }
            }
        }

        // Genera TRA
        $tra = $this->generateTra($wsn);

        // Firma TRA
        $cms = $this->signTra($tra, $normalizedCuit);

        if (!$cms) {
            return null;
        }

        // Limpia el CMS (extrae el body)
        $cms = $this->extractCmsBody($cms);

        // Realiza llamada SOAP al WSAA
        try {
            $client = new \SoapClient(
                $this->wsaaUrl . '?wsdl',
                ['exceptions' => true, 'trace' => true]
            );

            try {
                $response = $client->loginCms(['in' => $cms]);
            } catch (\SoapFault $fault) {
                // Algunos WSDL de WSAA exponen loginCms con el argumento "in0".
                if (str_contains($fault->getMessage(), "'in0'")) {
                    $response = $client->loginCms(['in0' => $cms]);
                } else {
                    throw $fault;
                }
            }

            $xmlResponse = $response->loginCmsReturn;

            // Parsea respuesta
            $xml = simplexml_load_string($xmlResponse);
            if (!$xml) {
                throw new \RuntimeException('Respuesta WSAA inválida.');
            }

            // Extrae token y sign
            $token = (string) $xml->xpath('//token')[0] ?? null;
            $sign = (string) $xml->xpath('//sign')[0] ?? null;
            $expiresAt = (string) $xml->xpath('//expirationTime')[0] ?? null;

            if (!$token || !$sign) {
                throw new \RuntimeException('Token o Sign no encontrados en respuesta WSAA.');
            }

            $result = [
                'token' => $token,
                'sign' => $sign,
                'expires_at' => $expiresAt,
            ];

            // Cachea por 11 horas (seguridad)
            Cache::put($cacheKey, $result, 11 * 3600);

            return $result;
        } catch (\Exception $e) {
            throw new \RuntimeException('Error WSAA: ' . $e->getMessage());
        }
    }

    /**
     * Extrae el body del CMS firmado.
     */
    private function extractCmsBody(string $cms): string
    {
        if (preg_match('/-----BEGIN PKCS7-----(.*?)-----END PKCS7-----/s', $cms, $matches) === 1) {
            return trim($matches[1]);
        }

        // Formato S/MIME: elimina headers MIME y conserva el cuerpo CMS base64.
        if (preg_match('/\R\R(.*)$/s', $cms, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($cms);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveCredentialPaths(string|int|null $companyCuit = null): array
    {
        if ($companyCuit === null || $companyCuit === '') {
            return [$this->certPath, $this->keyPath];
        }

        $normalizedCuit = $this->normalizeCuit($companyCuit);

        return [
            sprintf($this->certPathPattern, $normalizedCuit),
            sprintf($this->keyPathPattern, $normalizedCuit),
        ];
    }

    private function buildCsrPath(string $normalizedCuit): string
    {
        $directory = dirname(sprintf($this->keyPathPattern, $normalizedCuit));

        return $directory . DIRECTORY_SEPARATOR . 'request.csr';
    }

    private function validateCredentials(string $certPath, string $keyPath): void
    {
        $certContents = file_get_contents($certPath);
        if ($certContents === false || trim($certContents) === '') {
            throw new \RuntimeException(sprintf(
                'El certificado esta vacio o no se puede leer: %s. Debes guardar en ese archivo el cert.crt emitido por ARCA, no el CSR.',
                $certPath
            ));
        }

        $keyContents = file_get_contents($keyPath);
        if ($keyContents === false || trim($keyContents) === '') {
            throw new \RuntimeException(sprintf('La clave privada esta vacia o no se puede leer: %s.', $keyPath));
        }

        $certificate = openssl_x509_read($certContents);
        if ($certificate === false) {
            throw new \RuntimeException(sprintf(
                'El certificado no tiene un formato X509/PEM valido: %s. Verifica que sea el cert.crt emitido por ARCA.',
                $certPath
            ));
        }

        $privateKey = openssl_pkey_get_private($keyContents, $this->keyPassphrase ?? '');
        if ($privateKey === false) {
            throw new \RuntimeException(sprintf(
                'La clave privada no se puede abrir%s: %s.',
                $this->keyPassphrase ? ' con la passphrase configurada' : '',
                $keyPath
            ));
        }

        if (!openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new \RuntimeException(sprintf(
                'El certificado %s no corresponde a la clave privada %s.',
                $certPath,
                $keyPath
            ));
        }
    }

    /**
     * @param array<string,string|null> $distinguishedName
     * @return array<string,string>
     */
    private function buildDistinguishedName(string $normalizedCuit, array $distinguishedName): array
    {
        $organizationName = trim((string) ($distinguishedName['organizationName'] ?? ''));
        $commonName = trim((string) ($distinguishedName['commonName'] ?? ''));

        if ($organizationName === '' || $commonName === '') {
            throw new \RuntimeException('Para generar el CSR se requieren organizationName y commonName además del CUIT.');
        }

        return array_filter([
            'countryName' => trim((string) ($distinguishedName['countryName'] ?? 'AR')),
            'stateOrProvinceName' => trim((string) ($distinguishedName['stateOrProvinceName'] ?? '')),
            'localityName' => trim((string) ($distinguishedName['localityName'] ?? '')),
            'organizationName' => $organizationName,
            'organizationalUnitName' => trim((string) ($distinguishedName['organizationalUnitName'] ?? '')),
            'commonName' => $commonName,
            'emailAddress' => trim((string) ($distinguishedName['emailAddress'] ?? '')),
            'serialNumber' => 'CUIT ' . $normalizedCuit,
        ], static fn (string $value): bool => $value !== '');
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
