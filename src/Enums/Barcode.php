<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Enums;

/**
 * One-dimensional barcode symbologies supported by both builders.
 */
enum Barcode: string
{
    case Code128 = 'code128';

    case Ean13 = 'ean13';

    case Ean8 = 'ean8';

    case Code39 = 'code39';
}
