<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting;

use Beziworld\LabelPrinting\Enums\Driver;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\EscPos\CodePage;
use Beziworld\LabelPrinting\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable definition of a single named printer.
 */
final readonly class PrinterConfig
{
    public const int DEFAULT_PORT = 9100;

    /**
     * @param array<string, string|null> $cupsOptions Extra "lp -o" options; a null value passes the bare option name.
     */
    public function __construct(
        public string $name,
        public Driver $driver,
        public Language $language = Language::Zpl,
        public ?string $host = null,
        public int $port = self::DEFAULT_PORT,
        public float $timeout = 5.0,
        public ?string $destination = null,
        public array $cupsOptions = [],
        public string $lpBinary = 'lp',
        public ?string $share = null,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public ?string $workgroup = null,
        public string $windowsMethod = 'auto',
        public string $smbclientBinary = 'smbclient',
        public ?string $path = null,
        public bool $append = false,
        public string $profile = 'default',
        public CodePage $codePage = CodePage::Cp852,
        public ?int $codePageTable = null,
        public int $columns = 48,
        public int $dpi = 203,
        public ?int $labelWidth = null,
        public ?int $labelLength = null,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $key = static fn(string $option): string => sprintf('printers.%s.%s', $name, $option);
        $string = static fn(string $option): ?string => is_scalar($config[$option] ?? null) && trim((string) $config[$option]) !== '' ? trim((string) $config[$option]) : null;
        $int = static function (string $option, int $default, int $min, int $max) use ($config, $key): int {
            $value = $config[$option] ?? null;

            if ($value === null || $value === '') {
                return $default;
            }

            if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
                throw InvalidConfiguration::invalid($key($option), sprintf('expected an integer between %d and %d.', $min, $max));
            }

            return (int) $value;
        };
        $nullableInt = static fn(string $option, int $min, int $max): ?int => ($config[$option] ?? null) === null || ($config[$option] ?? null) === ''
            ? null
            : $int($option, 0, $min, $max);

        $driverName = $string('driver') ?? throw InvalidConfiguration::missing($key('driver'));
        $driver = Driver::tryFrom(strtolower($driverName))
            ?? throw InvalidConfiguration::invalid($key('driver'), sprintf('"%s" is not one of network, cups, windows, file, null.', $driverName));

        $languageName = $string('language') ?? Language::Zpl->value;
        $language = Language::tryFrom(strtolower(str_replace(['/', '-', '_'], '', $languageName)))
            ?? throw InvalidConfiguration::invalid($key('language'), sprintf('"%s" is not one of zpl, epl, escpos, raw.', $languageName));

        $timeout = $config['timeout'] ?? 5;
        if (! is_numeric($timeout) || (float) $timeout <= 0) {
            throw InvalidConfiguration::invalid($key('timeout'), 'expected a positive number of seconds.');
        }

        $windowsMethod = strtolower($string('method') ?? 'auto');
        if (! in_array($windowsMethod, ['auto', 'copy', 'smbclient'], true)) {
            throw InvalidConfiguration::invalid($key('method'), 'expected auto, copy or smbclient.');
        }

        $instance = new self(
            name: $name,
            driver: $driver,
            language: $language,
            host: $string('host'),
            port: $int('port', self::DEFAULT_PORT, 1, 65535),
            timeout: (float) $timeout,
            destination: $string('destination') ?? $string('queue'),
            cupsOptions: self::options($config['options'] ?? [], $key('options')),
            lpBinary: $string('lp_binary') ?? 'lp',
            share: $string('share'),
            username: $string('username'),
            password: is_scalar($config['password'] ?? null) && (string) $config['password'] !== '' ? (string) $config['password'] : null,
            workgroup: $string('workgroup'),
            windowsMethod: $windowsMethod,
            smbclientBinary: $string('smbclient_binary') ?? 'smbclient',
            path: $string('path'),
            append: filter_var($config['append'] ?? false, FILTER_VALIDATE_BOOL),
            profile: $string('profile') ?? 'default',
            codePage: CodePage::fromConfig($string('code_page') ?? CodePage::Cp852->value),
            codePageTable: $nullableInt('code_page_table', 0, 255),
            columns: $int('columns', 48, 8, 255),
            dpi: $int('dpi', 203, 100, 600),
            labelWidth: $nullableInt('label_width', 2, 32000),
            labelLength: $nullableInt('label_length', 1, 32000),
        );

        $instance->assertDriverRequirements($key);

        return $instance;
    }

    /**
     * Printer as an array accepted by fromArray(), used to rebuild runtime printers inside queued jobs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'language' => $this->language->value,
            'host' => $this->host,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'destination' => $this->destination,
            'options' => $this->cupsOptions,
            'lp_binary' => $this->lpBinary,
            'share' => $this->share,
            'username' => $this->username,
            'password' => $this->password,
            'workgroup' => $this->workgroup,
            'method' => $this->windowsMethod,
            'smbclient_binary' => $this->smbclientBinary,
            'path' => $this->path,
            'append' => $this->append,
            'profile' => $this->profile,
            'code_page' => $this->codePage->value,
            'code_page_table' => $this->codePageTable,
            'columns' => $this->columns,
            'dpi' => $this->dpi,
            'label_width' => $this->labelWidth,
            'label_length' => $this->labelLength,
        ];
    }

    /**
     * Human readable endpoint used in messages and the test command.
     */
    public function endpoint(): string
    {
        return match ($this->driver) {
            Driver::Network => sprintf('tcp://%s:%d', $this->host, $this->port),
            Driver::Cups => sprintf('cups:%s', $this->destination),
            Driver::Windows => (string) $this->share,
            Driver::File => (string) $this->path,
            Driver::Null => 'null',
        };
    }

    /**
     * "ESC t" table number actually sent to the printer.
     */
    public function characterTable(): int
    {
        return $this->codePageTable ?? $this->codePage->table();
    }

    /**
     * @param \Closure(string): string $key
     */
    private function assertDriverRequirements(\Closure $key): void
    {
        match ($this->driver) {
            Driver::Network => $this->host ?? throw InvalidConfiguration::missing($key('host')),
            Driver::Cups => $this->destination ?? throw InvalidConfiguration::missing($key('destination')),
            Driver::Windows => $this->assertShare($key('share')),
            Driver::File => $this->path ?? throw InvalidConfiguration::missing($key('path')),
            Driver::Null => null,
        };
    }

    private function assertShare(string $key): string
    {
        $share = $this->share ?? throw InvalidConfiguration::missing($key);

        if (preg_match('~^(\\\\\\\\|//)[^\\\\/]+[\\\\/][^\\\\/]+$~', $share) !== 1) {
            throw InvalidConfiguration::invalid($key, 'expected a UNC path such as \\\\HOST\\Printer or //HOST/Printer.');
        }

        return $share;
    }

    /**
     * @return array<string, string|null>
     */
    private static function options(mixed $options, string $key): array
    {
        if (! is_array($options)) {
            throw InvalidConfiguration::invalid($key, 'expected an array of CUPS options.');
        }

        $normalised = [];

        foreach ($options as $name => $value) {
            if (is_int($name) && is_string($value) && $value !== '') {
                $normalised[$value] = null;
            } elseif (is_string($name) && $name !== '' && (is_scalar($value) || $value === null)) {
                $normalised[$name] = $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            } else {
                throw InvalidConfiguration::invalid($key, 'expected option names mapped to scalar values.');
            }
        }

        return $normalised;
    }
}
