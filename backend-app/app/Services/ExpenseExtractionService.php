<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Etapa de "Extracción de información" + "Confiabilidad".
 *
 * v2: ajustado con facturas reales colombianas (ej. facturas de venta con
 * layout en tabla: "FECHA DE EMISION | D | M | A" con los números en celdas
 * separadas, "FACTURA DE VENTA" con el número en una caja aparte, encabezados
 * con NIT/régimen antes del nombre del proveedor, etc).
 *
 * Estrategia: reglas + expresiones regulares sobre el texto plano del OCR,
 * con un score de confianza 0-1 por campo:
 *
 *   1.0  -> match sobre una etiqueta explícita e inmediatamente adyacente
 *   0.7  -> match dentro de una "ventana" de texto cerca de una etiqueta
 *           (label y valor no están pegados, pero sí cerca)
 *   0.5  -> heurística de respaldo (ej. el monto con $ más grande)
 *   0.0  -> no se encontró nada; el campo queda null para que el usuario lo complete
 */
class ExpenseExtractionService
{
    protected const CATEGORY_KEYWORDS = [
        'Alimentacion' => ['restaurante', 'supermercado', 'comida', 'cafe', 'panaderia', 'mercado', 'food', 'grocery', 'pizza', 'burger'],
        'Transporte' => ['taxi', 'uber', 'didi', 'transporte', 'peaje', 'parqueadero', 'gasolina', 'combustible', 'bus', 'metro', 'airline', 'aerolinea'],
        'Tecnologia' => ['software', 'hardware', 'tecnologia', 'computador', 'laptop', 'licencia', 'saas', 'cloud', 'hosting', 'dominio', 'switch', 'router', 'accesorios', 'bateria', 'adaptador'],
        'Servicios' => ['servicio', 'suscripcion', 'mantenimiento', 'consultoria', 'internet', 'telefonia', 'electricidad', 'agua', 'gas natural'],
    ];

    // Líneas que casi nunca son el nombre del proveedor, aunque aparezcan
    // primero en el documento (encabezados típicos de facturas colombianas).
    protected const PROVIDER_DENYLIST_WORDS = [
        'nit', 'regimen', 'régimen', 'factura', 'cliente', 'direccion', 'dirección',
        'ciudad', 'telefono', 'teléfono', 'email', 'fecha', 'actividad economica',
        'contribuyente', 'autorretenedor', 'agente de retencion', 'dian', 'resolucion',
        'resolución', 'subtotal', 'iva', 'total', 'retefuente', 'garantia', 'garantía',
        'firma', 'certificado', 'gravable', 'concepto', 'señores', 'senores', 'vigencia',
        'formulario', 'codigo ciiu', 'código ciiu', 'tarifa ica',
    ];

    // Palabras que suelen indicar que una línea es el nombre de un NEGOCIO
    // (y no el nombre de una persona natural, que también puede aparecer
    // en el encabezado de facturas de régimen simplificado). Si alguna
    // línea candidata contiene una de estas, se prioriza sobre las demás.
    protected const PROVIDER_BUSINESS_KEYWORDS = [
        's.a.s', 'sas', 'ltda', 'cia', 'electronics', 'electronica', 'electrónica',
        'shop', 'store', 'comercial', 'distribuidora', 'almacen', 'almacén',
        'ferreteria', 'ferretería', 'panaderia', 'panadería', 'restaurante',
        'supermercado', 'farmacia', 'drogueria', 'droguería', 'tienda', 'market',
        'papeleria', 'papelería', 'licoreria', 'licorería', 'autoservicio', 'motos',
        'repuestos', 'variedades',
    ];

    // Contextos que invalidan una coincidencia cercana (el "Formulario DIAN"
    // trae su propio número y su propia fecha, que NO son los de la factura).
    protected const EXCLUDED_CONTEXT_WORDS = ['dian', 'formulario'];

    public function extract(string $ocrText): array
    {
        $normalized = $this->normalize($ocrText);

        [$total, $totalScore] = $this->extractAmount($normalized, ['total a pagar', 'gran total', 'valor total', 'total']);
        [$subtotal, $subtotalScore] = $this->extractAmount($normalized, ['subtotal', 'sub-total', 'base gravable', 'base imponible']);
        [$impuestos, $impuestosScore] = $this->extractAmount($normalized, ['iva', 'impuesto', 'tax', 'vat']);
        [$fecha, $fechaScore] = $this->extractDate($normalized);
        [$numeroFactura, $numeroFacturaScore] = $this->extractInvoiceNumber($normalized);
        [$proveedor, $proveedorScore] = $this->extractProvider($ocrText);
        [$moneda, $monedaScore] = $this->extractCurrency($normalized);

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
        return preg_replace('/\s+/', ' ', $text);
    }

    /**
     * @return array{0: ?float, 1: float}
     */
    protected function extractAmount(string $text, array $labels): array
    {
        foreach ($labels as $label) {
            // \b evita que "total" haga match dentro de "subtotal", etc.
            // El grupo captura dígitos/puntos/comas Y espacios internos
            // (el OCR a veces mete un espacio de más, ej. "27. 008"),
            // pero siempre debe empezar y terminar en un dígito.
            $pattern = '/\b'.preg_quote($label, '/').'\b\s*[:\-]?\s*\$?\s*([0-9](?:[0-9.,\s]{0,15}[0-9])?)/u';
            if (preg_match($pattern, $text, $m)) {
                $value = $this->parseAmount($m[1]);
                if ($value !== null) {
                    return [$value, 1.0];
                }
            }
        }

        foreach ($labels as $label) {
            $pattern = '/\b'.preg_quote($label, '/').'\b.{0,25}?\$?\s*([0-9](?:[0-9.,\s]{0,15}[0-9])?)/u';
            if (preg_match($pattern, $text, $m)) {
                $value = $this->parseAmount($m[1]);
                if ($value !== null) {
                    return [$value, 0.7];
                }
            }
        }

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
        // Quita espacios internos que a veces mete el OCR (ej. "27. 008").
        $raw = preg_replace('/\s+/', '', trim($raw));
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{2}$/', $raw)) {
            return (float) str_replace(['.', ','], ['', '.'], $raw);
        }
        if (preg_match('/^\d{1,3}(,\d{3})+\.\d{2}$/', $raw)) {
            return (float) str_replace(',', '', $raw);
        }
        // "27.008" o "3.991" (formato colombiano: punto de miles, SIN decimales)
        if (preg_match('/^\d{1,3}\.\d{3}$/', $raw)) {
            return (float) str_replace('.', '', $raw);
        }
        $clean = str_replace(',', '.', $raw);
        $clean = preg_replace('/\.(?=.*\.)/', '', $clean);
        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractDate(string $text): array
    {
        // 1) Prioridad máxima: etiquetas específicas de fecha de facturación
        //    (evita confundirla con otras fechas del documento, ej. fecha de
        //    vencimiento, fecha del Formulario DIAN, etc).
        $priorityLabels = ['fecha de facturacion', 'fecha de facturación', 'fecha de emision', 'fecha de emisión', 'fecha de expedicion', 'fecha de expedición'];
        foreach ($priorityLabels as $label) {
            if (preg_match('/'.preg_quote($label, '/').'[^0-9]{0,20}/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                $windowStart = $m[0][1];
                $window = mb_substr($text, $windowStart, 60);
                if ($date = $this->parseDateFromWindow($window)) {
                    return [$date, 1.0];
                }
            }
        }

        // 2) Puede haber varias ocurrencias de la palabra suelta "fecha" (la
        //    del Formulario DIAN y la real de la factura). Probamos cada una
        //    en orden, mientras NO esté precedida de contexto "dian"/"formulario".
        if (preg_match_all('/fecha[^0-9]{0,40}/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $windowStart = $match[1];

                if ($this->hasExcludedContext($text, $windowStart)) {
                    continue;
                }

                $window = mb_substr($text, $windowStart, 60);
                if ($date = $this->parseDateFromWindow($window)) {
                    return [$date, 0.9];
                }
            }

            // Ninguna ocurrencia "limpia" de fecha funcionó: usar la primera
            // aunque tenga contexto DIAN, con menor confianza.
            $windowStart = $matches[0][0][1];
            $window = mb_substr($text, $windowStart, 60);
            if ($date = $this->parseDateFromWindow($window)) {
                return [$date, 0.5];
            }
        }

        if ($date = $this->parseDateFromWindow($text)) {
            return [$date, 0.6];
        }

        return [null, 0.0];
    }

    /**
     * Revisa si los ~25 caracteres antes de una posición contienen alguna
     * palabra de contexto excluido (ej. "dian", "formulario").
     */
    protected function hasExcludedContext(string $text, int $position): bool
    {
        $contextBefore = mb_substr($text, max(0, $position - 25), 25);

        foreach (self::EXCLUDED_CONTEXT_WORDS as $word) {
            if (str_contains($contextBefore, $word)) {
                return true;
            }
        }

        return false;
    }

    protected function parseDateFromWindow(string $window): ?string
    {
        $patterns = [
            '/\b(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})\b/' => 'ymd',
            '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/' => 'dmy',
            '/\b(\d{1,2})\s+(\d{1,2})\s+(\d{2,4})\b/' => 'dmy_space',
            '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})\b/' => 'dmy_short',
        ];

        foreach ($patterns as $pattern => $order) {
            if (! preg_match($pattern, $window, $m)) {
                continue;
            }

            try {
                if ($order === 'ymd') {
                    $day = (int) $m[3];
                    $month = (int) $m[2];
                    $year = (int) $m[1];
                } else {
                    $day = (int) $m[1];
                    $month = (int) $m[2];
                    $year = (int) $m[3];
                }

                if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                    continue;
                }

                if ($year < 100) {
                    $year += $year <= 39 ? 2000 : 1900;
                }

                return Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractInvoiceNumber(string $text): array
    {
        // 1) Prioridad máxima: "factura de venta" explícitamente (evita
        //    agarrar el número del Formulario DIAN u otro número suelto).
        $priorityPattern = '/\bfactura\s*de\s*venta\b\s*(?:no\.?|nro\.?|n[uú]mero)?\s*[:\-#]?\s*([a-z0-9\-]{2,20})/u';
        if (preg_match($priorityPattern, $text, $m)) {
            return [strtoupper($m[1]), 1.0];
        }
        // A veces el número queda un poco más lejos de la etiqueta ("factura
        // de venta" en una caja y el número en la caja de al lado).
        $priorityWindowed = '/\bfactura\s*de\s*venta\b.{0,25}?\b([0-9]{2,10})\b/u';
        if (preg_match($priorityWindowed, $text, $m)) {
            return [strtoupper($m[1]), 0.9];
        }

        // 2) \b evita que "no" haga match dentro de palabras como "economica".
        //    Puede haber varias etiquetas candidatas (ej. "Formulario DIAN No."
        //    y también "Factura No."); recorremos todas y saltamos las que
        //    estén en el contexto del Formulario DIAN.
        $adjacent = '/\b(?:factura\s*(?:de\s*venta)?|invoice|no\.?|nro\.?|n[uú]mero|folio|comprobante)\b\s*[:\-#]?\s*([a-z0-9\-]{2,20})/u';
        if (preg_match_all($adjacent, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $fallback = null;
            foreach ($matches[0] as $i => $fullMatch) {
                $value = $matches[1][$i][0];

                // El valor capturado DEBE contener al menos un dígito. Sin
                // esto, palabras comunes en español como "No somos grandes
                // contribuyentes" hacían match con "no" y capturaban "somos"
                // como si fuera el número de factura.
                if (! preg_match('/\d/', $value)) {
                    continue;
                }

                if ($fallback === null) {
                    $fallback = $value;
                }
                if (! $this->hasExcludedContext($text, $fullMatch[1])) {
                    return [strtoupper($value), 1.0];
                }
            }
            if ($fallback !== null) {
                return [strtoupper($fallback), 0.5];
            }
        }

        $windowed = '/\bfactura\b(?:\s*de\s*venta)?.{0,25}?\b([0-9]{2,10})\b/u';
        if (preg_match($windowed, $text, $m)) {
            return [strtoupper($m[1]), 0.7];
        }

        return [null, 0.0];
    }

    /**
     * @return array{0: ?string, 1: float}
     */
    protected function extractProvider(string $rawText): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $rawText)));
        $candidates = [];

        foreach (array_slice($lines, 0, 8) as $line) {
            $lower = mb_strtolower($line);

            if (mb_strlen($line) < 3 || mb_strlen($line) > 60) {
                continue;
            }
            if (preg_match('/^[\d\s.,\-\/$]+$/', $line)) {
                continue;
            }
            $isHeader = false;
            foreach (self::PROVIDER_DENYLIST_WORDS as $word) {
                if (str_contains($lower, $word)) {
                    $isHeader = true;
                    break;
                }
            }
            if ($isHeader) {
                continue;
            }

            $candidates[] = $line;
        }

        if (empty($candidates)) {
            return [null, 0.0];
        }

        // Prioriza cualquier candidata que "suene a negocio" (contiene una
        // palabra tipo SAS, ELECTRONICS, TIENDA, etc) sobre el nombre de
        // una persona natural, aunque la persona aparezca primero en el
        // encabezado (común en facturas de régimen simplificado).
        foreach ($candidates as $line) {
            $lower = mb_strtolower($line);
            foreach (self::PROVIDER_BUSINESS_KEYWORDS as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return [$line, 0.9];
                }
            }
        }

        // Ninguna candidata tiene una palabra reconocible de negocio:
        // devolvemos la primera, pero con confianza más baja porque puede
        // ser el nombre de una persona en vez del negocio.
        return [$candidates[0], 0.6];
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
