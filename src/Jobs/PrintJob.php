<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Jobs;

use Beziworld\LabelPrinting\Data\PrintPayload;
use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\LabelPrintingException;
use Beziworld\LabelPrinting\LabelPrinterManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued print job.
 *
 * Only connection failures (nothing reached the printer) are retried, using
 * the configured tries and backoff. Any other failure, including a write
 * that broke off mid-job, fails the job at once so no label is printed twice.
 */
final class PrintJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param array{bytes: string, language: string, copies: int, reference: string|null} $payload Bytes are base64 encoded.
     * @param array<string, mixed>|null $printerConfig Definition of a runtime printer created with using().
     * @param list<int> $backoffSeconds
     */
    public function __construct(
        public readonly string $printer,
        public readonly array $payload,
        public readonly ?array $printerConfig = null,
        public int $tries = 5,
        public readonly array $backoffSeconds = [5, 15, 60],
    ) {}

    public function handle(LabelPrinterManager $manager): void
    {
        $printer = $this->printerConfig !== null
            ? $manager->using($this->printerConfig, $this->printer)
            : $manager->printer($this->printer);

        try {
            $printer->send(PrintPayload::fromArray($this->payload));
        } catch (ConnectionFailed $exception) {
            throw $exception;
        } catch (LabelPrintingException $exception) {
            $this->fail($exception);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->backoffSeconds;
    }

    public function payload(): PrintPayload
    {
        return PrintPayload::fromArray($this->payload);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = ['label-printer:' . $this->printer];

        if ($this->payload['reference'] !== null) {
            $tags[] = 'print-reference:' . $this->payload['reference'];
        }

        return $tags;
    }
}
