<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Data;

use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\Exceptions\InvalidPayload;

/**
 * Raw bytes ready for a printer, together with their command language.
 */
final readonly class PrintPayload
{
    public function __construct(
        public string $bytes,
        public Language $language,
        public int $copies = 1,
        public ?string $reference = null,
    ) {
        if ($bytes === '') {
            throw InvalidPayload::because('The print payload is empty.');
        }

        if ($copies < 1 || $copies > 999) {
            throw InvalidPayload::because('The number of copies must be between 1 and 999.');
        }
    }

    /**
     * Raw ZPL II, for example a label returned by a courier API.
     */
    public static function zpl(string $zpl, int $copies = 1, ?string $reference = null): self
    {
        if (stripos($zpl, '^XA') === false) {
            throw InvalidPayload::because('The ZPL payload does not contain a ^XA format start command.');
        }

        return new self($zpl, Language::Zpl, $copies, $reference);
    }

    /**
     * Base64 encoded ZPL, the form most courier JSON APIs use.
     */
    public static function zplBase64(string $base64, int $copies = 1, ?string $reference = null): self
    {
        return self::zpl(self::decode($base64), $copies, $reference);
    }

    /**
     * Raw EPL2, the older Eltron/Zebra page description language.
     */
    public static function epl(string $epl, int $copies = 1, ?string $reference = null): self
    {
        if (preg_match('/^P\d+/m', $epl) !== 1) {
            throw InvalidPayload::because('The EPL payload does not contain a P (print) command.');
        }

        return new self($epl, Language::Epl, $copies, $reference);
    }

    public static function eplBase64(string $base64, int $copies = 1, ?string $reference = null): self
    {
        return self::epl(self::decode($base64), $copies, $reference);
    }

    /**
     * Bytes sent to the printer without any validation.
     */
    public static function raw(string $bytes, Language $language = Language::Raw, int $copies = 1, ?string $reference = null): self
    {
        return new self($bytes, $language, $copies, $reference);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bytes = is_string($data['bytes'] ?? null) ? base64_decode($data['bytes'], true) : false;

        if ($bytes === false) {
            throw InvalidPayload::because('The serialised print payload is not valid base64.');
        }

        $language = is_string($data['language'] ?? null) ? Language::tryFrom($data['language']) : null;

        return new self(
            $bytes,
            $language ?? Language::Raw,
            is_int($data['copies'] ?? null) ? $data['copies'] : 1,
            is_string($data['reference'] ?? null) ? $data['reference'] : null,
        );
    }

    /**
     * Queue friendly form: queue payloads are JSON, so binary bytes travel as base64.
     *
     * @return array{bytes: string, language: string, copies: int, reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'bytes' => base64_encode($this->bytes),
            'language' => $this->language->value,
            'copies' => $this->copies,
            'reference' => $this->reference,
        ];
    }

    /**
     * Bytes written to the transport: the payload repeated once per copy.
     */
    public function output(): string
    {
        return str_repeat($this->bytes, $this->copies);
    }

    private static function decode(string $base64): string
    {
        $decoded = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);

        if ($decoded === false || $decoded === '') {
            throw InvalidPayload::because('The label is not valid base64.');
        }

        return $decoded;
    }
}
