<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Exceptions;

/**
 * Raised when delivery fails after it started, or when a spooler command reports an error.
 *
 * The printer may already have received part of the job, so the failure is
 * never retried automatically: a retry could print the same label twice.
 */
final class TransportFailed extends LabelPrintingException
{
    public function __construct(
        string $message,
        private readonly string $printer,
        private readonly ?int $exitCode = null,
        private readonly ?string $errorOutput = null,
    ) {
        parent::__construct($message, $exitCode ?? 0);
    }

    public static function writeFailed(string $printer, int $written, int $total): self
    {
        return new self(sprintf('Printer "%s" accepted only %d of %d bytes.', $printer, $written, $total), $printer);
    }

    public static function commandFailed(string $printer, string $command, int $exitCode, string $errorOutput): self
    {
        $error = trim($errorOutput);

        return new self(
            sprintf('Printing to "%s" with %s failed (exit code %d)%s', $printer, $command, $exitCode, $error !== '' ? ': ' . $error : '.'),
            $printer,
            $exitCode,
            $error,
        );
    }

    public static function fileNotWritable(string $printer, string $path): self
    {
        return new self(sprintf('Printer "%s" could not write to %s.', $printer, $path), $printer);
    }

    public function printer(): string
    {
        return $this->printer;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function errorOutput(): ?string
    {
        return $this->errorOutput;
    }
}
