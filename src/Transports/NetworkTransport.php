<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;

/**
 * Raw TCP printing (JetDirect / AppSocket), usually on port 9100.
 *
 * The protocol has no acknowledgement: the job is considered delivered once
 * every byte has been written and the socket has been closed cleanly.
 */
final class NetworkTransport implements Transport
{
    private const int CHUNK = 8192;

    public function send(string $bytes, PrinterConfig $printer): void
    {
        $endpoint = sprintf('tcp://%s:%d', $this->formatHost((string) $printer->host), $printer->port);
        $errorCode = 0;
        $errorMessage = '';

        $socket = @stream_socket_client($endpoint, $errorCode, $errorMessage, $printer->timeout, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            throw ConnectionFailed::to($printer->name, $endpoint, $errorMessage ?? '', $errorCode ?? 0);
        }

        $seconds = (int) floor($printer->timeout);
        stream_set_timeout($socket, $seconds, (int) (($printer->timeout - $seconds) * 1_000_000));

        $total = strlen($bytes);
        $written = 0;

        try {
            while ($written < $total) {
                $result = @fwrite($socket, substr($bytes, $written, self::CHUNK));

                if ($result === false || $result === 0) {
                    throw TransportFailed::writeFailed($printer->name, $written, $total);
                }

                $written += $result;
            }

            @fflush($socket);
        } finally {
            @fclose($socket);
        }
    }

    private function formatHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    }
}
