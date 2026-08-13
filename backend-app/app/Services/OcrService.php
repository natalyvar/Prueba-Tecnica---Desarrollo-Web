<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Etapa de OCR.
 *
 * Usa el motor Tesseract (vía el binario `tesseract`, instalado a nivel de
 * sistema operativo) a través del wrapper thiagoalessio/tesseract_ocr.
 *
 * Si el archivo es un PDF, primero se rasteriza cada página a imagen (con
 * Imagick, que a su vez usa Ghostscript) y se concatena el texto de OCR de
 * todas las páginas.
 *
 * Se aísla en un servicio propio para que el motor de OCR pueda cambiarse
 * (por ejemplo a un servicio en la nube) sin tocar el resto de la app.
 */
class OcrService
{
    public function extractText(string $absoluteFilePath, string $mimeType): string
    {
        try {
            if ($mimeType === 'application/pdf') {
                return $this->extractFromPdf($absoluteFilePath);
            }

            return $this->runTesseract($absoluteFilePath);
        } catch (\Throwable $e) {
            // El OCR nunca debe tumbar la subida del documento: si falla,
            // se registra el error y se deja el texto vacío para que el
            // usuario pueda completar los campos manualmente.
            Log::error('OCR falló para archivo: '.$absoluteFilePath, [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    protected function runTesseract(string $imagePath): string
    {
        return (new TesseractOCR($imagePath))
            ->lang('spa', 'eng')
            ->run();
    }

    protected function extractFromPdf(string $pdfPath): string
    {
        $imagick = new \Imagick();
        $imagick->setResolution(300, 300);
        $imagick->readImage($pdfPath);

        $text = '';
        $tmpDir = sys_get_temp_dir();

        foreach ($imagick as $index => $page) {
            $page->setImageFormat('png');
            $tmpImage = $tmpDir.'/ocr_page_'.uniqid().'_'.$index.'.png';
            $page->writeImage($tmpImage);

            $text .= $this->runTesseract($tmpImage)."\n";

            @unlink($tmpImage);
        }

        $imagick->clear();

        return trim($text);
    }
}
