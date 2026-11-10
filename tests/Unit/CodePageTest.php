<?php

declare(strict_types=1);

use Beziworld\LabelPrinting\EscPos\CodePage;
use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;

const POLISH = 'ĄąĆćĘęŁłŃńÓóŚśŹźŻż';

it('encodes Polish letters in PC852', function (): void {
    expect(bin2hex(CodePage::Cp852->encode(POLISH)))
        ->toBe('a4a58f86a8a99d88e3e4e0a297988dabbdbe');
});

it('encodes Polish letters in WPC1250', function (): void {
    expect(bin2hex(CodePage::Cp1250->encode(POLISH)))
        ->toBe('a5b9c6e6caeaa3b3d1f1d3f38c9c8f9fafbf');
});

it('encodes Polish letters in ISO 8859-2', function (): void {
    expect(bin2hex(CodePage::Iso8859_2->encode(POLISH)))
        ->toBe('a1b1c6e6caeaa3b3d1f1d3f3a6b6acbcafbf');
});

it('keeps ASCII and line feeds and drops carriage returns and control characters', function (): void {
    expect(CodePage::Cp852->encode("Suma:\r\n12,00\x07"))->toBe("Suma:\n12,00");
});

it('transliterates characters missing from the code page', function (): void {
    expect(CodePage::Cp852->encode('Cena 5 €'))->toBe('Cena 5 EUR')
        ->and(CodePage::Cp437->encode('Łódź'))->toBe("L\xA2dz")
        ->and(CodePage::Cp852->encode('ok 😀'))->toBe('ok ?');
});

it('composes decomposed characters before encoding', function (): void {
    expect(bin2hex(CodePage::Cp852->encode("a\u{0328}")))->toBe('a5');
});

it('maps code pages to Epson ESC t table numbers', function (): void {
    expect(CodePage::Cp852->table())->toBe(18)
        ->and(CodePage::Cp1250->table())->toBe(45)
        ->and(CodePage::Iso8859_2->table())->toBe(39)
        ->and(CodePage::Cp858->table())->toBe(19)
        ->and(CodePage::Cp437->table())->toBe(0);
});

it('accepts common aliases in configuration', function (string $alias, CodePage $expected): void {
    expect(CodePage::fromConfig($alias))->toBe($expected);
})->with([
    ['PC852', CodePage::Cp852],
    ['windows-1250', CodePage::Cp1250],
    ['ISO-8859-2', CodePage::Iso8859_2],
    ['cp858', CodePage::Cp858],
]);

it('rejects unknown code pages', function (): void {
    CodePage::fromConfig('mazovia');
})->throws(InvalidConfiguration::class, 'mazovia');
