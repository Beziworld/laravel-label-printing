<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Tests;

use Beziworld\LabelPrinting\LabelPrintingServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [LabelPrintingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('label-printing.default', 'warehouse');
        $app['config']->set('label-printing.printers', [
            // 192.0.2.0/24 is TEST-NET-1 (RFC 5737); tests fake every transport that would reach it.
            'warehouse' => ['driver' => 'network', 'language' => 'zpl', 'host' => '192.0.2.10', 'label_width' => 812, 'label_length' => 1218],
            'receipt' => ['driver' => 'network', 'language' => 'escpos', 'host' => '192.0.2.11', 'code_page' => 'cp852', 'columns' => 32],
            'office' => ['driver' => 'cups', 'language' => 'zpl', 'destination' => 'Zebra_ZD421'],
            'epl' => ['driver' => 'null', 'language' => 'epl'],
            'null' => ['driver' => 'null', 'language' => 'raw'],
        ]);
        $app['config']->set('label-printing.queue', ['connection' => null, 'queue' => 'printing', 'tries' => 4, 'backoff' => [2, 10]]);
    }
}
