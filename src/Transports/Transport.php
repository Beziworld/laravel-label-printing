<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;

/**
 * Delivers raw bytes to a printer.
 */
interface Transport
{
    /**
     * @throws ConnectionFailed When the printer cannot be reached and nothing was sent.
     * @throws TransportFailed When delivery failed after it started or the spooler rejected the job.
     */
    public function send(string $bytes, PrinterConfig $printer): void;
}
