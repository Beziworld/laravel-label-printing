<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Data\PrintPayload;
use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\TransportFailed;
use Beziworld\LabelPrinting\Facades\LabelPrinter;
use Beziworld\LabelPrinting\Jobs\PrintJob;
use Beziworld\LabelPrinting\LabelPrinterManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

it('dispatches a queued job with the configured queue, tries and backoff', function (): void {
    Bus::fake();

    $job = LabelPrinter::printer('warehouse')->queue()->zpl('^XA^XZ', 2, 'shipment-7');

    Bus::assertDispatched(PrintJob::class, fn(PrintJob $dispatched): bool => $dispatched === $job
        && $dispatched->printer === 'warehouse'
        && $dispatched->queue === 'printing'
        && $dispatched->tries === 4
        && $dispatched->backoff() === [2, 10]
        && $dispatched->printerConfig === null
        && $dispatched->payload()->copies === 2
        && $dispatched->tags() === ['label-printer:warehouse', 'print-reference:shipment-7']);
    expect($job)->toBeInstanceOf(ShouldQueue::class);
});

it('honours an explicit connection and queue', function (): void {
    Bus::fake();

    $job = LabelPrinter::queue('redis', 'labels')->zplBase64(base64_encode('^XA^XZ'));

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('labels');
});

it('stores rendered receipt bytes so the queue payload stays JSON safe', function (): void {
    Bus::fake();

    $job = LabelPrinter::printer('receipt')->queue()->escpos(LabelPrinter::receipt()->line('Łódź'));

    expect(json_encode($job->payload, JSON_THROW_ON_ERROR))->toBeString()
        ->and($job->payload()->bytes)->toBe("\x1B@\x1Bt\x12\x9D\xA2d\xAB\n");
});

it('carries runtime printer definitions into the job', function (): void {
    Bus::fake();

    $job = LabelPrinter::using(['driver' => 'null', 'language' => 'raw'], 'station-1')->queue()->raw('abc');

    expect($job->printerConfig)->toMatchArray(['driver' => 'null', 'language' => 'raw']);
});

it('prints when the worker handles the job', function (): void {
    $fake = LabelPrinter::fake();
    $job = new PrintJob('warehouse', PrintPayload::zpl('^XA^XZ')->toArray());
    $job->withFakeQueueInteractions();

    $job->handle(app(LabelPrinterManager::class));

    $job->assertNotFailed();
    $fake->assertPrinted('warehouse', '^XA^XZ');
});

it('prints on runtime printers inside the worker', function (): void {
    $fake = LabelPrinter::fake();
    $job = new PrintJob('station-1', PrintPayload::raw('abc')->toArray(), ['driver' => 'null', 'language' => 'raw']);

    $job->handle(app(LabelPrinterManager::class));

    $fake->assertPrinted('station-1', 'abc');
});

it('rethrows connection failures so the worker retries them', function (): void {
    LabelPrinter::fake()->failNext();
    $job = new PrintJob('warehouse', PrintPayload::zpl('^XA^XZ')->toArray());
    $job->withFakeQueueInteractions();

    expect(fn() => $job->handle(app(LabelPrinterManager::class)))->toThrow(ConnectionFailed::class);
    $job->assertNotFailed();
});

it('fails at once on any other error so no label is printed twice', function (): void {
    LabelPrinter::fake()->failNext(1, TransportFailed::writeFailed('warehouse', 100, 400));
    $job = new PrintJob('warehouse', PrintPayload::zpl('^XA^XZ')->toArray());
    $job->withFakeQueueInteractions();

    $job->handle(app(LabelPrinterManager::class));

    $job->assertFailedWith(TransportFailed::class);
});

it('runs synchronously on the sync queue', function (): void {
    $fake = LabelPrinter::fake();
    config()->set('queue.default', 'sync');

    LabelPrinter::printer('warehouse')->queue()->zpl('^XA^FDsync^FS^XZ');

    $fake->assertPrinted('warehouse', '^XA^FDsync^FS^XZ');
});
