<?php

declare(strict_types=1);

namespace Mause\LaravelArca;

use Illuminate\Support\ServiceProvider;
use Mause\LaravelArca\Contracts\ArcaClientInterface;
use Mause\LaravelArca\Modules\Wsfev1;
use Mause\LaravelArca\Modules\WsPadron;
use Mause\LaravelArca\Modules\Wsaa;
use Mause\LaravelArca\Services\ArcaClient;

final class LaravelArcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/arca.php', 'arca');

        $this->app->singleton(ArcaClientInterface::class, function ($app) {
            $config = $app['config']->get('arca', []);

            return new ArcaClient($config);
        });

        $this->app->singleton(Wsaa::class, function ($app) {
            return new Wsaa($app['config']->get('arca', []));
        });

        $this->app->singleton(Wsfev1::class, function ($app) {
            return new Wsfev1(
                $app->make(Wsaa::class),
                $app['config']->get('arca', [])
            );
        });

        $this->app->singleton(WsPadron::class, function ($app) {
            return new WsPadron(
                $app->make(Wsaa::class),
                $app['config']->get('arca', [])
            );
        });

        $this->app->alias(ArcaClientInterface::class, 'arca');
        $this->app->alias(Wsaa::class, 'arca.wsaa');
        $this->app->alias(Wsfev1::class, 'arca.wsfev1');
        $this->app->alias(WsPadron::class, 'arca.ws-padron');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/arca.php' => config_path('arca.php'),
        ], 'arca-config');
    }
}
