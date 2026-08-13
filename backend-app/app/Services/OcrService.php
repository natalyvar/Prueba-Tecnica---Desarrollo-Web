<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Etapa de OCR.
 *
 * Usa el motor Tesseract (vía el binario `tesseract`, instalado a nivel de
 * sistema operativo) a través del wrapper thiagoalessio/tesseract_ocr.
 *
 * Si el archivo es un PDF, primero se rasteriza cada página a imagen. Hay
 * dos formas de hacerlo, en este orden de preferencia:
 *
 *   1) Imagick (extensión de PHP) + Ghostscript, si está disponible.
 *   2) El binario `pdftoppm` (parte de Poppler) vía línea de comandos.
 *      Esta es la opción recomendada en Windows, porque instalar la
 *      extensión Imagick de PHP en Windows es notoriamente complicado
 *      (requiere que la versión del .dll coincida exactamente con la
 *      versión/arquitectura de PHP), mientras que `pdftoppm` es un
 *      ejecutable independiente que solo hay que descargar y agregar al PATH.
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
        if (class_exists('Imagick')) {
            try {
                return $this->extractFromPdfWithImagick($pdfPath);
            } catch (\Throwable $e) {
                Log::warning('Imagick falló procesando PDF, se intentará con pdftoppm.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->extractFromPdfWithPdftoppm($pdfPath);
    }

    protected function extractFromPdfWithImagick(string $pdfPath): string
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

    /**
     * Alternativa sin la extensión Imagick: usa el binario `pdftoppm`
     * (parte de Poppler, https://github.com/oschwartz10612/poppler-windows
     * para Windows) para convertir cada página del PDF a PNG, y luego
     * corre Tesseract sobre cada imagen generada.
     */
    protected function extractFromPdfWithPdftoppm(string $pdfPath): string
    {
        $tmpDir = sys_get_temp_dir();
        $prefix = $tmpDir.DIRECTORY_SEPARATOR.'ocr_pdf_'.uniqid();

        $process = new Process(['pdftoppm', '-png', '-r', '300', $pdfPath, $prefix]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'No se pudo convertir el PDF a imagen con pdftoppm. '.
                'Verifica que esté instalado y en el PATH del sistema. '.
                'Detalle: '.$process->getErrorOutput()
            );
        }

        // pdftoppm genera archivos tipo "prefix-1.png", "prefix-2.png", etc.
        $generatedFiles = glob($prefix.'*.png') ?: [];
        sort($generatedFiles);

        if (empty($generatedFiles)) {
            throw new \RuntimeException('pdftoppm no generó ninguna imagen a partir del PDF.');
        }

        $text = '';
        foreach ($generatedFiles as $imagePath) {
            $text .= $this->runTesseract($imagePath)."\n";
            @unlink($imagePath);
        }

        return trim($text);
    }
}
