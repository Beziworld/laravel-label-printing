<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Enums\Alignment;
use Beziworld\LabelPrinting\Enums\Barcode;
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;
use Beziworld\LabelPrinting\EscPos\CodePage;
use Beziworld\LabelPrinting\EscPos\Receipt;
use Beziworld\LabelPrinting\EscPos\RenderOptions;
use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;

const ESC_INIT_PC852 = "\x1B@\x1Bt\x12";

it('starts with ESC @ and selects the PC852 table', function (): void {
    expect(Receipt::make()->render())->toBe(ESC_INIT_PC852);
});

it('encodes Polish text with the selected code page', function (): void {
    expect(Receipt::make()->line('Zażółć')->render())
        ->toBe(ESC_INIT_PC852 . "Za\xBE\xA2\x88\x86\n");
});

it('uses the configured code page and table override', function (): void {
    $bytes = Receipt::make()->text('ł')->render(new RenderOptions(CodePage::Cp1250));
    $override = Receipt::make()->text('ł')->render(new RenderOptions(CodePage::Cp852, table: 46));

    expect($bytes)->toBe("\x1B@\x1Bt\x2D\xB3")
        ->and($override)->toBe("\x1B@\x1Bt\x2E\x88");
});

it('renders text styles and alignment', function (): void {
    $bytes = Receipt::make()
        ->center()
        ->bold()
        ->underline()
        ->doubleSize()
        ->text('A')
        ->align(Alignment::Right)
        ->invert()
        ->font('B')
        ->render();

    expect($bytes)->toBe(ESC_INIT_PC852 . "\x1Ba\x01" . "\x1BE\x01" . "\x1B-\x01" . "\x1D!\x11" . 'A' . "\x1Ba\x02" . "\x1DB\x01" . "\x1BM\x01");
});

it('resets styles with normal()', function (): void {
    expect(Receipt::make()->normal()->render())
        ->toBe(ESC_INIT_PC852 . "\x1B!\x00" . "\x1D!\x00" . "\x1BE\x00" . "\x1B-\x00" . "\x1DB\x00");
});

it('prints dividers and two column lines at the configured width', function (): void {
    $bytes = Receipt::make()
        ->divider('=')
        ->columns('Kawa', '12,00 zł')
        ->columns('Bardzo długa nazwa produktu', '1,00')
        ->render(new RenderOptions(columns: 20));

    expect($bytes)->toBe(ESC_INIT_PC852
        . str_repeat('=', 20) . "\n"
        . "Kawa        12,00 z\x88\n"
        . "Bardzo d\x88uga na 1,00\n");
});

it('feeds paper with LF or ESC d', function (): void {
    expect(Receipt::make()->feed()->feed(4)->render())->toBe(ESC_INIT_PC852 . "\n\x1Bd\x04");
});

it('renders a Code 128 barcode with the code set B prefix', function (): void {
    expect(Receipt::make()->barcode('ABC123')->render())
        ->toBe(ESC_INIT_PC852 . "\x1Dh\x50" . "\x1Dw\x03" . "\x1DH\x02" . "\x1Dk\x49\x08{BABC123");
});

it('renders EAN-13 with the check digit and no human readable text', function (): void {
    expect(Receipt::make()->barcode('590123412345', Barcode::Ean13, 60, 2, false)->render())
        ->toBe(ESC_INIT_PC852 . "\x1Dh\x3C" . "\x1Dw\x02" . "\x1DH\x00" . "\x1Dk\x43\x0D5901234123457");
});

it('renders a model 2 QR code', function (): void {
    expect(Receipt::make()->qr('AB', 6, QrErrorCorrection::Medium)->render())
        ->toBe(ESC_INIT_PC852
            . "\x1D(k\x04\x001A2\x00"
            . "\x1D(k\x03\x001C\x06"
            . "\x1D(k\x03\x001E1"
            . "\x1D(k\x05\x001P0AB"
            . "\x1D(k\x03\x001Q0");
});

it('cuts the paper and opens the cash drawer', function (): void {
    expect(Receipt::make()->cut()->cut(true, 0)->openCashDrawer()->openCashDrawer(5, 100, 200)->render())
        ->toBe(ESC_INIT_PC852 . "\x1DVA\x03" . "\x1DVB\x00" . "\x1Bp0\x3C\x78" . "\x1Bp1\x32\x64");
});

it('rejects an invalid drawer pin', function (): void {
    Receipt::make()->openCashDrawer(3);
})->throws(InvalidPayload::class);

it('prints a raster image with GS v 0', function (): void {
    $image = imagecreatetruecolor(8, 2);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagesetpixel($image, 0, 0, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    expect(Receipt::make()->image($png)->render())
        ->toBe(ESC_INIT_PC852 . "\x1Dv0\x00\x01\x00\x02\x00" . "\x80\x00");
});

it('appends raw bytes verbatim', function (): void {
    expect(Receipt::make()->raw("\x1B\x42\x02\x01")->render())->toBe(ESC_INIT_PC852 . "\x1B\x42\x02\x01");
});

it('reports features missing from the capability profile', function (): void {
    Receipt::make()->qr('AB')->render(new RenderOptions(profile: 'simple'));
})->throws(InvalidPayload::class, 'QR codes are not supported');

it('rejects unknown capability profiles', function (): void {
    Receipt::make()->render(new RenderOptions(profile: 'no-such-printer'));
})->throws(InvalidConfiguration::class);

it('validates text magnification', function (): void {
    Receipt::make()->size(9, 1);
})->throws(InvalidPayload::class);
