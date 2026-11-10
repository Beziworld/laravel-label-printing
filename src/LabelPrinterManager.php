<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting;

use Beziworld\LabelPrinting\Data\PrintResult;
use Beziworld\LabelPrinting\Enums\Driver;
use Beziworld\LabelPrinting\EscPos\Receipt;
use Beziworld\LabelPrinting\Transports\CupsTransport;
use Beziworld\LabelPrinting\Transports\FakeTransport;
use Beziworld\LabelPrinting\Transports\FileTransport;
use Beziworld\LabelPrinting\Transports\NetworkTransport;
use Beziworld\LabelPrinting\Transports\NullTransport;
use Beziworld\LabelPrinting\Transports\Transport;
use Beziworld\LabelPrinting\Transports\WindowsShareTransport;
use Beziworld\LabelPrinting\Zpl\ZplLabel;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Resolves named printers from configuration and runtime definitions.
 */
final class LabelPrinterManager
{
    /** @var array<string, Printer> */
    private array $printers = [];

    private ?FakeTransport $fake = null;

    public function __construct(
        private readonly LabelPrintingConfig $config,
        private readonly Dispatcher $events,
        private readonly BusDispatcher $bus,
        private readonly ProcessFactory $process,
    ) {}

    /**
     * Printer defined in config/label-printing.php; null selects the default printer.
     */
    public function printer(?string $name = null): Printer
    {
        $printer = $this->config->printer($name);

        return $this->printers[$printer->name] ??= $this->build($printer, false);
    }

    /**
     * Printer defined at runtime, for example from a database record.
     *
     * @param array<array-key, mixed> $config Same keys as an entry of the "printers" configuration.
     */
    public function using(array $config, string $name = 'runtime'): Printer
    {
        return $this->build(PrinterConfig::fromArray($name, $config), true);
    }

    /**
     * Replace every transport with an in-memory fake that records the bytes.
     */
    public function fake(): FakeTransport
    {
        $this->printers = [];

        return $this->fake = new FakeTransport();
    }

    public function isFake(): bool
    {
        return $this->fake !== null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->config->names();
    }

    public function config(): LabelPrintingConfig
    {
        return $this->config;
    }

    public function label(): ZplLabel
    {
        return $this->printer()->label();
    }

    public function receipt(): Receipt
    {
        return new Receipt();
    }

    public function zpl(string|ZplLabel $zpl, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->printer()->zpl($zpl, $copies, $reference);
    }

    public function escpos(Receipt|string $receipt, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->printer()->escpos($receipt, $copies, $reference);
    }

    public function queue(?string $connection = null, ?string $queue = null): QueuedPrinter
    {
        return $this->printer()->queue($connection, $queue);
    }

    public function transportFor(PrinterConfig $printer): Transport
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return match ($printer->driver) {
            Driver::Network => new NetworkTransport(),
            Driver::Cups => new CupsTransport($this->process),
            Driver::Windows => new WindowsShareTransport($this->process),
            Driver::File => new FileTransport(),
            Driver::Null => new NullTransport(),
        };
    }

    private function build(PrinterConfig $printer, bool $runtime): Printer
    {
        return new Printer($printer, $this->transportFor($printer), $this->events, $this->bus, $this->config, $runtime);
    }
}
