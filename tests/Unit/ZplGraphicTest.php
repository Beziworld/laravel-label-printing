<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Zpl\ZplGraphic;

/**
 * Build a PNG in memory; "#" is black, "." white and "o" fully transparent black.
 *
 * @param list<string> $rows
 */
function pngFromPattern(array $rows): string
{
    $image = imagecreatetruecolor(strlen($rows[0]), count($rows));
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $black = imagecolorallocatealpha($image, 0, 0, 0, 0);
    $white = imagecolorallocatealpha($image, 255, 255, 255, 0);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

    foreach ($rows as $y => $row) {
        foreach (str_split($row) as $x => $pixel) {
            imagesetpixel($image, $x, $y, match ($pixel) {
                '#' => (int) $black,
                'o' => (int) $transparent,
                default => (int) $white,
            });
        }
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('converts a PNG to uncompressed ASCII hexadecimal rows', function (): void {
    $graphic = ZplGraphic::fromImage(pngFromPattern([
        '##########',
        '#........#',
    ]));

    expect($graphic->bytesPerRow)->toBe(2)
        ->and($graphic->rows)->toBe(2)
        ->and($graphic->hex)->toBe('FFC08040')
        ->and($graphic->toCommand())->toBe('^GFA,4,4,2,FFC08040')
        ->and($graphic->widthInDots())->toBe(16);
});

it('treats transparent pixels as white', function (): void {
    expect(ZplGraphic::fromImage(pngFromPattern(['#o#o#o#o']))->hex)->toBe('AA');
});

it('applies the luminance threshold', function (): void {
    $image = imagecreatetruecolor(8, 1);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 150, 150, 150));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    expect(ZplGraphic::fromImage($png)->hex)->toBe('00')
        ->and(ZplGraphic::fromImage($png, 200)->hex)->toBe('FF');
});

it('reads images from disk', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'zpl');
    file_put_contents($path, pngFromPattern(['#.......']));

    try {
        expect(ZplGraphic::fromFile($path)->hex)->toBe('80');
    } finally {
        unlink($path);
    }
});

it('rejects data that is not an image', function (): void {
    ZplGraphic::fromImage('not an image');
})->throws(InvalidPayload::class, 'could not be decoded');

it('rejects inconsistent dimensions', function (): void {
    new ZplGraphic(2, 2, 'FF');
})->throws(InvalidPayload::class);
