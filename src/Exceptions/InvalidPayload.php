<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Exceptions;

/**
 * Raised when label or receipt content is malformed before anything is sent.
 */
final class InvalidPayload extends LabelPrintingException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
