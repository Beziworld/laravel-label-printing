<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Zpl;

use Beziworld\LabelPrinting\Exceptions\InvalidPayload;

/**
 * Monochrome bitmap encoded as an uncompressed ZPL "^GFA" graphic field.
 *
 * Each row is padded to a whole number of bytes, a set bit prints a black
 * dot and every byte is written as two upper-case hexadecimal digits.
 */
final readonly class ZplGraphic
{
    public function __construct(
        public int $bytesPerRow,
        public int $rows,
        public string $hex,
    ) {
        if ($bytesPerRow < 1 || $rows < 1 || strlen($hex) !== $bytesPerRow * $rows * 2) {
            throw InvalidPayload::because('The graphic dimensions do not match its data.');
        }
    }

    /**
     * Convert a PNG, GIF, JPEG or WebP image (binary contents) to a graphic field.
     *
     * Pixels darker than the threshold (0 to 255 luminance) print black;
     * transparent pixels are treated as white.
     */
    public static function fromImage(string $contents, int $threshold = 128): self
    {
        if (! function_exists('imagecreatefromstring')) {
            throw InvalidPayload::because('The GD extension is required to convert images to ZPL.');
        }

        $image = $contents !== '' ? @imagecreatefromstring($contents) : false;

        if ($image === false) {
            throw InvalidPayload::because('The image could not be decoded.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $bits = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];

            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);
                $color = imagecolorsforindex($image, $index === false ? 0 : $index);
                $luminance = 0.299 * $color['red'] + 0.587 * $color['green'] + 0.114 * $color['blue'];
                $row[] = $color['alpha'] < 64 && $luminance < $threshold;
            }

            $bits[] = $row;
        }

        return self::fromBits($bits);
    }

    public static function fromFile(string $path, int $threshold = 128): self
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw InvalidPayload::because(sprintf('The image %s could not be read.', $path));
        }

        return self::fromImage($contents, $threshold);
    }

    /**
     * Build a graphic from rows of booleans, true meaning a black dot.
     *
     * @param list<list<bool>> $rows
     */
    public static function fromBits(array $rows): self
    {
        $width = $rows === [] ? 0 : max(array_map('count', $rows));

        if ($width === 0) {
            throw InvalidPayload::because('The graphic is empty.');
        }

        $bytesPerRow = intdiv($width + 7, 8);
        $hex = '';

        foreach ($rows as $row) {
            for ($byte = 0; $byte < $bytesPerRow; $byte++) {
                $value = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    if ($row[$byte * 8 + $bit] ?? false) {
                        $value |= 0x80 >> $bit;
                    }
                }

                $hex .= sprintf('%02X', $value);
            }
        }

        return new self($bytesPerRow, count($rows), $hex);
    }

    public function totalBytes(): int
    {
        return $this->bytesPerRow * $this->rows;
    }

    public function widthInDots(): int
    {
        return $this->bytesPerRow * 8;
    }

    /**
     * The "^GFA,b,c,d,data" command; b equals c for ASCII hexadecimal data.
     */
    public function toCommand(): string
    {
        return sprintf('^GFA,%d,%d,%d,%s', $this->totalBytes(), $this->totalBytes(), $this->bytesPerRow, $this->hex);
    }
}
