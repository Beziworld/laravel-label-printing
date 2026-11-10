<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Zpl;

use Beziworld\LabelPrinting\Enums\Barcode;
use Beziworld\LabelPrinting\Enums\Orientation;
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Support\Gtin;
use Stringable;

/**
 * Fluent builder of a single ZPL II label format (^XA ... ^XZ).
 *
 * Coordinates and sizes are in printer dots (8 dots per millimetre on a
 * 203 dpi head, 12 on 300 dpi). Text is sent as UTF-8 under ^CI28; the
 * characters ^, ~, _ and \ inside field data are escaped through ^FH.
 */
final class ZplLabel implements Stringable
{
    /** @var list<string> */
    private array $fields = [];

    private int $copies = 1;

    public function __construct(
        private ?int $width = null,
        private ?int $length = null,
        private readonly int $dpi = 203,
        private readonly bool $utf8 = true,
    ) {}

    public static function make(?int $width = null, ?int $length = null, int $dpi = 203): self
    {
        return new self($width, $length, $dpi);
    }

    /**
     * Print width in dots (^PW).
     */
    public function width(int $dots): self
    {
        $this->width = $this->range($dots, 2, 32000, 'width');

        return $this;
    }

    /**
     * Label length in dots (^LL).
     */
    public function length(int $dots): self
    {
        $this->length = $this->range($dots, 1, 32000, 'length');

        return $this;
    }

    /**
     * Convert millimetres to dots for the configured print head resolution.
     */
    public function mm(float $millimetres): int
    {
        return (int) round($millimetres * $this->dpi / 25.4);
    }

    /**
     * Single line of text (^FO, ^A, ^FD, ^FS).
     */
    public function text(
        int $x,
        int $y,
        string $text,
        int $height = 30,
        ?int $width = null,
        string $font = '0',
        Orientation $orientation = Orientation::Normal,
    ): self {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);

        return $this->field($x, $y, $this->font($font, $orientation, $height, $width) . $this->data($text));
    }

    /**
     * Word-wrapped text block (^FB); line breaks in the text start a new line.
     *
     * Justification is L (left), C (center), R (right) or J (justified).
     */
    public function textBlock(
        int $x,
        int $y,
        int $blockWidth,
        string $text,
        int $height = 30,
        int $maxLines = 2,
        string $justify = 'L',
        int $lineSpacing = 0,
        string $font = '0',
    ): self {
        if (! in_array($justify, ['L', 'C', 'R', 'J'], true)) {
            throw InvalidPayload::because('Text block justification must be L, C, R or J.');
        }

        $block = sprintf(
            '^FB%d,%d,%d,%s,0',
            $this->range($blockWidth, 1, 32000, 'block width'),
            $this->range($maxLines, 1, 9999, 'max lines'),
            $this->range($lineSpacing, -9999, 9999, 'line spacing'),
            $justify,
        );

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = $lines === false ? [$text] : $lines;
        $escaped = implode('\\&', array_map(fn(string $line): string => $this->escape($line), $lines));

        return $this->field($x, $y, $this->font($font, Orientation::Normal, $height, null) . $block . $this->prefix($text) . '^FD' . $escaped . '^FS');
    }

    /**
     * Code 128 barcode (^BC) in automatic subset mode.
     */
    public function code128(
        int $x,
        int $y,
        string $data,
        int $height = 100,
        bool $interpretation = true,
        int $moduleWidth = 2,
        Orientation $orientation = Orientation::Normal,
    ): self {
        if ($data === '' || preg_match('/^[\x20-\x7E]+$/', $data) !== 1) {
            throw InvalidPayload::because('Code 128 data must be printable ASCII.');
        }

        return $this->field($x, $y, sprintf(
            '%s^BC%s,%d,%s,N,N,A%s',
            $this->moduleWidth($moduleWidth),
            $orientation->value,
            $this->range($height, 1, 32000, 'barcode height'),
            $interpretation ? 'Y' : 'N',
            $this->data($data),
        ));
    }

    /**
     * EAN-13 barcode (^BE); accepts 12 digits or 13 digits with a valid check digit.
     */
    public function ean13(int $x, int $y, string $digits, int $height = 100, bool $interpretation = true, int $moduleWidth = 2): self
    {
        $data = Gtin::withoutCheckDigit($digits, 13);

        return $this->field($x, $y, sprintf(
            '%s^BEN,%d,%s,N^FD%s^FS',
            $this->moduleWidth($moduleWidth),
            $this->range($height, 1, 32000, 'barcode height'),
            $interpretation ? 'Y' : 'N',
            $data,
        ));
    }

    /**
     * EAN-8 barcode (^B8); accepts 7 digits or 8 digits with a valid check digit.
     */
    public function ean8(int $x, int $y, string $digits, int $height = 100, bool $interpretation = true, int $moduleWidth = 2): self
    {
        $data = Gtin::withoutCheckDigit($digits, 8);

        return $this->field($x, $y, sprintf(
            '%s^B8N,%d,%s,N^FD%s^FS',
            $this->moduleWidth($moduleWidth),
            $this->range($height, 1, 32000, 'barcode height'),
            $interpretation ? 'Y' : 'N',
            $data,
        ));
    }

    /**
     * Code 39 barcode (^B3) without the optional Mod-43 check digit.
     */
    public function code39(int $x, int $y, string $data, int $height = 100, bool $interpretation = true, int $moduleWidth = 2): self
    {
        if (preg_match('/^[0-9A-Z \-.$\/+%]+$/', $data) !== 1) {
            throw InvalidPayload::because('Code 39 data may contain only 0-9, A-Z, space and - . $ / + %.');
        }

        return $this->field($x, $y, sprintf(
            '%s^B3N,N,%d,%s,N^FD%s^FS',
            $this->moduleWidth($moduleWidth),
            $this->range($height, 1, 32000, 'barcode height'),
            $interpretation ? 'Y' : 'N',
            $data,
        ));
    }

    /**
     * Barcode of any supported one-dimensional symbology.
     */
    public function barcode(Barcode $type, int $x, int $y, string $data, int $height = 100, bool $interpretation = true, int $moduleWidth = 2): self
    {
        return match ($type) {
            Barcode::Code128 => $this->code128($x, $y, $data, $height, $interpretation, $moduleWidth),
            Barcode::Ean13 => $this->ean13($x, $y, $data, $height, $interpretation, $moduleWidth),
            Barcode::Ean8 => $this->ean8($x, $y, $data, $height, $interpretation, $moduleWidth),
            Barcode::Code39 => $this->code39($x, $y, $data, $height, $interpretation, $moduleWidth),
        };
    }

    /**
     * QR code (^BQ, model 2) with automatic data input.
     */
    public function qr(int $x, int $y, string $data, int $magnification = 4, QrErrorCorrection $errorCorrection = QrErrorCorrection::Medium): self
    {
        if ($data === '') {
            throw InvalidPayload::because('QR code data must not be empty.');
        }

        return $this->field($x, $y, sprintf(
            '^BQN,2,%d,%s%s^FD%sA,%s^FS',
            $this->range($magnification, 1, 100, 'QR magnification'),
            $errorCorrection->value,
            $this->prefix($data),
            $errorCorrection->value,
            $this->escape($data),
        ));
    }

    /**
     * Rectangle outline, or a filled box when the thickness covers the shorter side (^GB).
     */
    public function box(int $x, int $y, int $width, int $height, int $thickness = 2, bool $white = false, int $rounding = 0): self
    {
        $thickness = $this->range($thickness, 1, 32000, 'thickness');

        return $this->field($x, $y, sprintf(
            '^GB%d,%d,%d,%s,%d^FS',
            max($thickness, $this->range($width, 0, 32000, 'box width')),
            max($thickness, $this->range($height, 0, 32000, 'box height')),
            $thickness,
            $white ? 'W' : 'B',
            $this->range($rounding, 0, 8, 'rounding'),
        ));
    }

    /**
     * Horizontal line.
     */
    public function line(int $x, int $y, int $length, int $thickness = 2): self
    {
        return $this->box($x, $y, $length, $thickness, $thickness);
    }

    /**
     * Monochrome image as an uncompressed graphic field (^GFA).
     */
    public function image(int $x, int $y, string|ZplGraphic $image, int $threshold = 128): self
    {
        $graphic = $image instanceof ZplGraphic ? $image : ZplGraphic::fromImage($image, $threshold);

        return $this->field($x, $y, $graphic->toCommand() . '^FS');
    }

    /**
     * Number of copies of this format (^PQ).
     */
    public function copies(int $copies): self
    {
        $this->copies = $this->range($copies, 1, 99_999_999, 'copies');

        return $this;
    }

    /**
     * Append ZPL commands verbatim.
     */
    public function raw(string $zpl): self
    {
        $this->fields[] = $zpl;

        return $this;
    }

    public function toZpl(): string
    {
        $lines = ['^XA'];

        if ($this->utf8) {
            $lines[] = '^CI28';
        }

        if ($this->width !== null) {
            $lines[] = '^PW' . $this->width;
        }

        if ($this->length !== null) {
            $lines[] = '^LL' . $this->length;
        }

        array_push($lines, ...$this->fields);

        if ($this->copies > 1) {
            $lines[] = '^PQ' . $this->copies;
        }

        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }

    public function __toString(): string
    {
        return $this->toZpl();
    }

    private function field(int $x, int $y, string $body): self
    {
        $this->fields[] = sprintf('^FO%d,%d', $this->range($x, 0, 32000, 'x'), $this->range($y, 0, 32000, 'y')) . $body;

        return $this;
    }

    private function font(string $font, Orientation $orientation, int $height, ?int $width): string
    {
        if (preg_match('/^[A-Z0-9]$/', $font) !== 1) {
            throw InvalidPayload::because('A ZPL font name is a single character A-Z or 0-9.');
        }

        $command = sprintf('^A%s%s,%d', $font, $orientation->value, $this->range($height, 1, 32000, 'font height'));

        return $width === null ? $command : $command . ',' . $this->range($width, 1, 32000, 'font width');
    }

    private function moduleWidth(int $dots): string
    {
        return '^BY' . $this->range($dots, 1, 10, 'module width');
    }

    /**
     * Field data with ^FH escaping when needed.
     */
    private function data(string $text): string
    {
        return $this->prefix($text) . '^FD' . $this->escape($text) . '^FS';
    }

    private function prefix(string $text): string
    {
        return strpbrk($text, '^~_\\') !== false ? '^FH' : '';
    }

    private function escape(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw InvalidPayload::because('Label text must be valid UTF-8.');
        }

        if (strpbrk($text, '^~_\\') === false) {
            return $text;
        }

        return strtr($text, ['_' => '_5F', '^' => '_5E', '~' => '_7E', '\\' => '_5C']);
    }

    private function range(int $value, int $min, int $max, string $name): int
    {
        if ($value < $min || $value > $max) {
            throw InvalidPayload::because(sprintf('The %s must be between %d and %d, %d given.', $name, $min, $max, $value));
        }

        return $value;
    }
}
