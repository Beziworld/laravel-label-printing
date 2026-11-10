# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Named printers with network (raw TCP 9100), CUPS, Windows share, file and null drivers.
- Raw ZPL and EPL passthrough for courier labels, including base64 input.
- ZPL II builder: text, text blocks, Code 128, EAN-13, EAN-8, Code 39, QR, boxes, lines and PNG graphics.
- ESC/POS receipt builder on mike42/escpos-php with explicit code pages (PC852, WPC1250, ISO 8859-2, PC858, PC437).
- Queued `PrintJob` retrying connection failures only; `PrintSucceeded` and `PrintFailed` events.
- `LabelPrinter::fake()` with byte level assertions and the `label-printer:test` command.
