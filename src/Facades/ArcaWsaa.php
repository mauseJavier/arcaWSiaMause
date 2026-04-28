<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Facades;

use Illuminate\Support\Facades\Facade;
use Mause\LaravelArca\Modules\Wsaa;

/**
 * Facade para WSAA.
 *
 * @method static string generateTra(string $wsn = 'wsfe')
 * @method static array{key_path: string, csr_path: string, key: string, csr: string} createCertificateRequest(string|int $companyCuit, array $distinguishedName, ?string $passphrase = null, int $privateKeyBits = 2048)
 * @method static string|null signTra(string $tra, string|int|null $companyCuit = null)
 * @method static array{token: string, sign: string, expires_at: string}|null requestTa(string|int $companyCuit, string $wsn = 'wsfe')
 */
final class ArcaWsaa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'arca.wsaa';
    }
}
