<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null consultarPadron(string|int $companyCuit, string|int $identifier)
 * @method static array consultarPorDni(string|int $companyCuit, string|int $numeroDni)
 * @method static array consultarPersona(string|int $companyCuit, string|int $cuitOCuil, string $personType = 'cuit')
 *
 * @see \Mause\LaravelArca\Modules\WsPadron
 */
final class ArcaWsPadron extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'arca.ws-padron';
    }
}
