<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Exceptions;

/**
 * Raised when the printer configuration is incomplete or inconsistent.
 */
final class InvalidConfiguration extends LabelPrintingException
{
    public static function missing(string $key, ?string $env = null): self
    {
        return new self($env === null
            ? sprintf('Label printing "%s" is not configured.', $key)
            : sprintf('Label printing "%s" is not configured. Set %s in the environment.', $key, $env));
    }

    public static function unknownPrinter(string $name): self
    {
        return new self(sprintf('Printer "%s" is not defined in config/label-printing.php.', $name));
    }

    public static function noDefaultPrinter(): self
    {
        return new self('No default printer is configured. Set LABEL_PRINTER or pass a printer name.');
    }

    public static function invalid(string $key, string $reason): self
    {
        return new self(sprintf('Label printing "%s" is invalid: %s', $key, $reason));
    }
}
