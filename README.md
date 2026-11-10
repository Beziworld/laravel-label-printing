# Laravel Label Printing

[![tests](https://github.com/beziworld/laravel-label-printing/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-label-printing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-label-printing.svg)](https://packagist.org/packages/beziworld/laravel-label-printing)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-label-printing.svg)](LICENSE.md)

Server side printing of shipping labels and non-fiscal receipts from Laravel:

- named printers in config: raw TCP (port 9100), CUPS (`lp -o raw`), Windows shares (`\\HOST\Printer`), files or device nodes, and a null driver
- raw passthrough of ZPL and EPL labels returned by courier APIs (InPost, DPD, DHL, GLS, UPS ...), plain or base64
- a typed ZPL II builder: text, text blocks, Code 128, EAN-13, EAN-8, Code 39, QR, boxes, lines and PNG graphics
- an ESC/POS receipt builder on top of [mike42/escpos-php](https://github.com/mike42/escpos-php): styles, alignment, columns, barcodes, QR, logo, cut, cash drawer, and Polish characters through an explicit code page (PC852 by default)
- queued `PrintJob` that retries connection failures only, `PrintSucceeded` / `PrintFailed` events
- `LabelPrinter::fake()` capturing the exact bytes, and an artisan test page command

[Polska wersja dokumentacji](README.pl.md)

## Why this package

Packagist (November 2026) has `mike42/escpos-php` (2.6M downloads) and `robgridley/zebra` (2.2M) as framework agnostic libraries, but the Laravel side is thin: `charlieuki/receiptprinter` wraps escpos-php 2.x and is no longer maintained, `abdullah-life/laravel-zebra-image-printer` only converts PNG to ZPL, and the QZ Tray / Web Serial packages print from the browser, not from the server. None of them gives you named printers, courier ZPL passthrough, queued jobs with safe retries and test fakes in one place.

This package depends on `mike42/escpos-php` ^5.0 (MIT, maintained, PHP 8.2+) for ESC/POS command rendering. The ZPL builder is written here, because `robgridley/zebra` has not been released since 2022 and calls `imagedestroy()`, which is deprecated in PHP 8.5.

## Installation

```bash
composer require beziworld/laravel-label-printing
php artisan vendor:publish --tag=label-printing-config   # optional
```

Requires PHP 8.3+, Laravel 12 or 13, `ext-intl` (required by escpos-php) and `ext-gd` for images.

```dotenv
LABEL_PRINTER=warehouse                 # default printer
LABEL_PRINTER_HOST=192.168.1.50         # Zebra on the network, raw port 9100
RECEIPT_PRINTER_HOST=192.168.1.60       # ESC/POS receipt printer
LABEL_PRINTER_CUPS_QUEUE=Zebra_ZD421    # CUPS queue name
LABEL_PRINTER_SHARE=\\SHOP-PC\Zebra     # printer shared by a Windows PC
LABEL_PRINTER_QUEUE=printing            # queue used by PrintJob
```

## Configuration

```php
// config/label-printing.php
'printers' => [
    'warehouse' => ['driver' => 'network', 'language' => 'zpl', 'host' => env('LABEL_PRINTER_HOST'), 'port' => 9100,
                    'timeout' => 5, 'dpi' => 203, 'label_width' => 812, 'label_length' => 1218],
    'receipt'   => ['driver' => 'network', 'language' => 'escpos', 'host' => env('RECEIPT_PRINTER_HOST'),
                    'profile' => 'default', 'code_page' => 'cp852', 'code_page_table' => null, 'columns' => 48],
    'office'    => ['driver' => 'cups', 'language' => 'zpl', 'destination' => 'Zebra_ZD421', 'host' => null, 'options' => []],
    'desk'      => ['driver' => 'windows', 'language' => 'zpl', 'share' => '\\\\SHOP-PC\\Zebra',
                    'username' => null, 'password' => null, 'workgroup' => null, 'method' => 'auto'],
    'capture'   => ['driver' => 'file', 'language' => 'raw', 'path' => storage_path('app/label-printing/capture.prn'), 'append' => true],
    'null'      => ['driver' => 'null', 'language' => 'raw'],
],
'queue' => ['connection' => null, 'queue' => 'printing', 'tries' => 5, 'backoff' => [5, 15, 60, 180]],
```

| Driver | How bytes are delivered |
| --- | --- |
| `network` | TCP socket to `host:port` (JetDirect / AppSocket, usually 9100) |
| `cups` | `lp [-h host] -d destination -o raw [-o ...] -` with the job on standard input |
| `windows` | On Windows: written to the UNC path. Elsewhere: `smbclient //HOST/Printer -c 'print -'`, password in the `PASSWD` environment variable. `method` forces `copy` or `smbclient` |
| `file` | `file_put_contents()` to a file or a device such as `/dev/usb/lp0`; `append` keeps a capture log |
| `null` | Discards the job |

`language` (`zpl`, `epl`, `escpos`, `raw`) protects you from sending a receipt to a label printer or the other way round; `raw` printers accept anything. Printers are validated when first used, so one incomplete definition does not break the others.

## Print courier labels (ZPL / EPL passthrough)

```php
use Beziworld\LabelPrinting\Facades\LabelPrinter;

// Raw ZPL bytes, for example from laravel-inpost or laravel-gls
LabelPrinter::printer('warehouse')->zpl($zplFromCourier);

// Base64 ZPL from a JSON API (for example DPD or DHL), two copies, with a reference for events and logs
LabelPrinter::printer('warehouse')->zplBase64($label->content, copies: 2, reference: $shipment->number);

// EPL2 labels
LabelPrinter::printer('warehouse')->epl($eplFromCourier);

// Anything else, unvalidated
LabelPrinter::printer('capture')->raw($bytes);
```

`zpl()` checks that the data contains `^XA`, `epl()` that it contains a `P` print command. Ask the courier API for ZPL or EPL: thermal printers in these modes cannot print PDF labels.

## Build a ZPL label

```php
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;

$printer = LabelPrinter::printer('warehouse');

$label = $printer->label()          // ^PW / ^LL from label_width, label_length and dpi
    ->text(40, 40, 'Zamówienie #1024', height: 50)
    ->textBlock(40, 110, 730, "ul. Długa 5/3\n30-001 Kraków", height: 32, maxLines: 3)
    ->code128(40, 230, 'ORD-1024', height: 120)
    ->ean13(40, 420, '590123412345')
    ->qr(560, 420, 'https://example.com/o/1024', magnification: 5, errorCorrection: QrErrorCorrection::Quartile)
    ->box(20, 20, 772, 600, thickness: 3)
    ->image(560, 40, file_get_contents(resource_path('logo.png')))
    ->copies(2);

$printer->zpl($label);
$label->toZpl();                     // the exact ZPL string
$label->mm(25);                      // 200 dots at 203 dpi
```

Text is sent as UTF-8 under `^CI28`; `^`, `~`, `_` and `\` are escaped with `^FH`. Whether Polish letters print depends on the font in the printer (font `0` on current Zebra firmware is the usual choice). Images are thresholded to black and white and sent as an uncompressed `^GFA` graphic; transparent pixels are white.

## Print an ESC/POS receipt

```php
$receipt = LabelPrinter::receipt()
    ->center()->bold()->doubleSize()->line('SKLEP ŻÓŁTY')->normal()
    ->center()->line('ul. Prosta 1, 00-001 Warszawa')
    ->left()->divider()
    ->columns('Kawa ziarnista 1 kg', '89,00 zł')
    ->columns('Herbata', '12,50 zł')
    ->divider('=')
    ->bold()->columns('RAZEM', '101,50 zł')->bold(false)
    ->feed()
    ->center()->barcode('ORD-1024')            // Code 128; also Barcode::Ean13, Ean8, Code39
    ->qr('https://example.com/r/1024', size: 6)
    ->line('To nie jest paragon fiskalny')
    ->feed(3)
    ->cut()
    ->openCashDrawer();

LabelPrinter::printer('receipt')->escpos($receipt);
```

The receipt starts with `ESC @` and `ESC t n` and all text is encoded into that one code page:

| `code_page` | Default `ESC t` | Polish |
| --- | --- | --- |
| `cp852` (default) | 18 | yes |
| `cp1250` | 45 | yes |
| `iso8859-2` | 39 | yes |
| `cp858` | 19 | no (Western European, euro sign) |
| `cp437` | 0 | no |

Table numbers follow the Epson numbering. Many clones use different numbers: print the test page and set `code_page_table` if the letters come out wrong. Characters missing from the code page are transliterated (`€` becomes `EUR` in PC852) or replaced with `?`. `profile` is an escpos-php capability profile (`default`, `TM-T88V`, `simple`, ...); features the profile lacks (for example QR on `simple`) raise `InvalidPayload`.

These are non-fiscal printouts: fiscal printers and cash register protocols are out of scope.

## Queue print jobs

```php
LabelPrinter::printer('warehouse')->queue()->zpl($zpl, reference: $shipment->number);
LabelPrinter::printer('receipt')->queue('redis', 'pos')->escpos($receipt);
```

Payloads are rendered before dispatch and stored base64 encoded, so the worker prints exactly the bytes you built. `PrintJob` retries **only** `ConnectionFailed` (the printer could not be reached, nothing was sent), with the configured `tries` and `backoff`. Any other error, including a connection that dropped in the middle of a job, fails the job immediately, because a retry could print the label twice. Runtime printers created with `using()` travel inside the job payload, including their credentials.

## Runtime printers and events

```php
$printer = LabelPrinter::using(['driver' => 'network', 'host' => $station->ip, 'language' => 'zpl'], 'station-'.$station->id);
$printer->zpl($zpl);
```

| Event | Payload |
| --- | --- |
| `PrintSucceeded` | `$result` (`PrintResult`: printer, endpoint, language, bytes, copies, durationMs, reference) |
| `PrintFailed` | printer, language, bytes, exception, `retryable`, reference |

Raw printer protocols have no acknowledgement: success means the transport accepted every byte, not that the label left the printer.

## Test page

```bash
php artisan label-printer:test                # default printer
php artisan label-printer:test receipt        # ESC/POS receipt with Polish sample text
php artisan label-printer:test receipt --dry-run   # show the bytes (hex for ESC/POS), send nothing
```

## Testing your code

```php
use Beziworld\LabelPrinting\Facades\LabelPrinter;

$fake = LabelPrinter::fake();

// ... code that prints

$fake->assertPrinted('warehouse');
$fake->assertPrinted('warehouse', "^XA\n...^XZ\n");
$fake->assertPrinted('receipt', fn (string $bytes) => str_contains($bytes, "\x1DVA"));
$fake->assertPrintedCount(2);
$fake->failNext();                  // simulate an unreachable printer
$fake->printed('warehouse');        // list of captured byte strings
```

## Errors

All exceptions extend `Beziworld\LabelPrinting\Exceptions\LabelPrintingException`; `isRetryable()` tells whether a retry is safe.

| Exception | When | Retried by PrintJob |
| --- | --- | --- |
| `InvalidConfiguration` | missing or invalid printer settings, unknown printer, unknown profile | no |
| `InvalidPayload` | malformed ZPL / EPL / base64, invalid barcode data, wrong language for the printer | no |
| `ConnectionFailed` | socket connect failed, CUPS scheduler or SMB host unreachable | yes |
| `TransportFailed` | write broke off, `lp` / `smbclient` rejected the job, file not writable | no |

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8, strict rules), pest
```

Tests never touch real printers: transports are faked, `lp` and `smbclient` run through `Process::fake()`, and the TCP transport is exercised against a local listener on 127.0.0.1. Builder tests assert exact bytes.

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues: [GitHub issues](https://github.com/beziworld/laravel-label-printing/issues)

Need an integration? Get in touch through [beziworld.eu](https://beziworld.eu).

### Other packages by the author

[laravel-inpost](https://github.com/beziworld/laravel-inpost) ·
[laravel-dpd](https://github.com/beziworld/laravel-dpd) ·
[laravel-dhl-parcel](https://github.com/beziworld/laravel-dhl-parcel) ·
[laravel-gls](https://github.com/beziworld/laravel-gls) ·
[laravel-ups](https://github.com/beziworld/laravel-ups)

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with Zebra Technologies, Seiko Epson, the CUPS or Samba projects, or any courier.
