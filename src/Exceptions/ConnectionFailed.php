<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Exceptions;

/**
 * Raised when the printer cannot be reached before any byte has been sent.
 *
 * Nothing reached the printer, so a queued job may safely retry it.
 */
final class ConnectionFailed extends LabelPrintingException
{
    public function __construct(
        string $message,
        private readonly string $printer,
        private readonly ?string $endpoint = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }

    public static function to(string $printer, string $endpoint, string $reason, int $code = 0): self
    {
        return new self(
            sprintf('Could not connect to printer "%s" at %s: %s', $printer, $endpoint, $reason !== '' ? $reason : 'unknown error'),
            $printer,
            $endpoint,
            $code,
        );
    }

    public function printer(): string
    {
        return $this->printer;
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
