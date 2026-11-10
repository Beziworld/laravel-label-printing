<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Exceptions;

use RuntimeException;

/**
 * Base exception for every error raised by the label printing integration.
 */
class LabelPrintingException extends RuntimeException
{
    /**
     * Whether repeating the same job later may succeed without duplicating output.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
