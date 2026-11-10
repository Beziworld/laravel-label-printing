<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Support;

use Beziworld\LabelPrinting\Exceptions\InvalidPayload;

/**
 * GS1 check digit helpers for EAN-8 and EAN-13.
 */
final class Gtin
{
    /**
     * Modulo 10 check digit of the data digits (without the check digit).
     */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        $weight = 3;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * Validate an EAN and return it without the check digit.
     *
     * @param 8|13 $length Full length including the check digit.
     */
    public static function withoutCheckDigit(string $digits, int $length): string
    {
        $digits = trim($digits);

        if (preg_match('/^\d+$/', $digits) !== 1 || (strlen($digits) !== $length && strlen($digits) !== $length - 1)) {
            throw InvalidPayload::because(sprintf('EAN-%d needs %d digits, or %d digits with the check digit.', $length, $length - 1, $length));
        }

        if (strlen($digits) === $length - 1) {
            return $digits;
        }

        $data = substr($digits, 0, -1);

        if (self::checkDigit($data) !== (int) substr($digits, -1)) {
            throw InvalidPayload::because(sprintf('The EAN-%d check digit of %s is invalid.', $length, $digits));
        }

        return $data;
    }

    /**
     * @param 8|13 $length
     */
    public static function withCheckDigit(string $digits, int $length): string
    {
        $data = self::withoutCheckDigit($digits, $length);

        return $data . self::checkDigit($data);
    }
}
