<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\EscPos;

use Beziworld\LabelPrinting\Enums\Alignment;
use Beziworld\LabelPrinting\Enums\Barcode;
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;
use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;
use Beziworld\LabelPrinting\Support\Gtin;
use Closure;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;

/**
 * Fluent builder of an ESC/POS receipt (non-fiscal printout).
 *
 * Commands are rendered with mike42/escpos-php into memory, never sent from
 * here. Text is encoded by this package into a single, explicitly selected
 * code page (PC852 by default for Polish), so every byte is predictable.
 */
final class Receipt
{
    /** @var list<Closure(EscposPrinter, RenderOptions): void> */
    private array $operations = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * Text without a trailing line feed.
     */
    public function text(string $text): self
    {
        return $this->push(static fn(EscposPrinter $printer, RenderOptions $options) => $printer->textRaw($options->codePage->encode($text)));
    }

    /**
     * Text followed by a line feed; an empty call prints an empty line.
     */
    public function line(string $text = ''): self
    {
        return $this->text($text . "\n");
    }

    public function bold(bool $on = true): self
    {
        return $this->push(static fn(EscposPrinter $printer) => $printer->setEmphasis($on));
    }

    public function underline(bool $on = true, bool $double = false): self
    {
        $mode = ! $on ? EscposPrinter::UNDERLINE_NONE : ($double ? EscposPrinter::UNDERLINE_DOUBLE : EscposPrinter::UNDERLINE_SINGLE);

        return $this->push(static fn(EscposPrinter $printer) => $printer->setUnderline($mode));
    }

    /**
     * White on black printing.
     */
    public function invert(bool $on = true): self
    {
        return $this->push(static fn(EscposPrinter $printer) => $printer->setReverseColors($on));
    }

    /**
     * Select font A, B or C (ESC M).
     */
    public function font(string $font): self
    {
        $value = match ($font) {
            'A' => EscposPrinter::FONT_A,
            'B' => EscposPrinter::FONT_B,
            'C' => EscposPrinter::FONT_C,
            default => throw InvalidPayload::because('The ESC/POS font must be A, B or C.'),
        };

        return $this->push(static fn(EscposPrinter $printer) => $printer->setFont($value));
    }

    /**
     * Character magnification, 1 to 8 in each direction (GS !).
     */
    public function size(int $width, int $height): self
    {
        if ($width < 1 || $width > 8 || $height < 1 || $height > 8) {
            throw InvalidPayload::because('Text magnification must be between 1 and 8.');
        }

        return $this->push(static fn(EscposPrinter $printer) => $printer->setTextSize($width, $height));
    }

    public function doubleSize(): self
    {
        return $this->size(2, 2);
    }

    /**
     * Restore normal text: no emphasis, underline, inversion or magnification, font A.
     */
    public function normal(): self
    {
        return $this->push(static function (EscposPrinter $printer): void {
            $printer->selectPrintMode(EscposPrinter::MODE_FONT_A);
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
            $printer->setUnderline(EscposPrinter::UNDERLINE_NONE);
            $printer->setReverseColors(false);
        });
    }

    public function align(Alignment $alignment): self
    {
        return $this->push(static fn(EscposPrinter $printer) => $printer->setJustification($alignment->value));
    }

    public function left(): self
    {
        return $this->align(Alignment::Left);
    }

    public function center(): self
    {
        return $this->align(Alignment::Center);
    }

    public function right(): self
    {
        return $this->align(Alignment::Right);
    }

    /**
     * Feed paper by a number of lines (LF for one line, ESC d n otherwise).
     */
    public function feed(int $lines = 1): self
    {
        if ($lines < 1 || $lines > 255) {
            throw InvalidPayload::because('The feed must be between 1 and 255 lines.');
        }

        return $this->push(static fn(EscposPrinter $printer) => $printer->feed($lines));
    }

    /**
     * Full-width line of a repeated character.
     */
    public function divider(string $character = '-'): self
    {
        return $this->push(static function (EscposPrinter $printer, RenderOptions $options) use ($character): void {
            $character = mb_substr($character, 0, 1) !== '' ? mb_substr($character, 0, 1) : '-';
            $printer->textRaw($options->codePage->encode(str_repeat($character, $options->columns) . "\n"));
        });
    }

    /**
     * Left and right text on one line, for example an item and its price.
     *
     * Long left text is shortened so the right text always fits.
     */
    public function columns(string $left, string $right, ?int $width = null): self
    {
        return $this->push(static function (EscposPrinter $printer, RenderOptions $options) use ($left, $right, $width): void {
            $printer->textRaw($options->codePage->encode(self::pad($left, $right, $width ?? $options->columns) . "\n"));
        });
    }

    /**
     * One-dimensional barcode (GS k) with the human readable text below it.
     */
    public function barcode(string $data, Barcode $type = Barcode::Code128, int $height = 80, int $moduleWidth = 3, bool $text = true): self
    {
        [$content, $symbology] = match ($type) {
            Barcode::Code128 => [self::code128($data), EscposPrinter::BARCODE_CODE128],
            Barcode::Ean13 => [Gtin::withCheckDigit($data, 13), EscposPrinter::BARCODE_JAN13],
            Barcode::Ean8 => [Gtin::withCheckDigit($data, 8), EscposPrinter::BARCODE_JAN8],
            Barcode::Code39 => [$data, EscposPrinter::BARCODE_CODE39],
        };

        if ($height < 1 || $height > 255 || $moduleWidth < 1 || $moduleWidth > 6) {
            throw InvalidPayload::because('The barcode height must be 1 to 255 dots and the module width 1 to 6.');
        }

        return $this->push(static function (EscposPrinter $printer) use ($content, $symbology, $height, $moduleWidth, $text): void {
            $printer->setBarcodeHeight($height);
            $printer->setBarcodeWidth($moduleWidth);
            $printer->setBarcodeTextPosition($text ? EscposPrinter::BARCODE_TEXT_BELOW : EscposPrinter::BARCODE_TEXT_NONE);
            $printer->barcode($content, $symbology);
        });
    }

    /**
     * QR code (GS ( k, model 2), module size 1 to 16 dots.
     */
    public function qr(string $data, int $size = 6, QrErrorCorrection $errorCorrection = QrErrorCorrection::Medium): self
    {
        if ($data === '' || $size < 1 || $size > 16) {
            throw InvalidPayload::because('QR data must not be empty and the module size must be between 1 and 16.');
        }

        return $this->push(static fn(EscposPrinter $printer) => $printer->qrCode($data, $errorCorrection->escPosLevel(), $size, EscposPrinter::QR_MODEL_2));
    }

    /**
     * Raster image (GS v 0) from PNG, GIF or JPEG contents, for example a logo.
     */
    public function image(string $contents): self
    {
        if ($contents === '') {
            throw InvalidPayload::because('The image is empty.');
        }

        return $this->push(static function (EscposPrinter $printer) use ($contents): void {
            $path = tempnam(sys_get_temp_dir(), 'escpos');

            if ($path === false) {
                throw InvalidPayload::because('A temporary file for the image could not be created.');
            }

            $file = $path . '.png';

            try {
                file_put_contents($file, $contents);
                $printer->bitImage(EscposImage::load($file, true, ['gd', 'native']));
            } finally {
                @unlink($file);
                @unlink($path);
            }
        });
    }

    /**
     * Feed and cut the paper (GS V function B).
     */
    public function cut(bool $partial = false, int $feed = 3): self
    {
        if ($feed < 0 || $feed > 255) {
            throw InvalidPayload::because('The cut feed must be between 0 and 255.');
        }

        return $this->push(static fn(EscposPrinter $printer) => $printer->cut($partial ? EscposPrinter::CUT_PARTIAL : EscposPrinter::CUT_FULL, $feed));
    }

    /**
     * Kick the cash drawer connected to the printer (ESC p).
     */
    public function openCashDrawer(int $pin = 2, int $onMs = 120, int $offMs = 240): self
    {
        if (! in_array($pin, [2, 5], true)) {
            throw InvalidPayload::because('The drawer kick-out connector pin must be 2 or 5.');
        }

        return $this->push(static fn(EscposPrinter $printer) => $printer->pulse($pin === 2 ? 0 : 1, $onMs, $offMs));
    }

    /**
     * Append bytes verbatim.
     */
    public function raw(string $bytes): self
    {
        return $this->push(static fn(EscposPrinter $printer) => $printer->getPrintConnector()->write($bytes));
    }

    /**
     * Render the receipt to bytes: ESC @, ESC t with the code page, then the commands.
     */
    public function render(RenderOptions $options = new RenderOptions()): string
    {
        try {
            $profile = CapabilityProfile::load($options->profile);
        } catch (\InvalidArgumentException $exception) {
            throw InvalidConfiguration::invalid('profile', $exception->getMessage());
        }

        $connector = new DummyPrintConnector();

        try {
            $printer = new EscposPrinter($connector, $profile);
            $connector->write("\x1Bt" . chr($options->table()));

            foreach ($this->operations as $operation) {
                $operation($printer, $options);
            }

            return $connector->getData();
        } catch (InvalidPayload $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            throw InvalidPayload::because('The receipt could not be rendered: ' . $exception->getMessage());
        } finally {
            $connector->finalize();
        }
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    /**
     * @param Closure(EscposPrinter, RenderOptions): void $operation
     */
    private function push(Closure $operation): self
    {
        $this->operations[] = $operation;

        return $this;
    }

    private static function code128(string $data): string
    {
        if ($data === '' || preg_match('/^[\x20-\x7E]+$/', $data) !== 1) {
            throw InvalidPayload::because('Code 128 data must be printable ASCII.');
        }

        return str_starts_with($data, '{') ? $data : '{B' . $data;
    }

    private static function pad(string $left, string $right, int $width): string
    {
        $right = mb_substr($right, 0, $width);
        $space = $width - mb_strlen($right);

        if ($space <= 0) {
            return $right;
        }

        $left = mb_strlen($left) >= $space ? mb_substr($left, 0, max(0, $space - 1)) : $left;

        return $left . str_repeat(' ', $width - mb_strlen($left) - mb_strlen($right)) . $right;
    }
}
