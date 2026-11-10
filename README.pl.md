# Laravel Label Printing

[![tests](https://github.com/beziworld/laravel-label-printing/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-label-printing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-label-printing.svg)](https://packagist.org/packages/beziworld/laravel-label-printing)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-label-printing.svg)](LICENSE.md)

Drukowanie etykiet wysyłkowych i wydruków niefiskalnych z Laravela, po stronie serwera:

- nazwane drukarki w konfiguracji: surowe TCP (port 9100), CUPS (`lp -o raw`), udziały Windows (`\\HOST\Drukarka`), pliki lub urządzenia oraz sterownik null
- przekazywanie bez zmian etykiet ZPL i EPL zwracanych przez API kurierów (InPost, DPD, DHL, GLS, UPS ...), także w base64
- typowany builder ZPL II: tekst, bloki tekstu, Code 128, EAN-13, EAN-8, Code 39, QR, ramki, linie i grafika z PNG
- builder paragonów ESC/POS oparty na [mike42/escpos-php](https://github.com/mike42/escpos-php): style, wyrównanie, kolumny, kody kreskowe, QR, logo, obcięcie papieru, szuflada kasowa i polskie znaki przez jawnie wybraną stronę kodową (domyślnie PC852)
- kolejkowany `PrintJob`, który ponawia wyłącznie błędy połączenia, zdarzenia `PrintSucceeded` / `PrintFailed`
- `LabelPrinter::fake()` przechwytujący dokładne bajty oraz komenda artisan drukująca stronę testową

[English documentation](README.md)

## Dlaczego ten pakiet

Na Packagist (listopad 2026) są niezależne od frameworka biblioteki `mike42/escpos-php` (2,6 mln pobrań) i `robgridley/zebra` (2,2 mln), ale po stronie Laravela niewiele: `charlieuki/receiptprinter` opakowuje escpos-php 2.x i nie jest rozwijany, `abdullah-life/laravel-zebra-image-printer` tylko konwertuje PNG na ZPL, a pakiety QZ Tray i Web Serial drukują z przeglądarki, nie z serwera. Żaden nie łączy nazwanych drukarek, przekazywania ZPL od kuriera, kolejki z bezpiecznymi ponowieniami i fake'ów do testów.

Pakiet korzysta z `mike42/escpos-php` ^5.0 (MIT, rozwijany, PHP 8.2+) do generowania komend ESC/POS. Builder ZPL jest napisany tutaj, ponieważ `robgridley/zebra` nie miał wydania od 2022 roku i wywołuje `imagedestroy()`, oznaczone jako przestarzałe w PHP 8.5.

## Instalacja

```bash
composer require beziworld/laravel-label-printing
php artisan vendor:publish --tag=label-printing-config   # opcjonalnie
```

Wymagania: PHP 8.3+, Laravel 12 lub 13, `ext-intl` (wymagane przez escpos-php) i `ext-gd` do obrazów.

```dotenv
LABEL_PRINTER=warehouse                 # drukarka domyślna
LABEL_PRINTER_HOST=192.168.1.50         # Zebra w sieci, surowy port 9100
RECEIPT_PRINTER_HOST=192.168.1.60       # drukarka paragonowa ESC/POS
LABEL_PRINTER_CUPS_QUEUE=Zebra_ZD421    # nazwa kolejki CUPS
LABEL_PRINTER_SHARE=\\SKLEP-PC\Zebra    # drukarka udostępniona z komputera z Windows
LABEL_PRINTER_QUEUE=printing            # kolejka dla PrintJob
```

## Konfiguracja

```php
// config/label-printing.php
'printers' => [
    'warehouse' => ['driver' => 'network', 'language' => 'zpl', 'host' => env('LABEL_PRINTER_HOST'), 'port' => 9100,
                    'timeout' => 5, 'dpi' => 203, 'label_width' => 812, 'label_length' => 1218],
    'receipt'   => ['driver' => 'network', 'language' => 'escpos', 'host' => env('RECEIPT_PRINTER_HOST'),
                    'profile' => 'default', 'code_page' => 'cp852', 'code_page_table' => null, 'columns' => 48],
    'office'    => ['driver' => 'cups', 'language' => 'zpl', 'destination' => 'Zebra_ZD421', 'host' => null, 'options' => []],
    'desk'      => ['driver' => 'windows', 'language' => 'zpl', 'share' => '\\\\SKLEP-PC\\Zebra',
                    'username' => null, 'password' => null, 'workgroup' => null, 'method' => 'auto'],
    'capture'   => ['driver' => 'file', 'language' => 'raw', 'path' => storage_path('app/label-printing/capture.prn'), 'append' => true],
    'null'      => ['driver' => 'null', 'language' => 'raw'],
],
'queue' => ['connection' => null, 'queue' => 'printing', 'tries' => 5, 'backoff' => [5, 15, 60, 180]],
```

| Sterownik | Jak trafiają bajty |
| --- | --- |
| `network` | gniazdo TCP do `host:port` (JetDirect / AppSocket, zwykle 9100) |
| `cups` | `lp [-h host] -d kolejka -o raw [-o ...] -` z danymi na standardowym wejściu |
| `windows` | Na Windows: zapis do ścieżki UNC. Gdzie indziej: `smbclient //HOST/Drukarka -c 'print -'`, hasło w zmiennej środowiskowej `PASSWD`. `method` wymusza `copy` lub `smbclient` |
| `file` | `file_put_contents()` do pliku lub urządzenia, np. `/dev/usb/lp0`; `append` tworzy log wydruków |
| `null` | odrzuca zadanie |

`language` (`zpl`, `epl`, `escpos`, `raw`) chroni przed wysłaniem paragonu na drukarkę etykiet i odwrotnie; drukarki `raw` przyjmują wszystko. Konfiguracja drukarki jest sprawdzana przy pierwszym użyciu, więc jedna niekompletna definicja nie psuje pozostałych.

## Etykiety kurierskie (przekazywanie ZPL / EPL)

```php
use Beziworld\LabelPrinting\Facades\LabelPrinter;

// Surowy ZPL, np. z laravel-inpost albo laravel-gls
LabelPrinter::printer('warehouse')->zpl($zplOdKuriera);

// ZPL w base64 z API JSON (np. DPD lub DHL), dwie kopie, z referencją do zdarzeń i logów
LabelPrinter::printer('warehouse')->zplBase64($label->content, copies: 2, reference: $shipment->number);

// Etykiety EPL2
LabelPrinter::printer('warehouse')->epl($eplOdKuriera);

// Cokolwiek innego, bez walidacji
LabelPrinter::printer('capture')->raw($bytes);
```

`zpl()` sprawdza, czy dane zawierają `^XA`, a `epl()`, czy zawierają komendę drukowania `P`. Zamawiaj w API kuriera etykiety ZPL lub EPL: drukarka termiczna w tych trybach nie wydrukuje PDF.

## Budowanie etykiety ZPL

```php
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;

$printer = LabelPrinter::printer('warehouse');

$label = $printer->label()          // ^PW / ^LL z label_width, label_length i dpi
    ->text(40, 40, 'Zamówienie #1024', height: 50)
    ->textBlock(40, 110, 730, "ul. Długa 5/3\n30-001 Kraków", height: 32, maxLines: 3)
    ->code128(40, 230, 'ORD-1024', height: 120)
    ->ean13(40, 420, '590123412345')
    ->qr(560, 420, 'https://example.com/o/1024', magnification: 5, errorCorrection: QrErrorCorrection::Quartile)
    ->box(20, 20, 772, 600, thickness: 3)
    ->image(560, 40, file_get_contents(resource_path('logo.png')))
    ->copies(2);

$printer->zpl($label);
$label->toZpl();                     // dokładny ciąg ZPL
$label->mm(25);                      // 200 punktów przy 203 dpi
```

Tekst idzie jako UTF-8 pod `^CI28`; znaki `^`, `~`, `_` i `\` są escapowane przez `^FH`. To, czy polskie litery się wydrukują, zależy od czcionki w drukarce (zwykle używa się czcionki `0` w aktualnym firmware Zebry). Obrazy są progowane do czerni i bieli i wysyłane jako nieskompresowana grafika `^GFA`; przezroczyste piksele są białe.

## Paragon ESC/POS

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
    ->center()->barcode('ORD-1024')            // Code 128; także Barcode::Ean13, Ean8, Code39
    ->qr('https://example.com/r/1024', size: 6)
    ->line('To nie jest paragon fiskalny')
    ->feed(3)
    ->cut()
    ->openCashDrawer();

LabelPrinter::printer('receipt')->escpos($receipt);
```

Paragon zaczyna się od `ESC @` i `ESC t n`, a cały tekst jest kodowany w tej jednej stronie kodowej:

| `code_page` | Domyślne `ESC t` | Polskie znaki |
| --- | --- | --- |
| `cp852` (domyślna) | 18 | tak |
| `cp1250` | 45 | tak |
| `iso8859-2` | 39 | tak |
| `cp858` | 19 | nie (Europa Zachodnia, znak euro) |
| `cp437` | 0 | nie |

Numery tablic są zgodne z numeracją Epsona. Wiele klonów numeruje inaczej: wydrukuj stronę testową i ustaw `code_page_table`, jeśli litery wychodzą błędnie. Znaki spoza strony kodowej są transliterowane (`€` staje się `EUR` w PC852) albo zastępowane `?`. `profile` to profil możliwości escpos-php (`default`, `TM-T88V`, `simple`, ...); funkcje, których profil nie ma (np. QR w `simple`), zgłaszają `InvalidPayload`.

To wydruk niefiskalny: pakiet nie obsługuje drukarek fiskalnych ani protokołów kas fiskalnych.

## Kolejka

```php
LabelPrinter::printer('warehouse')->queue()->zpl($zpl, reference: $shipment->number);
LabelPrinter::printer('receipt')->queue('redis', 'pos')->escpos($receipt);
```

Dane są renderowane przed wysłaniem do kolejki i zapisywane w base64, więc worker drukuje dokładnie zbudowane bajty. `PrintJob` ponawia **wyłącznie** `ConnectionFailed` (drukarka nieosiągalna, nic nie zostało wysłane), zgodnie z `tries` i `backoff`. Każdy inny błąd, także zerwane w połowie połączenie, od razu oznacza zadanie jako nieudane, bo ponowienie mogłoby wydrukować etykietę dwa razy. Drukarki utworzone przez `using()` trafiają do payloadu zadania razem z danymi logowania.

## Drukarki w locie i zdarzenia

```php
$printer = LabelPrinter::using(['driver' => 'network', 'host' => $station->ip, 'language' => 'zpl'], 'station-'.$station->id);
$printer->zpl($zpl);
```

| Zdarzenie | Dane |
| --- | --- |
| `PrintSucceeded` | `$result` (`PrintResult`: printer, endpoint, language, bytes, copies, durationMs, reference) |
| `PrintFailed` | printer, language, bytes, exception, `retryable`, reference |

Surowe protokoły drukarek nie mają potwierdzeń: sukces oznacza, że transport przyjął wszystkie bajty, a nie że etykieta wyszła z drukarki.

## Strona testowa

```bash
php artisan label-printer:test                # drukarka domyślna
php artisan label-printer:test receipt        # paragon ESC/POS z polskim tekstem
php artisan label-printer:test receipt --dry-run   # pokaż bajty (hex dla ESC/POS), nic nie wysyłaj
```

## Testy w aplikacji

```php
use Beziworld\LabelPrinting\Facades\LabelPrinter;

$fake = LabelPrinter::fake();

// ... kod, który drukuje

$fake->assertPrinted('warehouse');
$fake->assertPrinted('warehouse', "^XA\n...^XZ\n");
$fake->assertPrinted('receipt', fn (string $bytes) => str_contains($bytes, "\x1DVA"));
$fake->assertPrintedCount(2);
$fake->failNext();                  // symulacja nieosiągalnej drukarki
$fake->printed('warehouse');        // lista przechwyconych ciągów bajtów
```

## Błędy

Wszystkie wyjątki dziedziczą po `Beziworld\LabelPrinting\Exceptions\LabelPrintingException`; `isRetryable()` mówi, czy ponowienie jest bezpieczne.

| Wyjątek | Kiedy | Ponawiany przez PrintJob |
| --- | --- | --- |
| `InvalidConfiguration` | brakujące lub błędne ustawienia, nieznana drukarka, nieznany profil | nie |
| `InvalidPayload` | błędny ZPL / EPL / base64, błędne dane kodu kreskowego, zły język dla drukarki | nie |
| `ConnectionFailed` | nieudane połączenie TCP, nieosiągalny serwer CUPS lub host SMB | tak |
| `TransportFailed` | przerwany zapis, `lp` / `smbclient` odrzucił zadanie, plik niezapisywalny | nie |

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (poziom 8, strict rules), pest
```

Testy nigdy nie dotykają prawdziwych drukarek: transporty są podmieniane, `lp` i `smbclient` działają przez `Process::fake()`, a transport TCP jest sprawdzany na lokalnym nasłuchu 127.0.0.1. Testy builderów porównują dokładne bajty.

## Autor

Pakiet tworzy i rozwija **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia: [GitHub issues](https://github.com/beziworld/laravel-label-printing/issues)

Potrzebujesz integracji? Skontaktuj się przez [beziworld.eu](https://beziworld.eu).

### Inne pakiety autora

[laravel-inpost](https://github.com/beziworld/laravel-inpost) ·
[laravel-dpd](https://github.com/beziworld/laravel-dpd) ·
[laravel-dhl-parcel](https://github.com/beziworld/laravel-dhl-parcel) ·
[laravel-gls](https://github.com/beziworld/laravel-gls) ·
[laravel-ups](https://github.com/beziworld/laravel-ups)

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z Zebra Technologies, Seiko Epson, projektami CUPS i Samba ani z żadnym kurierem.
