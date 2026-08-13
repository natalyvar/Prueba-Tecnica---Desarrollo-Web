<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Etapa de "Extracción de información" + "Confiabilidad".
 *
 * Estrategia elegida: reglas + expresiones regulares sobre el texto plano
 * del OCR (sin dependencia de un LLM externo, para que la prueba funcione
 * sin API keys). Cada campo se extrae con su propia regla y se le asigna
 * un score de confianza 0-1 según qué tan específico fue el match:
 *
 *   1.0  -> match sobre una etiqueta explícita inequívoca (ej: "Total: $123,45")
 *   0.6  -> match heurístico (ej: el número más grande de la factura)
 *   0.0  -> no se encontró nada (queda null, el usuario debe completarlo)
 *
 * El score global del documento es el promedio de los campos obligatorios.
 * Campos con score < ExpenseDocument::LOW_CONFIDENCE_THRESHOLD se marcan
 * como "dudosos" en la UI para que el usuario los revise antes de confiar
 * en ellos.
 */
class ExpenseExtractionService
{
    protected const CATEGORY_KEYWORDS = [
        'Alimentacion' => ['restaurante', 'supermercado', 'comida', 'cafe', 'panaderia', 'mercado', 'food', 'grocery', 'pizza', 'burger'],
        'Transporte' => ['taxi', 'uber', 'didi', 'transporte', 'peaje', 'parqueadero', 'gasolina', 'combustible', 'bus', 'metro', 'airline', 'aerolinea'],
        'Tecnologia' => ['software', 'hardware', 'tecnologia', 'computador', 'laptop', 'licencia', 'saas', 'cloud', 'hosting', 'dominio'],
        'Servicios' => ['servicio', 'suscripcion', 'mantenimiento', 'consultoria', 'internet', 'telefonia', 'electricidad', 'agua', 'gas natural'],
    ];

    public function extract(string $ocrText): array
    {
        $normalized = $this->normalize($ocrText);

        [$total, $totalScore] = $this->extractAmount($normalized, ['total', 'total a pagar', 'gran total', 'valor total']);
        [$subtotal, $subtotalScore] = $this->extractAmount($normalized, ['subtotal', 'sub-total', 'base gravable', 'base imponible']);
        [$impuestos, $impuestosScore] = $this->extractAmount($normalized, ['iva', 'impuesto', 'tax', 'vat']);
        [$fecha, $fechaScore] = $this->extractDate($normalized);
        [$numeroFactura, $numeroFacturaScore] = $this->extractInvoiceNumber($normalized);
        [$proveedor, $proveedorScore] = $this->extractProvider($ocrText);
        [$moneda, $monedaScore] = $this->extractCurrency($normalized);

        // Si no hay subtotal pero sí total e impuestos, se puede derivar (score algo menor: es inferido)
        if ($subtotal === null && $total !== null && $impuestos !== null) {
            $subtotal = round($total - $impuestos, 2);
            $subtotalScore = 0.5;
        }

        $categoria = $this->guessCategory($ocrText, $proveedor);

        $fieldConfidence = [
            'proveedor' => $proveedorScore,
            'numero_factura' => $numeroFacturaScore,
            'fecha' => $fechaScore,
            'subtotal' => $subtotalScore,
            'impuestos' => $impuestosScore,
            'total' => $totalScore,
            'moneda' => $monedaScore,
        ];

        $overallConfidence = round(array_sum($fieldConfidence) / count($fieldConfidence), 3);

        return [
            'proveedor' => $proveedor,
            'numero_factura' => $numeroFactura,
            'fecha' => $fecha,
            'subtotal' => $subtotal,
            'impuestos' => $impuestos,
            'total' => $total,
            'moneda' => $moneda,
            'categoria' => $categoria,
            'field_confidence' => $fieldConfidence,
            'overall_confidence' => $overallConfidence,
        ];
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Homogeniza separadores de miles/decimales y espacios raros de OCR
        return preg_replace('/\s+/', ' ', $text);
    }

    /**
     * Busca un monto asociado a alguna de las etiquetas dadas, en una
     * ventana de texto después de la etiqueta.
     *
     * @return array{0: ?float, 1: float} [valor, score]
     */
    protected function extractAmount(string $text, array $labels): array
    {
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*[:\-]?\s*\$?\s*([0-9][0-9.,]*)/u';
            if (preg_match($pattern, $text, $m)) {
                $value = $this->parseAmount($m[1]);
                if ($value !== null) {
                    return [$value, 1.0];
                }
            }
        }

        // Heurística de respaldo solo para "total": el monto con símbolo $
        // más grande del documento suele ser el total.
        if (in_array('total', $labels, true)) {
            preg_match_all('/\$\s*([0-9][0-9.,]*)/u', $text, $matches);
            $amounts = array_filter(array_map([$this, 'parseAmount'], $matches[1] ?? []));
            if (! empty($amounts)) {
                return [max($amounts), 0.5];
            }
        }

        return [null, 0.0];
    }

    protected function parseAmount(string $raw): ?float
    {
        $raw = trim($raw);
        // Formato "1.234,56" (miles con punto, decimales con coma)
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{2}$/', $raw)) {
            return (float) str_replace(['.', ','], ['', '.'], $raw);
        }
        // Formato "1,234.56" (miles con coma, decimales con punto)
        if (preg_match('/^\d{1,3}(,\d{3})+\.\d{2}$/', $raw)) {
            return (float) str_replace(',', '', $raw);
        }
        // Solo dígitos, o con un separador decimal simple
        $clean = str_replace(',', '.', $raw);
        $clean = preg_replace('/\.(?=.*\.)/', '', $clean); // deja solo el último punto como decimal
        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractDate(string $text): array
    {
        // dd/mm/yyyy , dd-mm-yyyy , yyyy-mm-dd
        $patterns = [
            '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/' => 'ymd',
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/' => 'dmy',
        ];

        foreach ($patterns as $pattern => $order) {
            if (preg_match($pattern, $text, $m)) {
                try {
                    if ($order === 'ymd') {
                        $date = Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3]);
                    } else {
                        $date = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1]);
                    }

                    return [$date->format('Y-m-d'), 1.0];
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return [null, 0.0];
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractInvoiceNumber(string $text): array
    {
        $pattern = '/(?:factura|invoice|no\.?|n[uú]mero|folio|comprobante)\s*[:\-#]?\s*([a-z0-9\-]{3,20})/u';
        if (preg_match($pattern, $text, $m)) {
            return [strtoupper($m[1]), 1.0];
        }

        return [null, 0.0];
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractProvider(string $rawText): array
    {
        // Heurística: en la mayoría de facturas/recibos, el nombre del
        // establecimiento aparece en una de las primeras líneas no vacías
        // y no es puramente numérico.
        $lines = array_filter(array_map('trim', explode("\n", $rawText)));

        foreach (array_slice($lines, 0, 5) as $line) {
            if (mb_strlen($line) >= 3 && ! preg_match('/^[\d\s.,\-\/$]+$/', $line)) {
                return [$line, 0.6];
            }
        }

        return [null, 0.0];
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractCurrency(string $text): array
    {
        $map = [
            'cop' => 'COP', 'pesos colombianos' => 'COP',
            'usd' => 'USD', 'us\$' => 'USD', 'dolares' => 'USD', 'dólares' => 'USD',
            'eur' => 'EUR', 'euros' => 'EUR',
            'mxn' => 'MXN',
        ];

        foreach ($map as $needle => $code) {
            if (preg_match('/'.$needle.'/u', $text)) {
                return [$code, 1.0];
            }
        }

        if (str_contains($text, '$')) {
            // Símbolo genérico sin contexto de país: se asume COP por defecto
            // (moneda más común en el contexto de uso), pero con baja confianza.
            return ['COP', 0.4];
        }

        return [null, 0.0];
    }

    protected function guessCategory(string $rawText, ?string $proveedor): string
    {
        $haystack = mb_strtolower($rawText.' '.($proveedor ?? ''));

        foreach (self::CATEGORY_KEYWORDS as $categoria => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $categoria;
                }
            }
        }

        return 'Otros';
    }
}
