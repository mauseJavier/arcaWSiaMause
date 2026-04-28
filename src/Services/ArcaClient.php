<?php

declare(strict_types=1);

namespace Mause\LaravelArca\Services;

use Mause\LaravelArca\Contracts\ArcaClientInterface;

final class ArcaClient implements ArcaClientInterface
{
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function ping(): array
    {
        return [
            'package' => 'mause/laravel-arca',
            'mode' => $this->mode(),
            'wsaa_homologacion' => (string) ($this->config['wsaa']['homologation_url'] ?? ''),
            'wsaa_produccion' => (string) ($this->config['wsaa']['production_url'] ?? ''),
        ];
    }

    public function mode(): string
    {
        $envMode = (string) ($this->config['mode'] ?? 'homologation');

        return $envMode === 'production' ? 'production' : 'homologation';
    }
}
