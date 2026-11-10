<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Submits jobs to a CUPS queue with "lp -d <destination> -o raw -".
 *
 * The data is piped through standard input ("-" as the file name), so no
 * temporary file is written. The "raw" option tells CUPS not to filter the
 * job, which is required for ZPL, EPL and ESC/POS.
 */
final class CupsTransport implements Transport
{
    /**
     * Error output fragments meaning that the scheduler could not be reached at all.
     */
    private const string UNREACHABLE = '/unable to connect|scheduler is not running|connection refused|no route to host/i';

    public function __construct(private readonly ProcessFactory $process) {}

    /**
     * @return list<string>
     */
    public function command(PrinterConfig $printer): array
    {
        $command = [$printer->lpBinary];

        if ($printer->host !== null) {
            $command[] = '-h';
            $command[] = $printer->host;
        }

        $command[] = '-d';
        $command[] = (string) $printer->destination;

        foreach (['raw' => null] + $printer->cupsOptions as $name => $value) {
            $command[] = '-o';
            $command[] = $value === null ? $name : $name . '=' . $value;
        }

        $command[] = '-';

        return $command;
    }

    public function send(string $bytes, PrinterConfig $printer): void
    {
        try {
            $result = $this->process
                ->newPendingProcess()
                ->timeout(max(1, (int) ceil($printer->timeout * 6)))
                ->input($bytes)
                ->run($this->command($printer));
        } catch (\Throwable $exception) {
            throw TransportFailed::commandFailed($printer->name, $printer->lpBinary, -1, $exception->getMessage());
        }

        if ($result->successful()) {
            return;
        }

        if (preg_match(self::UNREACHABLE, $result->errorOutput()) === 1) {
            throw ConnectionFailed::to($printer->name, $printer->endpoint(), trim($result->errorOutput()), (int) $result->exitCode());
        }

        throw TransportFailed::commandFailed($printer->name, $printer->lpBinary, (int) $result->exitCode(), $result->errorOutput());
    }
}
