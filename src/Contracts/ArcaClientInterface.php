<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Contracts;

interface ArcaClientInterface
{
    /**
     * Basic health check for wiring validation.
     */
    public function ping(): array;

    /**
     * Returns integration mode based on config.
     */
    public function mode(): string;
}
