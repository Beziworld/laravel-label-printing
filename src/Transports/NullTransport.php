<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\PrinterConfig;

/**
 * Accepts and discards every job.
 */
final class NullTransport implements Transport
{
    public function send(string $bytes, PrinterConfig $printer): void {}
}
