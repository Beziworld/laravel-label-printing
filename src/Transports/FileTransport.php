<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;

/**
 * Writes jobs to a file or a device node such as /dev/usb/lp0.
 *
 * With "append" enabled every job is added to the end of the file, which
 * turns the driver into a simple capture log for local development.
 */
final class FileTransport implements Transport
{
    public function send(string $bytes, PrinterConfig $printer): void
    {
        $path = (string) $printer->path;
        $directory = dirname($path);

        if (! str_starts_with($path, '/dev/') && ! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw TransportFailed::fileNotWritable($printer->name, $path);
        }

        $written = @file_put_contents($path, $bytes, $printer->append ? FILE_APPEND | LOCK_EX : LOCK_EX);

        if ($written === false) {
            throw TransportFailed::fileNotWritable($printer->name, $path);
        }

        if ($written !== strlen($bytes)) {
            throw TransportFailed::writeFailed($printer->name, $written, strlen($bytes));
        }
    }
}
