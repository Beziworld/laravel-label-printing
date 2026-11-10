<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Data\PrintPayload;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;

it('accepts raw ZPL from a courier API', function (): void {
    $payload = PrintPayload::zpl('^XA^FO50,50^A0N,50^FDInPost^FS^XZ', 2, 'shipment-1');

    expect($payload->language)->toBe(Language::Zpl)
        ->and($payload->copies)->toBe(2)
        ->and($payload->reference)->toBe('shipment-1')
        ->and($payload->output())->toBe(str_repeat('^XA^FO50,50^A0N,50^FDInPost^FS^XZ', 2));
});

it('rejects ZPL without a format start', function (): void {
    PrintPayload::zpl('%PDF-1.7');
})->throws(InvalidPayload::class, '^XA');

it('decodes base64 ZPL including line wrapped base64', function (): void {
    $encoded = chunk_split(base64_encode('^XA^FDok^FS^XZ'), 8, "\r\n");

    expect(PrintPayload::zplBase64($encoded)->bytes)->toBe('^XA^FDok^FS^XZ');
});

it('rejects invalid base64', function (): void {
    PrintPayload::zplBase64('***');
})->throws(InvalidPayload::class, 'base64');

it('accepts EPL with a print command', function (): void {
    $epl = "\nN\nA50,50,0,3,1,1,N,\"DHL\"\nP1\n";

    expect(PrintPayload::epl($epl)->language)->toBe(Language::Epl)
        ->and(PrintPayload::eplBase64(base64_encode($epl))->bytes)->toBe($epl);
});

it('rejects EPL without a print command', function (): void {
    PrintPayload::epl("N\nA50,50,0,3,1,1,N,\"x\"\n");
})->throws(InvalidPayload::class);

it('round trips binary bytes through a JSON safe array', function (): void {
    $payload = PrintPayload::raw("\x1B@\x1Bt\x12\xBE\xA2\n", Language::EscPos, 3, 'order-9');
    $array = $payload->toArray();

    expect(json_encode($array, JSON_THROW_ON_ERROR))->toBeString()
        ->and(PrintPayload::fromArray($array))->toEqual($payload);
});

it('rejects empty payloads and invalid copy counts', function (): void {
    expect(fn() => PrintPayload::raw(''))->toThrow(InvalidPayload::class)
        ->and(fn() => PrintPayload::raw('x', copies: 0))->toThrow(InvalidPayload::class)
        ->and(fn() => PrintPayload::fromArray(['bytes' => '!!']))->toThrow(InvalidPayload::class);
});
