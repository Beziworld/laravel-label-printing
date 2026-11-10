<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Enums\Barcode;
use Beziworld\LabelPrinting\Enums\Orientation;
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Zpl\ZplGraphic;
use Beziworld\LabelPrinting\Zpl\ZplLabel;

it('wraps fields in ^XA, ^CI28 and ^XZ', function (): void {
    expect(ZplLabel::make()->text(10, 20, 'Hello')->toZpl())
        ->toBe("^XA\n^CI28\n^FO10,20^A0N,30^FDHello^FS\n^XZ\n");
});

it('adds print width, label length and copies', function (): void {
    $zpl = ZplLabel::make(812, 1218)->text(0, 0, 'A', 40, 35)->copies(3)->toZpl();

    expect($zpl)->toBe("^XA\n^CI28\n^PW812\n^LL1218\n^FO0,0^A0N,40,35^FDA^FS\n^PQ3\n^XZ\n");
});

it('keeps Polish characters as UTF-8 under ^CI28', function (): void {
    expect(ZplLabel::make()->text(5, 5, 'Zażółć gęślą jaźń', 28, font: 'A', orientation: Orientation::Rotated)->toZpl())
        ->toBe("^XA\n^CI28\n^FO5,5^AAR,28^FDZażółć gęślą jaźń^FS\n^XZ\n");
});

it('escapes command prefixes through ^FH', function (): void {
    expect(ZplLabel::make()->text(0, 0, 'a^b~c_d\\e')->toZpl())
        ->toContain('^FO0,0^A0N,30^FH^FDa_5Eb_7Ec_5Fd_5Ce^FS');
});

it('replaces line breaks in single line text', function (): void {
    expect(ZplLabel::make()->text(0, 0, "one\ntwo")->toZpl())->toContain('^FDone two^FS');
});

it('builds a word-wrapped text block with explicit line breaks', function (): void {
    expect(ZplLabel::make()->textBlock(10, 10, 600, "Line 1\nLine 2", 25, 3, 'C')->toZpl())
        ->toContain('^FO10,10^A0N,25^FB600,3,0,C,0^FDLine 1\&Line 2^FS');
});

it('renders a Code 128 barcode in automatic mode', function (): void {
    expect(ZplLabel::make()->code128(50, 100, '6801234567890', 120, moduleWidth: 3)->toZpl())
        ->toContain('^FO50,100^BY3^BCN,120,Y,N,N,A^FD6801234567890^FS');
});

it('renders EAN-13 from 12 digits or 13 digits with a valid check digit', function (): void {
    $twelve = ZplLabel::make()->ean13(0, 0, '590123412345')->toZpl();
    $thirteen = ZplLabel::make()->ean13(0, 0, '5901234123457', 80, false)->toZpl();

    expect($twelve)->toContain('^FO0,0^BY2^BEN,100,Y,N^FD590123412345^FS')
        ->and($thirteen)->toContain('^FO0,0^BY2^BEN,80,N,N^FD590123412345^FS');
});

it('rejects an EAN-13 with a wrong check digit', function (): void {
    ZplLabel::make()->ean13(0, 0, '5901234123450');
})->throws(InvalidPayload::class, 'check digit');

it('renders EAN-8 and Code 39 through the generic barcode method', function (): void {
    $zpl = ZplLabel::make()
        ->barcode(Barcode::Ean8, 0, 0, '96385074')
        ->barcode(Barcode::Code39, 0, 150, 'ABC-123')
        ->toZpl();

    expect($zpl)->toContain('^FO0,0^BY2^B8N,100,Y,N^FD9638507^FS')
        ->and($zpl)->toContain('^FO0,150^BY2^B3N,N,100,Y,N^FDABC-123^FS');
});

it('rejects lower case Code 39 data', function (): void {
    ZplLabel::make()->code39(0, 0, 'abc');
})->throws(InvalidPayload::class);

it('renders a QR code with matching error correction switches', function (): void {
    expect(ZplLabel::make()->qr(20, 30, 'https://beziworld.eu', 5, QrErrorCorrection::High)->toZpl())
        ->toContain('^FO20,30^BQN,2,5,H^FDHA,https://beziworld.eu^FS');
});

it('escapes QR data containing a caret', function (): void {
    expect(ZplLabel::make()->qr(0, 0, 'A^B')->toZpl())->toContain('^BQN,2,4,M^FH^FDMA,A_5EB^FS');
});

it('draws boxes and lines', function (): void {
    $zpl = ZplLabel::make()->box(10, 10, 300, 200, 4, rounding: 2)->line(10, 250, 500, 3)->toZpl();

    expect($zpl)->toContain('^FO10,10^GB300,200,4,B,2^FS')
        ->and($zpl)->toContain('^FO10,250^GB500,3,3,B,0^FS');
});

it('embeds a graphic field', function (): void {
    $graphic = ZplGraphic::fromBits([[true, false, true, false, true, false, true, false, true]]);

    expect(ZplLabel::make()->image(0, 0, $graphic)->toZpl())->toContain('^FO0,0^GFA,2,2,2,AA80^FS');
});

it('appends raw commands verbatim', function (): void {
    expect(ZplLabel::make()->raw('^MMT')->toZpl())->toBe("^XA\n^CI28\n^MMT\n^XZ\n");
});

it('converts millimetres to dots for the head resolution', function (): void {
    expect(ZplLabel::make()->mm(100))->toBe(799)
        ->and(ZplLabel::make(dpi: 300)->mm(10))->toBe(118);
});

it('validates coordinates and sizes', function (): void {
    ZplLabel::make()->text(-1, 0, 'x');
})->throws(InvalidPayload::class, 'between 0 and 32000');

it('rejects multi character font names', function (): void {
    ZplLabel::make()->text(0, 0, 'x', font: 'AB');
})->throws(InvalidPayload::class);

it('casts to the same string as toZpl', function (): void {
    $label = ZplLabel::make()->text(0, 0, 'x');

    expect((string) $label)->toBe($label->toZpl());
});
