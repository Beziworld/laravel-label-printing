<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default printer
    |--------------------------------------------------------------------------
    |
    | Used by LabelPrinter::zpl(), LabelPrinter::escpos() and the test command
    | when no printer name is given.
    |
    */

    'default' => env('LABEL_PRINTER', 'warehouse'),

    /*
    |--------------------------------------------------------------------------
    | Printers
    |--------------------------------------------------------------------------
    |
    | Drivers: "network" (raw TCP, port 9100), "cups" (lp -o raw), "windows"
    | (\\HOST\Printer share), "file" (file or device node) and "null".
    | Languages: "zpl", "epl", "escpos" and "raw".
    |
    */

    'printers' => [

        'warehouse' => [
            'driver' => 'network',
            'language' => 'zpl',
            'host' => env('LABEL_PRINTER_HOST'),
            'port' => (int) env('LABEL_PRINTER_PORT', 9100),
            'timeout' => 5,
            'dpi' => 203,
            'label_width' => 812,
            'label_length' => 1218,
        ],

        'receipt' => [
            'driver' => 'network',
            'language' => 'escpos',
            'host' => env('RECEIPT_PRINTER_HOST'),
            'port' => 9100,
            'timeout' => 5,
            'profile' => 'default',
            'code_page' => 'cp852',
            'code_page_table' => null,
            'columns' => 48,
        ],

        'office' => [
            'driver' => 'cups',
            'language' => 'zpl',
            'destination' => env('LABEL_PRINTER_CUPS_QUEUE'),
            'host' => env('CUPS_SERVER'),
            'options' => [],
        ],

        'desk' => [
            'driver' => 'windows',
            'language' => 'zpl',
            'share' => env('LABEL_PRINTER_SHARE'),
            'username' => env('LABEL_PRINTER_SHARE_USER'),
            'password' => env('LABEL_PRINTER_SHARE_PASSWORD'),
            'workgroup' => env('LABEL_PRINTER_SHARE_WORKGROUP'),
            'method' => 'auto',
        ],

        'capture' => [
            'driver' => 'file',
            'language' => 'raw',
            'path' => env('LABEL_PRINTER_CAPTURE_PATH', storage_path('app/label-printing/capture.prn')),
            'append' => true,
        ],

        'null' => [
            'driver' => 'null',
            'language' => 'raw',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued printing
    |--------------------------------------------------------------------------
    |
    | PrintJob retries connection failures only; every other failure is final
    | so that a label is never printed twice.
    |
    */

    'queue' => [
        'connection' => env('LABEL_PRINTER_QUEUE_CONNECTION'),
        'queue' => env('LABEL_PRINTER_QUEUE'),
        'tries' => 5,
        'backoff' => [5, 15, 60, 180],
    ],
];
