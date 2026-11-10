<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting;

use Beziworld\LabelPrinting\Console\TestPageCommand;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the printer manager, the facade accessor and the test page command.
 */
final class LabelPrintingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/label-printing.php', 'label-printing');

        $this->app->singleton(LabelPrintingConfig::class, static function (Application $app): LabelPrintingConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make(Config::class)->get('label-printing', []);

            return LabelPrintingConfig::fromArray($config);
        });

        $this->app->singleton(LabelPrinterManager::class, static fn(Application $app): LabelPrinterManager => new LabelPrinterManager(
            $app->make(LabelPrintingConfig::class),
            $app->make(Dispatcher::class),
            $app->make(BusDispatcher::class),
            $app->make(ProcessFactory::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/label-printing.php' => $this->app->configPath('label-printing.php')], 'label-printing-config');

            $this->commands([TestPageCommand::class]);
        }
    }
}
