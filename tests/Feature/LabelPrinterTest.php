<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Data\PrintResult;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\Events\PrintFailed;
use Beziworld\LabelPrinting\Events\PrintSucceeded;
use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\Facades\LabelPrinter;
use Beziworld\LabelPrinting\LabelPrinterManager;
use Beziworld\LabelPrinting\Transports\CupsTransport;
use Beziworld\LabelPrinting\Transports\NetworkTransport;
use Beziworld\LabelPrinting\Transports\NullTransport;
use Illuminate\Support\Facades\Event;

const COURIER_ZPL = "^XA\n^FO40,40^A0N,40^FDInPost 6800000000000000000000^FS\n^FO40,100^BCN,200,Y,N,N^FD6800000000000000000000^FS\n^XZ\n";

it('passes courier ZPL through to a named printer unchanged', function (): void {
    $fake = LabelPrinter::fake();

    $result = LabelPrinter::printer('warehouse')->zpl(COURIER_ZPL, reference: 'shipment-42');

    $fake->assertPrinted('warehouse', COURIER_ZPL);
    $fake->assertPrintedCount(1);
    expect($result)->toBeInstanceOf(PrintResult::class)
        ->and($result->printer)->toBe('warehouse')
        ->and($result->endpoint)->toBe('tcp://192.0.2.10:9100')
        ->and($result->bytes)->toBe(strlen(COURIER_ZPL))
        ->and($result->language)->toBe(Language::Zpl)
        ->and($result->reference)->toBe('shipment-42');
});

it('prints base64 labels and repeats the payload per copy', function (): void {
    $fake = LabelPrinter::fake();

    LabelPrinter::printer('warehouse')->zplBase64(base64_encode('^XA^XZ'), 3);

    expect($fake->printed('warehouse'))->toBe(['^XA^XZ^XA^XZ^XA^XZ']);
});

it('prints a built label pre-sized from the printer configuration', function (): void {
    $fake = LabelPrinter::fake();
    $printer = LabelPrinter::printer('warehouse');

    $printer->zpl($printer->label()->text(10, 10, 'Paczka'));

    $fake->assertPrinted('warehouse', "^XA\n^CI28\n^PW812\n^LL1218\n^FO10,10^A0N,30^FDPaczka^FS\n^XZ\n");
});

it('uses the default printer through the facade shortcuts', function (): void {
    $fake = LabelPrinter::fake();

    LabelPrinter::zpl(LabelPrinter::label()->text(0, 0, 'x'));

    $fake->assertPrinted('warehouse', fn(string $bytes): bool => str_contains($bytes, '^FDx^FS'));
});

it('renders receipts with the code page of the receipt printer', function (): void {
    $fake = LabelPrinter::fake();

    LabelPrinter::printer('receipt')->escpos(LabelPrinter::receipt()->line('Dziękujemy')->cut());

    $fake->assertPrinted('receipt', "\x1B@\x1Bt\x12Dzi\xA9kujemy\n\x1DVA\x03");
});

it('refuses to send a receipt to a ZPL printer and a label to a receipt printer', function (): void {
    LabelPrinter::fake();

    expect(fn() => LabelPrinter::printer('warehouse')->escpos("\x1B@"))->toThrow(InvalidPayload::class, 'speaks ZPL')
        ->and(fn() => LabelPrinter::printer('receipt')->zpl('^XA^XZ'))->toThrow(InvalidPayload::class, 'speaks ESCPOS');
});

it('lets raw printers accept any language and EPL printers accept EPL', function (): void {
    $fake = LabelPrinter::fake();

    LabelPrinter::printer('null')->raw("\x1B@", language: Language::EscPos);
    LabelPrinter::printer('null')->zpl('^XA^XZ');
    LabelPrinter::printer('epl')->epl("N\nP1\n");
    LabelPrinter::printer('epl')->eplBase64(base64_encode("N\nP2\n"));

    $fake->assertPrintedCount(2, 'null');
    $fake->assertPrintedCount(2, 'epl');
});

it('dispatches PrintSucceeded', function (): void {
    Event::fake([PrintSucceeded::class]);
    LabelPrinter::fake();

    LabelPrinter::printer('warehouse')->zpl('^XA^XZ', reference: 'r1');

    Event::assertDispatched(PrintSucceeded::class, fn(PrintSucceeded $event): bool => $event->result->printer === 'warehouse'
        && $event->result->reference === 'r1'
        && $event->result->bytes === 6);
});

it('dispatches PrintFailed and rethrows transport errors', function (): void {
    Event::fake([PrintFailed::class]);
    LabelPrinter::fake()->failNext();

    expect(fn() => LabelPrinter::printer('warehouse')->zpl('^XA^XZ', reference: 'r2'))->toThrow(ConnectionFailed::class);

    Event::assertDispatched(PrintFailed::class, fn(PrintFailed $event): bool => $event->printer === 'warehouse'
        && $event->retryable
        && $event->reference === 'r2'
        && $event->exception instanceof ConnectionFailed);
});

it('marks non connection failures as final in PrintFailed', function (): void {
    Event::fake([PrintFailed::class]);
    LabelPrinter::fake()->failNext(1, TransportFailed::writeFailed('warehouse', 10, 20));

    expect(fn() => LabelPrinter::printer('warehouse')->zpl('^XA^XZ'))->toThrow(TransportFailed::class, '10 of 20');

    Event::assertDispatched(PrintFailed::class, fn(PrintFailed $event): bool => ! $event->retryable);
});

it('builds runtime printers with using()', function (): void {
    $fake = LabelPrinter::fake();

    $printer = LabelPrinter::using(['driver' => 'network', 'host' => '192.0.2.50', 'language' => 'zpl'], 'packing-station-3');
    $printer->zpl('^XA^XZ');

    expect($printer->isRuntime())->toBeTrue()
        ->and($printer->name())->toBe('packing-station-3');
    $fake->assertPrinted('packing-station-3');
});

it('selects the transport matching the driver', function (): void {
    $manager = app(LabelPrinterManager::class);

    expect($manager->transportFor($manager->config()->printer('warehouse')))->toBeInstanceOf(NetworkTransport::class)
        ->and($manager->transportFor($manager->config()->printer('office')))->toBeInstanceOf(CupsTransport::class)
        ->and($manager->transportFor($manager->config()->printer('null')))->toBeInstanceOf(NullTransport::class)
        ->and($manager->isFake())->toBeFalse()
        ->and(LabelPrinter::names())->toBe(['warehouse', 'receipt', 'office', 'epl', 'null']);
});

it('caches named printers and reports unknown ones', function (): void {
    expect(LabelPrinter::printer('warehouse'))->toBe(LabelPrinter::printer('warehouse'))
        ->and(fn() => LabelPrinter::printer('missing'))->toThrow(InvalidConfiguration::class, '"missing"');
});

it('asserts that nothing was printed', function (): void {
    LabelPrinter::fake()->assertNothingPrinted();
});
