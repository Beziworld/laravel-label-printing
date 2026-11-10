<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\EscPos;

use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;
use Illuminate\Support\Str;

/**
 * Single-byte character code table used for ESC/POS text.
 *
 * The table numbers follow the Epson TM numbering of "ESC t n" (PC852 is 18,
 * WPC1250 is 45, ISO 8859-2 is 39). Printers with a different numbering can
 * override the number per printer with the "code_page_table" option.
 */
enum CodePage: string
{
    /** PC437 USA, standard Europe; no Polish letters. */
    case Cp437 = 'cp437';

    /** PC852 Latin 2, the usual choice for Polish text on ESC/POS printers. */
    case Cp852 = 'cp852';

    /** PC858 Euro, Western European letters with the euro sign. */
    case Cp858 = 'cp858';

    /** WPC1250 Windows Central European. */
    case Cp1250 = 'cp1250';

    /** ISO 8859-2 Latin 2. */
    case Iso8859_2 = 'iso8859-2';

    public static function fromConfig(string $value): self
    {
        $normalised = strtolower(str_replace(['_', ' '], '-', trim($value)));
        $normalised = match ($normalised) {
            'pc852', 'cp-852', 'latin2', 'latin-2' => 'cp852',
            'wpc1250', 'cp-1250', 'windows-1250' => 'cp1250',
            'iso-8859-2', 'iso88592' => 'iso8859-2',
            'pc437', 'cp-437' => 'cp437',
            'pc858', 'cp-858' => 'cp858',
            default => $normalised,
        };

        return self::tryFrom($normalised)
            ?? throw InvalidConfiguration::invalid('code_page', sprintf('"%s" is not one of cp437, cp852, cp858, cp1250, iso8859-2.', $value));
    }

    /**
     * Default "ESC t" table number on Epson compatible printers.
     */
    public function table(): int
    {
        return match ($this) {
            self::Cp437 => 0,
            self::Cp852 => 18,
            self::Cp858 => 19,
            self::Cp1250 => 45,
            self::Iso8859_2 => 39,
        };
    }

    /**
     * @return array<int, int> Unicode code point to byte for 0x80 to 0xFF.
     */
    public function map(): array
    {
        return match ($this) {
            self::Cp437 => CodePageTables::CP437,
            self::Cp852 => CodePageTables::CP852,
            self::Cp858 => CodePageTables::CP858,
            self::Cp1250 => CodePageTables::CP1250,
            self::Iso8859_2 => CodePageTables::ISO_8859_2,
        };
    }

    /**
     * Encode UTF-8 text into this code page.
     *
     * Characters missing from the table are transliterated to ASCII when
     * possible (for example "€" in PC852 becomes "EUR"), otherwise replaced
     * with "?". Control characters other than line feed are dropped.
     */
    public function encode(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $normalised = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;
        $text = is_string($normalised) ? $normalised : $text;

        $map = $this->map();
        $output = '';

        foreach (mb_str_split(str_replace("\r\n", "\n", $text), 1, 'UTF-8') as $character) {
            $codePoint = mb_ord($character, 'UTF-8');

            if ($codePoint === 0x0A || ($codePoint >= 0x20 && $codePoint < 0x7F)) {
                $output .= $character;
            } elseif (isset($map[$codePoint])) {
                $output .= chr($map[$codePoint]);
            } elseif ($codePoint >= 0x20 && $codePoint !== 0x7F) {
                $ascii = preg_replace('/[^\x20-\x7E]/', '', Str::ascii($character)) ?? '';
                $output .= $ascii !== '' ? $ascii : '?';
            }
        }

        return $output;
    }
}
