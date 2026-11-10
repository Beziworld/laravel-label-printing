<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Enums;

/**
 * ZPL field orientation (N, R, I, B).
 */
enum Orientation: string
{
    case Normal = 'N';

    /** Rotated 90 degrees clockwise. */
    case Rotated = 'R';

    /** Inverted 180 degrees. */
    case Inverted = 'I';

    /** Read from the bottom up, 270 degrees. */
    case Bottom = 'B';
}
