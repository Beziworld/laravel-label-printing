<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Enums\Driver;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\EscPos\CodePage;
use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;
use Beziworld\LabelPrinting\LabelPrintingConfig;
use Beziworld\LabelPrinting\PrinterConfig;

it('applies network defaults', function (): void {
    $printer = PrinterConfig::fromArray('warehouse', ['driver' => 'network', 'host' => '10.0.0.5']);

    expect($printer->driver)->toBe(Driver::Network)
        ->and($printer->language)->toBe(Language::Zpl)
        ->and($printer->port)->toBe(9100)
        ->and($printer->timeout)->toBe(5.0)
        ->and($printer->codePage)->toBe(CodePage::Cp852)
        ->and($printer->characterTable())->toBe(18)
        ->and($printer->endpoint())->toBe('tcp://10.0.0.5:9100');
});

it('requires the settings of each driver', function (array $config, string $key): void {
    expect(fn() => PrinterConfig::fromArray('p', $config))->toThrow(InvalidConfiguration::class, $key);
})->with([
    [[], 'printers.p.driver'],
    [['driver' => 'network'], 'printers.p.host'],
    [['driver' => 'cups'], 'printers.p.destination'],
    [['driver' => 'windows'], 'printers.p.share'],
    [['driver' => 'file'], 'printers.p.path'],
]);

it('rejects unknown drivers, languages and out of range ports', function (array $config): void {
    PrinterConfig::fromArray('p', $config + ['host' => 'h']);
})->with([
    [['driver' => 'usb']],
    [['driver' => 'network', 'language' => 'pdf']],
    [['driver' => 'network', 'port' => 70000]],
    [['driver' => 'network', 'timeout' => 0]],
])->throws(InvalidConfiguration::class);

it('normalises language spelling and CUPS options', function (): void {
    $printer = PrinterConfig::fromArray('p', [
        'driver' => 'cups',
        'queue' => 'Zebra',
        'language' => 'ESC/POS',
        'options' => ['media=Custom.100x150mm', 'job-priority' => 80, 'fit-to-page' => false],
    ]);

    expect($printer->language)->toBe(Language::EscPos)
        ->and($printer->destination)->toBe('Zebra')
        ->and($printer->cupsOptions)->toBe(['media=Custom.100x150mm' => null, 'job-priority' => '80', 'fit-to-page' => 'false']);
});

it('validates Windows share paths', function (): void {
    expect(PrinterConfig::fromArray('p', ['driver' => 'windows', 'share' => '\\\\SHOP-PC\\Zebra'])->share)->toBe('\\\\SHOP-PC\\Zebra')
        ->and(PrinterConfig::fromArray('p', ['driver' => 'windows', 'share' => '//shop-pc/Zebra'])->share)->toBe('//shop-pc/Zebra')
        ->and(fn() => PrinterConfig::fromArray('p', ['driver' => 'windows', 'share' => 'C:\\Zebra']))->toThrow(InvalidConfiguration::class, 'UNC');
});

it('round trips through toArray', function (): void {
    $printer = PrinterConfig::fromArray('desk', [
        'driver' => 'windows',
        'share' => '//pc/receipt',
        'language' => 'escpos',
        'username' => 'shop',
        'password' => 'secret',
        'code_page' => 'cp1250',
        'code_page_table' => 47,
        'columns' => 42,
    ]);

    expect(PrinterConfig::fromArray('desk', $printer->toArray()))->toEqual($printer)
        ->and($printer->characterTable())->toBe(47);
});

it('resolves printers lazily and reports unknown names', function (): void {
    $config = LabelPrintingConfig::fromArray([
        'default' => 'a',
        'printers' => ['a' => ['driver' => 'null'], 'broken' => ['driver' => 'network']],
        'queue' => ['tries' => 3, 'backoff' => 7],
    ]);

    expect($config->printer()->name)->toBe('a')
        ->and($config->names())->toBe(['a', 'broken'])
        ->and($config->tries)->toBe(3)
        ->and($config->backoff)->toBe([7])
        ->and(fn() => $config->printer('broken'))->toThrow(InvalidConfiguration::class, 'host')
        ->and(fn() => $config->printer('missing'))->toThrow(InvalidConfiguration::class, 'missing');
});

it('requires a default printer when no name is given', function (): void {
    LabelPrintingConfig::fromArray(['printers' => []])->printer();
})->throws(InvalidConfiguration::class, 'LABEL_PRINTER');
