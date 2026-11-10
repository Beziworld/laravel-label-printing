<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\PrinterConfig;
use Beziworld\LabelPrinting\Transports\NetworkTransport;

/**
 * Local TCP listener standing in for a printer on port 9100.
 *
 * @return array{0: resource, 1: int}
 */
function localListener(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($server === false) {
        throw new RuntimeException($errorMessage);
    }

    $name = (string) stream_socket_get_name($server, false);

    return [$server, (int) substr($name, (int) strrpos($name, ':') + 1)];
}

it('writes every byte to the raw TCP socket', function (): void {
    [$server, $port] = localListener();
    $bytes = "^XA\n^FDZażółć^FS\n^XZ\n" . str_repeat("\x00\xFF", 10_000);

    (new NetworkTransport())->send($bytes, new PrinterConfig('local', Beziworld\LabelPrinting\Enums\Driver::Network, host: '127.0.0.1', port: $port, timeout: 2));

    $client = stream_socket_accept($server, 2);
    expect($client)->not->toBeFalse();

    $received = '';
    while (! feof($client)) {
        $received .= (string) fread($client, 8192);
    }

    fclose($client);
    fclose($server);

    expect($received)->toBe($bytes);
});

it('raises a retryable connection failure when nothing listens', function (): void {
    [$server, $port] = localListener();
    fclose($server);

    try {
        (new NetworkTransport())->send('^XA^XZ', new PrinterConfig('local', Beziworld\LabelPrinting\Enums\Driver::Network, host: '127.0.0.1', port: $port, timeout: 2));
        $this->fail('The connection should have been refused.');
    } catch (ConnectionFailed $exception) {
        expect($exception->isRetryable())->toBeTrue()
            ->and($exception->printer())->toBe('local')
            ->and($exception->endpoint())->toBe('tcp://127.0.0.1:' . $port)
            ->and($exception->getMessage())->toContain('Could not connect to printer "local"');
    }
});
