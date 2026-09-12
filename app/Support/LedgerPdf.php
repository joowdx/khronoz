<?php

namespace App\Support;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\Routing\UrlGenerator;
use InvalidArgumentException;
use Spatie\LaravelPdf\Facades\Pdf;

class LedgerPdf
{
    public function __construct(private UrlGenerator $urls) {}

    public function render(array $snapshot, string $template = 'form48', ?string $token = null): string
    {
        if (! in_array($template, ['form48', 'plain'], true)) {
            throw new InvalidArgumentException('Unsupported ledger template.');
        }

        [$paperWidth, $paperHeight, $paperUnit, $marginTop, $marginRight, $marginBottom, $marginLeft, $marginUnit] = match ($template) {
            'form48' => [8.5, 14, 'in', 0.4, 0.4, 0.4, 0.4, 'in'],
            'plain' => [210, 297, 'mm', 12, 12, 12, 12, 'mm'],
        };

        $verificationUrl = $token === null ? null : $this->urls->route('ledgers.verify', ['token' => $token]);
        $qrSvg = $verificationUrl === null ? null : (new SvgWriter)->write(new QrCode(data: $verificationUrl))->getString();

        return base64_decode(Pdf::view('pdf.ledgers.'.$template, [
            'snapshot' => $snapshot,
            'preview' => $token === null,
            'verificationUrl' => $verificationUrl,
            'qrSvg' => $qrSvg,
        ])->driver('gotenberg')->paperSize($paperWidth, $paperHeight, $paperUnit)->margins($marginTop, $marginRight, $marginBottom, $marginLeft, $marginUnit)->base64(), true);
    }
}
