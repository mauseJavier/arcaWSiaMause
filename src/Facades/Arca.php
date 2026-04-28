<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Facades;

use Illuminate\Support\Facades\Facade;

final class Arca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'arca';
    }
}
