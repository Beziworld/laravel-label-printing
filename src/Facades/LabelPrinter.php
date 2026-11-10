<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Facades;

use Beziworld\LabelPrinting\LabelPrinterManager;
use Beziworld\LabelPrinting\Transports\FakeTransport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\LabelPrinting\Printer printer(?string $name = null)
 * @method static \Beziworld\LabelPrinting\Printer using(array<array-key, mixed> $config, string $name = 'runtime')
 * @method static bool isFake()
 * @method static list<string> names()
 * @method static \Beziworld\LabelPrinting\LabelPrintingConfig config()
 * @method static \Beziworld\LabelPrinting\Zpl\ZplLabel label()
 * @method static \Beziworld\LabelPrinting\EscPos\Receipt receipt()
 * @method static \Beziworld\LabelPrinting\Data\PrintResult zpl(string|\Beziworld\LabelPrinting\Zpl\ZplLabel $zpl, int $copies = 1, ?string $reference = null)
 * @method static \Beziworld\LabelPrinting\Data\PrintResult escpos(\Beziworld\LabelPrinting\EscPos\Receipt|string $receipt, int $copies = 1, ?string $reference = null)
 * @method static \Beziworld\LabelPrinting\QueuedPrinter queue(?string $connection = null, ?string $queue = null)
 * @method static \Beziworld\LabelPrinting\Transports\Transport transportFor(\Beziworld\LabelPrinting\PrinterConfig $printer)
 *
 * @see LabelPrinterManager
 */
final class LabelPrinter extends Facade
{
    /**
     * Swap every printer transport for an in-memory fake and return it for assertions.
     */
    public static function fake(): FakeTransport
    {
        /** @var LabelPrinterManager $manager */
        $manager = static::getFacadeRoot();

        return $manager->fake();
    }

    protected static function getFacadeAccessor(): string
    {
        return LabelPrinterManager::class;
    }
}
