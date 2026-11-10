<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Data;

use Beziworld\LabelPrinting\Enums\Language;

/**
 * Outcome of a delivered print job.
 *
 * Raw printer protocols do not acknowledge jobs, so a result means the
 * transport accepted every byte, not that the printer finished printing.
 */
final readonly class PrintResult
{
    public function __construct(
        public string $printer,
        public string $endpoint,
        public Language $language,
        public int $bytes,
        public int $copies,
        public float $durationMs,
        public ?string $reference = null,
    ) {}
}
