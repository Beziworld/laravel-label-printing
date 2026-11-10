<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Support\Gtin;

it('computes GS1 modulo 10 check digits', function (): void {
    expect(Gtin::checkDigit('590123412345'))->toBe(7)
        ->and(Gtin::checkDigit('9638507'))->toBe(4)
        ->and(Gtin::withCheckDigit('590123412345', 13))->toBe('5901234123457');
});

it('rejects wrong lengths and non digits', function (string $value): void {
    Gtin::withoutCheckDigit($value, 13);
})->with(['12345', '59012341234X', '59012341234567'])->throws(InvalidPayload::class);
