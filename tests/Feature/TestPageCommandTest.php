<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Facades\LabelPrinter;

it('sends a ZPL test page to the default printer', function (): void {
    $fake = LabelPrinter::fake();

    $this->artisan('label-printer:test')
        ->expectsOutputToContain('Test page sent to "warehouse"')
        ->assertSuccessful();

    $fake->assertPrinted('warehouse', fn(string $bytes): bool => str_starts_with($bytes, "^XA\n^CI28\n^PW812\n^LL1218\n")
        && str_contains($bytes, '^FDZażółć gęślą jaźń ĄĆĘŁŃÓŚŹŻ^FS')
        && str_contains($bytes, '^BQN,2,4,M^FDMA,https://beziworld.eu^FS'));
});

it('sends an ESC/POS test receipt to a receipt printer', function (): void {
    $fake = LabelPrinter::fake();

    $this->artisan('label-printer:test', ['printer' => 'receipt'])->assertSuccessful();

    $fake->assertPrinted('receipt', fn(string $bytes): bool => str_starts_with($bytes, "\x1B@\x1Bt\x12")
        && str_contains($bytes, "Za\xBE\xA2\x88\x86 g\xA9\x98l\xA5 ja\xAB\xE4")
        && str_ends_with($bytes, "\x1DVA\x03"));
});

it('sends an EPL test page to an EPL printer', function (): void {
    $fake = LabelPrinter::fake();

    $this->artisan('label-printer:test', ['printer' => 'epl'])->assertSuccessful();

    $fake->assertPrinted('epl', fn(string $bytes): bool => str_contains($bytes, "\nP1\n"));
});

it('renders without sending in dry-run mode', function (): void {
    $fake = LabelPrinter::fake();

    $this->artisan('label-printer:test', ['printer' => 'receipt', '--dry-run' => true])
        ->expectsOutputToContain('1B 40 1B 74 12')
        ->expectsOutputToContain('Dry run: nothing was sent.')
        ->assertSuccessful();

    $fake->assertNothingPrinted();
});

it('fails with a readable message for unknown printers and unreachable printers', function (): void {
    LabelPrinter::fake()->failNext();

    $this->artisan('label-printer:test', ['printer' => 'missing'])
        ->expectsOutputToContain('Printer "missing" is not defined')
        ->assertFailed();

    $this->artisan('label-printer:test', ['printer' => 'warehouse'])
        ->expectsOutputToContain('Could not connect to printer')
        ->assertFailed();
});
