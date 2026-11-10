<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\LabelPrinting')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\LabelPrinting\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\LabelPrinting\Exceptions')
    ->toExtend('Beziworld\LabelPrinting\Exceptions\LabelPrintingException')
    ->ignoring('Beziworld\LabelPrinting\Exceptions\LabelPrintingException');
