<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Enums;

/**
 * Printer command language of a payload or a printer.
 */
enum Language: string
{
    case Zpl = 'zpl';

    case Epl = 'epl';

    case EscPos = 'escpos';

    case Raw = 'raw';
}
