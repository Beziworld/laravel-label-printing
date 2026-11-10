<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Events;

use Beziworld\LabelPrinting\Data\PrintResult;

/**
 * Dispatched after a transport accepted every byte of a job.
 */
final readonly class PrintSucceeded
{
    public function __construct(public PrintResult $result) {}
}
