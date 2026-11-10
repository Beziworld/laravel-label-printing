<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\PrinterConfig;
use Beziworld\LabelPrinting\Transports\FileTransport;
use Beziworld\LabelPrinting\Transports\NullTransport;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'label-printing-' . bin2hex(random_bytes(4));
});

afterEach(function (): void {
    foreach (glob($this->directory . '/*') ?: [] as $file) {
        unlink($file);
    }

    if (is_dir($this->directory)) {
        rmdir($this->directory);
    }
});

it('overwrites the target file by default and creates its directory', function (): void {
    $path = $this->directory . '/label.prn';
    $printer = PrinterConfig::fromArray('capture', ['driver' => 'file', 'path' => $path]);

    (new FileTransport())->send('first', $printer);
    (new FileTransport())->send("\x1B@second", $printer);

    expect(file_get_contents($path))->toBe("\x1B@second");
});

it('appends jobs when configured as a capture log', function (): void {
    $path = $this->directory . '/capture.prn';
    $printer = PrinterConfig::fromArray('capture', ['driver' => 'file', 'path' => $path, 'append' => true]);

    (new FileTransport())->send('^XA^XZ', $printer);
    (new FileTransport())->send('^XA^XZ', $printer);

    expect(file_get_contents($path))->toBe('^XA^XZ^XA^XZ');
});

it('fails when the path cannot be written', function (): void {
    mkdir($this->directory);
    $printer = PrinterConfig::fromArray('capture', ['driver' => 'file', 'path' => $this->directory]);

    (new FileTransport())->send('x', $printer);
})->throws(TransportFailed::class, 'could not write');

it('discards jobs with the null transport', function (): void {
    (new NullTransport())->send('x', PrinterConfig::fromArray('null', ['driver' => 'null']));

    expect(true)->toBeTrue();
});
