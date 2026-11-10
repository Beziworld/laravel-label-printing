<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\EscPos;

use Beziworld\LabelPrinting\PrinterConfig;

/**
 * Printer specific settings that affect how a receipt is rendered.
 */
final readonly class RenderOptions
{
    /**
     * @param string $profile mike42/escpos-php capability profile, for example "default", "TM-T88V" or "simple".
     * @param int|null $table "ESC t" table number overriding the code page default.
     */
    public function __construct(
        public CodePage $codePage = CodePage::Cp852,
        public string $profile = 'default',
        public int $columns = 48,
        public ?int $table = null,
    ) {}

    public static function forPrinter(PrinterConfig $printer): self
    {
        return new self($printer->codePage, $printer->profile, $printer->columns, $printer->codePageTable);
    }

    public function table(): int
    {
        return $this->table ?? $this->codePage->table();
    }
}
