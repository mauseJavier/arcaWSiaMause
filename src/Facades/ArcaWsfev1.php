<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Facades;

use Illuminate\Support\Facades\Facade;
use Mause\LaravelArca\Modules\Wsfev1;

/**
 * Facade para WSFEv1.
 *
 * @method static array|null getLastAuthorizedNumber(string|int $companyCuit, int $ptoVta, int $cbteType)
 * @method static array<array{id: int, name: string}>|null getInvoiceTypes(string|int $companyCuit)
 * @method static array|null requestCae(string|int $companyCuit, array $invoice)
 */
final class ArcaWsfev1 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'arca.wsfev1';
    }
}
