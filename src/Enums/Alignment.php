<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Enums;

/**
 * Horizontal justification of receipt content (ESC a n).
 */
enum Alignment: int
{
    case Left = 0;

    case Center = 1;

    case Right = 2;
}
