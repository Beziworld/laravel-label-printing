<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting;

use Beziworld\LabelPrinting\Data\PrintPayload;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\EscPos\Receipt;
use Beziworld\LabelPrinting\Jobs\PrintJob;
use Beziworld\LabelPrinting\Zpl\ZplLabel;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;

/**
 * Dispatches PrintJob instances for a printer.
 *
 * Payloads are rendered before dispatch, so the queue stores the exact bytes
 * that will be printed and the worker needs no builder state.
 */
final class QueuedPrinter
{
    /**
     * @param list<int> $backoff
     */
    public function __construct(
        private readonly Printer $printer,
        private readonly BusDispatcher $bus,
        private readonly ?string $connection,
        private readonly ?string $queue,
        private readonly int $tries,
        private readonly array $backoff,
    ) {}

    public function zpl(string|ZplLabel $zpl, int $copies = 1, ?string $reference = null): PrintJob
    {
        return $this->send(PrintPayload::zpl((string) $zpl, $copies, $reference));
    }

    public function zplBase64(string $base64, int $copies = 1, ?string $reference = null): PrintJob
    {
        return $this->send(PrintPayload::zplBase64($base64, $copies, $reference));
    }

    public function epl(string $epl, int $copies = 1, ?string $reference = null): PrintJob
    {
        return $this->send(PrintPayload::epl($epl, $copies, $reference));
    }

    public function eplBase64(string $base64, int $copies = 1, ?string $reference = null): PrintJob
    {
        return $this->send(PrintPayload::eplBase64($base64, $copies, $reference));
    }

    public function escpos(Receipt|string $receipt, int $copies = 1, ?string $reference = null): PrintJob
    {
        return $this->send($this->printer->escposPayload($receipt, $copies, $reference));
    }

    public function raw(string $bytes, int $copies = 1, ?string $reference = null, Language $language = Language::Raw): PrintJob
    {
        return $this->send(PrintPayload::raw($bytes, $language, $copies, $reference));
    }

    public function send(PrintPayload $payload): PrintJob
    {
        $job = new PrintJob(
            $this->printer->name(),
            $payload->toArray(),
            $this->printer->isRuntime() ? $this->printer->config()->toArray() : null,
            $this->tries,
            $this->backoff,
        );

        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        $this->bus->dispatch($job);

        return $job;
    }
}
