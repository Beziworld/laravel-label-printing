<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;
use Beziworld\LabelPrinting\Transports\CupsTransport;
use Beziworld\LabelPrinting\Transports\WindowsShareTransport;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

it('pipes the job to lp with the raw option', function (): void {
    $process = new Factory();
    $process->fake();
    $printer = PrinterConfig::fromArray('office', [
        'driver' => 'cups',
        'destination' => 'Zebra_ZD421',
        'host' => 'cups.local:631',
        'options' => ['media' => 'Custom.100x150mm'],
    ]);

    (new CupsTransport($process))->send("^XA^XZ\n", $printer);

    $process->assertRan(fn(PendingProcess $pending): bool => $pending->command === ['lp', '-h', 'cups.local:631', '-d', 'Zebra_ZD421', '-o', 'raw', '-o', 'media=Custom.100x150mm', '-']
        && $pending->input === "^XA^XZ\n");
});

it('reports lp errors as final transport failures', function (): void {
    $process = new Factory();
    $process->fake(fn() => $process->result(errorOutput: 'lp: The printer or class does not exist.', exitCode: 1));

    try {
        (new CupsTransport($process))->send('^XA^XZ', PrinterConfig::fromArray('office', ['driver' => 'cups', 'destination' => 'missing']));
        $this->fail('A transport failure was expected.');
    } catch (TransportFailed $exception) {
        expect($exception->isRetryable())->toBeFalse()
            ->and($exception->exitCode())->toBe(1)
            ->and($exception->errorOutput())->toBe('lp: The printer or class does not exist.');
    }
});

it('treats an unreachable CUPS scheduler as a connection failure', function (): void {
    $process = new Factory();
    $process->fake(fn() => $process->result(errorOutput: 'lp: Unable to connect to server: Connection refused', exitCode: 1));

    (new CupsTransport($process))->send('^XA^XZ', PrinterConfig::fromArray('office', ['driver' => 'cups', 'destination' => 'Zebra']));
})->throws(ConnectionFailed::class);

it('prints through smbclient with the password in the environment', function (): void {
    $process = new Factory();
    $process->fake();
    $printer = PrinterConfig::fromArray('desk', [
        'driver' => 'windows',
        'share' => '\\\\SHOP-PC\\Zebra',
        'username' => 'kasa',
        'password' => 's3cret',
        'workgroup' => 'SKLEP',
    ]);

    (new WindowsShareTransport($process, windowsHost: false))->send('^XA^XZ', $printer);

    $process->assertRan(fn(PendingProcess $pending): bool => $pending->command === ['smbclient', '//SHOP-PC/Zebra', '-U', 'kasa', '-W', 'SKLEP', '-c', 'print -']
        && $pending->input === '^XA^XZ'
        && ($pending->environment['PASSWD'] ?? null) === 's3cret');
});

it('uses anonymous access when no password is configured', function (): void {
    $process = new Factory();
    $process->fake();

    (new WindowsShareTransport($process, windowsHost: false))->send('x', PrinterConfig::fromArray('desk', ['driver' => 'windows', 'share' => '//pc/receipt']));

    $process->assertRan(fn(PendingProcess $pending): bool => $pending->command === ['smbclient', '//pc/receipt', '-N', '-c', 'print -']
        && ! isset($pending->environment['PASSWD']));
});

it('classifies smbclient failures', function (string $output, string $exception): void {
    $process = new Factory();
    $process->fake(fn() => $process->result(output: $output, exitCode: 1));

    expect(fn() => (new WindowsShareTransport($process, false))->send('x', PrinterConfig::fromArray('desk', ['driver' => 'windows', 'share' => '//pc/p'])))
        ->toThrow($exception);
})->with([
    ['do_connect: Connection to pc failed (Error NT_STATUS_HOST_UNREACHABLE)', ConnectionFailed::class],
    ['NT_STATUS_ACCESS_DENIED opening remote file', TransportFailed::class],
]);

it('copies to the UNC path on Windows hosts only', function (): void {
    $transport = new WindowsShareTransport(new Factory(), windowsHost: true);
    $linux = new WindowsShareTransport(new Factory(), windowsHost: false);
    $auto = PrinterConfig::fromArray('desk', ['driver' => 'windows', 'share' => '//pc/p']);
    $forced = PrinterConfig::fromArray('desk', ['driver' => 'windows', 'share' => '//pc/p', 'method' => 'smbclient']);

    expect($transport->usesCopy($auto))->toBeTrue()
        ->and($transport->usesCopy($forced))->toBeFalse()
        ->and($linux->usesCopy($auto))->toBeFalse();
});
