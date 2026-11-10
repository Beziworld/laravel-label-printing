<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Enums;

/**
 * QR code error correction level.
 */
enum QrErrorCorrection: string
{
    /** High density, about 7 percent recovery. */
    case Low = 'L';

    /** Standard level, about 15 percent recovery. */
    case Medium = 'M';

    /** High reliability, about 25 percent recovery. */
    case Quartile = 'Q';

    /** Ultra-high reliability, about 30 percent recovery. */
    case High = 'H';

    /**
     * Level index expected by the ESC/POS QR error correction function (0 to 3).
     */
    public function escPosLevel(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::Quartile => 2,
            self::High => 3,
        };
    }
}
