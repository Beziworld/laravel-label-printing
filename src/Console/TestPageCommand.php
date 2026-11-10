<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Console;

use Beziworld\LabelPrinting\Data\PrintPayload;
use Beziworld\LabelPrinting\Enums\Language;
use Beziworld\LabelPrinting\Enums\QrErrorCorrection;
use Beziworld\LabelPrinting\Exceptions\LabelPrintingException;
use Beziworld\LabelPrinting\LabelPrinterManager;
use Beziworld\LabelPrinting\Printer;
use Illuminate\Console\Command;

/**
 * Sends a test page in the printer's own language (ZPL, EPL or ESC/POS).
 */
final class TestPageCommand extends Command
{
    public const string SAMPLE = 'Zażółć gęślą jaźń ĄĆĘŁŃÓŚŹŻ';

    /** @var string */
    protected $signature = 'label-printer:test
        {printer? : Printer name from config/label-printing.php (default printer when omitted)}
        {--dry-run : Render the test page and show it without sending it}';

    /** @var string */
    protected $description = 'Send a test page to a configured label or receipt printer';

    public function handle(LabelPrinterManager $manager): int
    {
        $name = $this->input->getArgument('printer');
        $dryRun = $this->input->getOption('dry-run') === true;

        try {
            $printer = $manager->printer(is_string($name) && $name !== '' ? $name : null);
            $payload = $this->testPage($printer);

            $this->components->twoColumnDetail('Printer', $printer->name());
            $this->components->twoColumnDetail('Endpoint', $printer->config()->endpoint());
            $this->components->twoColumnDetail('Language', strtoupper($payload->language->value));
            $this->components->twoColumnDetail('Size', strlen($payload->bytes) . ' bytes');

            if ($dryRun) {
                $this->line($payload->language === Language::EscPos ? $this->hexDump($payload->bytes) : $payload->bytes);
                $this->components->info('Dry run: nothing was sent.');

                return self::SUCCESS;
            }

            $result = $printer->send($payload);
        } catch (LabelPrintingException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Test page sent to "%s" in %.1f ms.', $result->printer, $result->durationMs));

        return self::SUCCESS;
    }

    private function testPage(Printer $printer): PrintPayload
    {
        $config = $printer->config();
        $stamp = date('Y-m-d H:i:s');

        return match ($config->language) {
            Language::Zpl, Language::Raw => PrintPayload::zpl($printer->label()
                ->text(30, 30, 'beziworld/laravel-label-printing', 30)
                ->text(30, 75, 'Printer: ' . $config->name, 28)
                ->text(30, 115, self::SAMPLE, 28)
                ->text(30, 155, $stamp, 24)
                ->code128(30, 200, 'TEST-' . date('Ymd'), 80)
                ->qr(30, 330, 'https://beziworld.eu', 4)
                ->toZpl(), reference: 'test-page'),
            Language::Epl => PrintPayload::epl(implode("\n", [
                '',
                'N',
                'A30,30,0,3,1,1,N,"laravel-label-printing"',
                'A30,70,0,2,1,1,N,"Printer: ' . str_replace('"', "'", $config->name) . '"',
                'A30,100,0,2,1,1,N,"' . $stamp . '"',
                'B30,140,0,1,2,6,80,B,"TEST' . date('Ymd') . '"',
                'P1',
                '',
            ]), reference: 'test-page'),
            Language::EscPos => $printer->escposPayload($printer->receipt()
                ->center()->bold()->doubleSize()->line('TEST')->normal()
                ->center()->line('beziworld/laravel-label-printing')
                ->left()->divider()
                ->columns('Printer', $config->name)
                ->columns('Code page', strtoupper($config->codePage->value) . ' (ESC t ' . $config->characterTable() . ')')
                ->columns('Date', $stamp)
                ->divider()
                ->line(self::SAMPLE)
                ->feed()
                ->center()->barcode('TEST' . date('Ymd'))
                ->feed()
                ->qr('https://beziworld.eu', 6, QrErrorCorrection::Medium)
                ->feed(2)
                ->cut(), reference: 'test-page'),
        };
    }

    private function hexDump(string $bytes): string
    {
        return implode("\n", array_map(
            static fn(string $chunk): string => implode(' ', str_split(strtoupper(bin2hex($chunk)), 2)),
            str_split($bytes, 16),
        ));
    }
}
