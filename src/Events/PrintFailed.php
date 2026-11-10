<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Events;

use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\Exceptions\LabelPrintingException;

/**
 * Dispatched whenever delivering a job fails.
 *
 * "retryable" is true only for connection failures, which a queued
 * PrintJob retries; any other failure is final.
 */
final readonly class PrintFailed
{
    public function __construct(
        public string $printer,
        public Language $language,
        public int $bytes,
        public LabelPrintingException $exception,
        public bool $retryable,
        public ?string $reference = null,
    ) {}
}
