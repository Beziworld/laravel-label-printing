<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Transports;

use Beziworld\LabelPrinting\Exceptions\ConnectionFailed;
use Beziworld\LabelPrinting\Exceptions\LabelPrintingException;
use Beziworld\LabelPrinting\PrinterConfig;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * In-memory transport that captures every job instead of printing it.
 *
 * Installed by LabelPrinter::fake(); it never opens sockets, runs commands
 * or touches files, and offers assertions on the exact bytes sent.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{printer: string, bytes: string}> */
    private array $jobs = [];

    /** @var list<LabelPrintingException> */
    private array $failures = [];

    public function send(string $bytes, PrinterConfig $printer): void
    {
        $failure = array_shift($this->failures);

        if ($failure !== null) {
            throw $failure;
        }

        $this->jobs[] = ['printer' => $printer->name, 'bytes' => $bytes];
    }

    /**
     * Make the next jobs fail with the given exception, or with a connection failure when none is given.
     */
    public function failNext(int $times = 1, ?LabelPrintingException $exception = null): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[] = $exception ?? ConnectionFailed::to('fake', 'tcp://fake:9100', 'Connection refused', 111);
        }

        return $this;
    }

    /**
     * Captured jobs, optionally limited to one printer.
     *
     * @return list<string>
     */
    public function printed(?string $printer = null): array
    {
        return array_values(array_map(
            static fn(array $job): string => $job['bytes'],
            array_filter($this->jobs, static fn(array $job): bool => $printer === null || $job['printer'] === $printer),
        ));
    }

    /**
     * Assert that a job was sent to the printer, optionally matching exact bytes or a callback.
     *
     * @param string|(\Closure(string): bool)|null $expected
     */
    public function assertPrinted(string $printer, string|\Closure|null $expected = null): void
    {
        $jobs = $this->printed($printer);

        PHPUnit::assertNotEmpty($jobs, sprintf('No job was sent to printer "%s".', $printer));

        if ($expected === null) {
            return;
        }

        $matches = array_filter($jobs, static fn(string $bytes): bool => is_string($expected) ? $bytes === $expected : $expected($bytes));

        PHPUnit::assertNotEmpty($matches, sprintf('No job sent to printer "%s" matched the expectation.', $printer));
    }

    public function assertPrintedCount(int $count, ?string $printer = null): void
    {
        PHPUnit::assertCount($count, $this->printed($printer));
    }

    public function assertNothingPrinted(): void
    {
        PHPUnit::assertSame([], $this->jobs, 'Unexpected print jobs were sent.');
    }
}
