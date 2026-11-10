<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Prints to a printer shared by a Windows host (\\HOST\Printer).
 *
 * On Windows the bytes are written straight to the UNC path, which spools
 * them as a raw job when the share uses a "Generic / Text Only" or raw driver.
 * Elsewhere the job is piped through "smbclient //HOST/Printer -c 'print -'";
 * the password travels in the PASSWD environment variable so it never appears
 * in the process list.
 */
final class WindowsShareTransport implements Transport
{
    private const string UNREACHABLE = '/connection to .* failed|NT_STATUS_(HOST_UNREACHABLE|CONNECTION_REFUSED|IO_TIMEOUT|NETWORK_UNREACHABLE|BAD_NETWORK_NAME)/i';

    public function __construct(
        private readonly ProcessFactory $process,
        private readonly ?bool $windowsHost = null,
    ) {}

    public function send(string $bytes, PrinterConfig $printer): void
    {
        $this->usesCopy($printer) ? $this->copy($bytes, $printer) : $this->smbclient($bytes, $printer);
    }

    public function usesCopy(PrinterConfig $printer): bool
    {
        return match ($printer->windowsMethod) {
            'copy' => true,
            'smbclient' => false,
            default => $this->windowsHost ?? PHP_OS_FAMILY === 'Windows',
        };
    }

    /**
     * @return list<string>
     */
    public function command(PrinterConfig $printer): array
    {
        $command = [$printer->smbclientBinary, '//' . str_replace('\\', '/', ltrim((string) $printer->share, '\\/'))];

        if ($printer->username !== null) {
            $command[] = '-U';
            $command[] = $printer->username;
        }

        if ($printer->workgroup !== null) {
            $command[] = '-W';
            $command[] = $printer->workgroup;
        }

        if ($printer->password === null) {
            $command[] = '-N';
        }

        $command[] = '-c';
        $command[] = 'print -';

        return $command;
    }

    private function copy(string $bytes, PrinterConfig $printer): void
    {
        $share = (string) $printer->share;
        $handle = @fopen($share, 'wb');

        if ($handle === false) {
            throw ConnectionFailed::to($printer->name, $share, error_get_last()['message'] ?? 'the share could not be opened');
        }

        try {
            $written = @fwrite($handle, $bytes);

            if ($written !== strlen($bytes)) {
                throw TransportFailed::writeFailed($printer->name, $written === false ? 0 : $written, strlen($bytes));
            }
        } finally {
            @fclose($handle);
        }
    }

    private function smbclient(string $bytes, PrinterConfig $printer): void
    {
        $pending = $this->process
            ->newPendingProcess()
            ->timeout(max(1, (int) ceil($printer->timeout * 6)))
            ->input($bytes);

        if ($printer->password !== null) {
            $pending = $pending->env(['PASSWD' => $printer->password]);
        }

        try {
            $result = $pending->run($this->command($printer));
        } catch (\Throwable $exception) {
            throw TransportFailed::commandFailed($printer->name, $printer->smbclientBinary, -1, $exception->getMessage());
        }

        if ($result->successful()) {
            return;
        }

        $output = $result->errorOutput() . "\n" . $result->output();

        if (preg_match(self::UNREACHABLE, $output) === 1) {
            throw ConnectionFailed::to($printer->name, (string) $printer->share, trim($output), (int) $result->exitCode());
        }

        throw TransportFailed::commandFailed($printer->name, $printer->smbclientBinary, (int) $result->exitCode(), $output);
    }
}
