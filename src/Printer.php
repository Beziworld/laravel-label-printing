<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting;

use Beziworld\LabelPrinting\Data\PrintPayload;
use Beziworld\LabelPrinting\Data\PrintResult;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\EscPos\Receipt;
use Beziworld\LabelPrinting\EscPos\RenderOptions;
use Beziworld\LabelPrinting\Events\PrintFailed;
use Beziworld\LabelPrinting\Events\PrintSucceeded;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Exceptions\LabelPrintingException;
use Beziworld\LabelPrinting\Transports\Transport;
use Beziworld\LabelPrinting\Zpl\ZplLabel;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A named printer: builds payloads for its language and sends them synchronously.
 */
final class Printer
{
    public function __construct(
        private readonly PrinterConfig $config,
        private readonly Transport $transport,
        private readonly Dispatcher $events,
        private readonly BusDispatcher $bus,
        private readonly LabelPrintingConfig $settings,
        private readonly bool $runtime = false,
    ) {}

    public function name(): string
    {
        return $this->config->name;
    }

    public function config(): PrinterConfig
    {
        return $this->config;
    }

    /**
     * Whether the printer was defined at runtime through LabelPrinter::using().
     */
    public function isRuntime(): bool
    {
        return $this->runtime;
    }

    /**
     * New ZPL label pre-sized from the printer's label_width, label_length and dpi.
     */
    public function label(): ZplLabel
    {
        return new ZplLabel($this->config->labelWidth, $this->config->labelLength, $this->config->dpi);
    }

    public function receipt(): Receipt
    {
        return new Receipt();
    }

    /**
     * Print ZPL: a builder, or raw ZPL such as a label returned by a courier API.
     */
    public function zpl(string|ZplLabel $zpl, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->send(PrintPayload::zpl((string) $zpl, $copies, $reference));
    }

    public function zplBase64(string $base64, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->send(PrintPayload::zplBase64($base64, $copies, $reference));
    }

    public function epl(string $epl, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->send(PrintPayload::epl($epl, $copies, $reference));
    }

    public function eplBase64(string $base64, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->send(PrintPayload::eplBase64($base64, $copies, $reference));
    }

    /**
     * Print an ESC/POS receipt rendered with this printer's code page and profile.
     */
    public function escpos(Receipt|string $receipt, int $copies = 1, ?string $reference = null): PrintResult
    {
        return $this->send($this->escposPayload($receipt, $copies, $reference));
    }

    /**
     * Send bytes without validation.
     */
    public function raw(string $bytes, int $copies = 1, ?string $reference = null, Language $language = Language::Raw): PrintResult
    {
        return $this->send(PrintPayload::raw($bytes, $language, $copies, $reference));
    }

    /**
     * Queue jobs for this printer instead of printing them immediately.
     */
    public function queue(?string $connection = null, ?string $queue = null): QueuedPrinter
    {
        return new QueuedPrinter(
            $this,
            $this->bus,
            $connection ?? $this->settings->queueConnection,
            $queue ?? $this->settings->queueName,
            $this->settings->tries,
            $this->settings->backoff,
        );
    }

    public function escposPayload(Receipt|string $receipt, int $copies = 1, ?string $reference = null): PrintPayload
    {
        $bytes = $receipt instanceof Receipt ? $receipt->render(RenderOptions::forPrinter($this->config)) : $receipt;

        return PrintPayload::raw($bytes, Language::EscPos, $copies, $reference);
    }

    /**
     * Deliver a payload and dispatch PrintSucceeded or PrintFailed.
     *
     * @throws LabelPrintingException
     */
    public function send(PrintPayload $payload): PrintResult
    {
        $this->assertLanguage($payload->language);

        $bytes = $payload->output();
        $started = hrtime(true);

        try {
            $this->transport->send($bytes, $this->config);
        } catch (LabelPrintingException $exception) {
            $this->events->dispatch(new PrintFailed(
                $this->config->name,
                $payload->language,
                strlen($bytes),
                $exception,
                $exception->isRetryable(),
                $payload->reference,
            ));

            throw $exception;
        }

        $result = new PrintResult(
            $this->config->name,
            $this->config->endpoint(),
            $payload->language,
            strlen($bytes),
            $payload->copies,
            round((hrtime(true) - $started) / 1_000_000, 3),
            $payload->reference,
        );

        $this->events->dispatch(new PrintSucceeded($result));

        return $result;
    }

    /**
     * Refuse payloads the printer cannot interpret, so a receipt never ends up as garbage on a label printer.
     */
    private function assertLanguage(Language $language): void
    {
        $printer = $this->config->language;

        $labelLanguages = [Language::Zpl, Language::Epl];
        $mismatch = ($printer === Language::EscPos && in_array($language, $labelLanguages, true))
            || (in_array($printer, $labelLanguages, true) && $language === Language::EscPos);

        if ($mismatch) {
            throw InvalidPayload::because(sprintf(
                'Printer "%s" speaks %s and cannot print a %s payload.',
                $this->config->name,
                strtoupper($printer->value),
                strtoupper($language->value),
            ));
        }
    }
}
