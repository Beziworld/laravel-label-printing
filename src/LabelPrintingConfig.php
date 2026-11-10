<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting;

use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable view of the "label-printing" configuration file.
 *
 * Printers are validated lazily when resolved, so an incomplete definition of
 * one printer never prevents the others from working.
 */
final readonly class LabelPrintingConfig
{
    /**
     * @param array<string, array<array-key, mixed>> $printers
     * @param list<int> $backoff
     */
    public function __construct(
        public ?string $default,
        public array $printers,
        public ?string $queueConnection = null,
        public ?string $queueName = null,
        public int $tries = 5,
        public array $backoff = [5, 15, 60],
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $string = static fn(mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;

        $printers = [];
        foreach (is_array($config['printers'] ?? null) ? $config['printers'] : [] as $name => $printer) {
            if (! is_string($name) || $name === '') {
                throw InvalidConfiguration::invalid('printers', 'every printer needs a string name.');
            }

            if (! is_array($printer)) {
                throw InvalidConfiguration::invalid('printers.' . $name, 'expected an array.');
            }

            $printers[$name] = $printer;
        }

        $queue = is_array($config['queue'] ?? null) ? $config['queue'] : [];
        $tries = $queue['tries'] ?? 5;
        if (! is_numeric($tries) || (int) $tries < 1) {
            throw InvalidConfiguration::invalid('queue.tries', 'expected a positive integer.');
        }

        $backoff = $queue['backoff'] ?? [5, 15, 60];
        $backoff = is_array($backoff) ? $backoff : [$backoff];
        $seconds = [];
        foreach ($backoff as $value) {
            if (! is_numeric($value) || (int) $value < 0) {
                throw InvalidConfiguration::invalid('queue.backoff', 'expected seconds as non-negative integers.');
            }
            $seconds[] = (int) $value;
        }

        return new self(
            default: $string($config['default'] ?? null),
            printers: $printers,
            queueConnection: $string($queue['connection'] ?? null),
            queueName: $string($queue['queue'] ?? null),
            tries: (int) $tries,
            backoff: $seconds,
        );
    }

    /**
     * Resolve and validate a named printer; null selects the default printer.
     */
    public function printer(?string $name = null): PrinterConfig
    {
        $name ??= $this->default ?? throw InvalidConfiguration::noDefaultPrinter();

        $printer = $this->printers[$name] ?? throw InvalidConfiguration::unknownPrinter($name);

        return PrinterConfig::fromArray($name, $printer);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->printers);
    }
}
