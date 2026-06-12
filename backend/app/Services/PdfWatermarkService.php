<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Http;
use Throwable;

class PdfWatermarkService
{
    public function addStampToDocument(array $document, string $text): array
    {
        $text = $this->normalizeText($text);

        if ($text === '') {
            return $this->withWatermarkError($document, 'Teks watermark kosong.');
        }

        $pdfBytes = $this->documentPdfBytes($document);
        if ($pdfBytes === '') {
            return $this->withWatermarkError($document, 'File PDF dokumen resmi tidak bisa dibaca untuk watermark.');
        }

        try {
            $watermarkedBytes = $this->addStampToPdfBytes($pdfBytes, $text);
        } catch (Throwable $exception) {
            return $this->withWatermarkError($document, $exception->getMessage(), $pdfBytes);
        }

        unset($document['url']);

        return [
            ...$document,
            'source' => 'base64',
            'content_base64' => base64_encode($watermarkedBytes),
            'filename' => $this->watermarkedFilename((string) ($document['filename'] ?? 'shipping-document.pdf')),
            'watermark' => [
                'text' => $text,
                'opacity' => 1,
                'position' => 'footer',
            ],
        ];
    }

    private function addStampToPdfBytes(string $pdfBytes, string $text): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'marketplace-label-in-');
        if ($inputPath === false) {
            throw new \RuntimeException('Temp file PDF tidak bisa dibuat.');
        }

        file_put_contents($inputPath, $pdfBytes);

        try {
            $pdf = new WatermarkedFpdi('P', 'mm');
            $pdf->SetAutoPageBreak(false);
            $pdf->SetCompression(false);
            $pageCount = $pdf->setSourceFile($inputPath);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);
                $width = (float) $size['width'];
                $height = (float) $size['height'];
                $orientation = $width > $height ? 'L' : 'P';

                $pdf->AddPage($orientation, [$width, $height]);
                $pdf->useTemplate($template, 0, 0, $width, $height);
                $this->stampFooter($pdf, $text, $width, $height);
            }

            return $pdf->Output('S');
        } finally {
            @unlink($inputPath);
        }
    }

    private function stampFooter(WatermarkedFpdi $pdf, string $text, float $width, float $height): void
    {
        $maxWidth = max(48.0, min($width - 16.0, 84.0));
        $fontSize = 14;
        $stampText = $this->toPdfText(mb_strtoupper($text));

        do {
            $pdf->SetFont('Arial', 'B', $fontSize);
            $textWidth = $pdf->GetStringWidth($stampText) + 12;
            $fontSize--;
        } while ($textWidth > $maxWidth && $fontSize >= 9);

        $stampWidth = min($maxWidth, max(44.0, $textWidth));
        $stampHeight = 10.0;
        $x = max(4.0, ($width - $stampWidth) / 2);
        $y = max(4.0, $height - $stampHeight - 14.0);

        $pdf->SetAlpha(1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.45);
        $pdf->SetXY($x, $y);
        $pdf->Cell($stampWidth, $stampHeight, $stampText, 1, 0, 'C');
        $pdf->SetAlpha(1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?: '');
    }

    private function toPdfText(string $text): string
    {
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $text);
    }

    private function watermarkedFilename(string $filename): string
    {
        if (! str_ends_with(strtolower($filename), '.pdf')) {
            return $filename;
        }

        return substr($filename, 0, -4).'-watermark.pdf';
    }

    private function documentPdfBytes(array $document): string
    {
        $content = (string) ($document['content_base64'] ?? '');
        if ($content !== '') {
            $content = preg_replace('/^data:[^;]+;base64,/', '', $content) ?: $content;
            $bytes = base64_decode($content, true);
            if (is_string($bytes) && $this->isPdfBytes($bytes)) {
                return $bytes;
            }
        }

        $bytes = $this->downloadDocumentUrl((string) ($document['url'] ?? ''));
        if ($bytes !== '' && $this->isPdfBytes($bytes)) {
            return $bytes;
        }

        return '';
    }

    private function isPdfBytes(string $bytes): bool
    {
        return str_starts_with(ltrim($bytes), '%PDF');
    }

    private function withWatermarkError(array $document, string $message, string $pdfBytes = ''): array
    {
        if ($pdfBytes !== '') {
            unset($document['url']);
            $document = [
                ...$document,
                'source' => 'base64',
                'mime_type' => 'application/pdf',
                'content_base64' => base64_encode($pdfBytes),
            ];
        }

        return [
            ...$document,
            'watermark_error' => trim($message) ?: 'Watermark gagal dipasang.',
        ];
    }

    private function downloadDocumentUrl(string $url): string
    {
        if (! str_starts_with($url, 'http')) {
            return '';
        }

        try {
            $response = Http::timeout(45)->accept('*/*')->get($url);
        } catch (Throwable) {
            return '';
        }

        if (! $response->successful() || $response->body() === '') {
            return '';
        }

        return $response->body();
    }
}

class WatermarkedFpdi extends Fpdi
{
    /** @var array<int, array{parms: array<string, float>, n?: int}> */
    protected array $extGStates = [];

    public function SetAlpha(float $alpha): void
    {
        $alpha = max(0, min(1, $alpha));
        $this->AddExtGState(['ca' => $alpha, 'CA' => $alpha]);
    }

    /** @param array<string, float> $parms */
    protected function AddExtGState(array $parms): void
    {
        $number = count($this->extGStates) + 1;
        $this->extGStates[$number] = ['parms' => $parms];
        $this->SetExtGState($number);
    }

    protected function SetExtGState(int $number): void
    {
        $this->_out('/GS'.$number.' gs');
    }

    protected function _putextgstates()
    {
        foreach ($this->extGStates as $number => $extGState) {
            $this->_newobj();
            $this->extGStates[$number]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            foreach ($extGState['parms'] as $key => $value) {
                $this->_put('/'.$key.' '.$value);
            }
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    protected function _putresourcedict()
    {
        parent::_putresourcedict();
        if ($this->extGStates === []) {
            return;
        }

        $this->_put('/ExtGState <<');
        foreach ($this->extGStates as $number => $extGState) {
            $this->_put('/GS'.$number.' '.$extGState['n'].' 0 R');
        }
        $this->_put('>>');
    }

    protected function _putresources()
    {
        $this->_putextgstates();
        parent::_putresources();
    }

    protected function _enddoc()
    {
        if ($this->extGStates !== [] && $this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }

        parent::_enddoc();
    }
}
