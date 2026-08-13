<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'file_path',
        'mime_type',
        'ocr_raw_text',
        'ocr_engine',
        'proveedor',
        'numero_factura',
        'fecha',
        'subtotal',
        'impuestos',
        'total',
        'moneda',
        'categoria',
        'field_confidence',
        'overall_confidence',
        'status',
        'was_manually_edited',
    ];

    protected $casts = [
        'fecha' => 'date',
        'subtotal' => 'decimal:2',
        'impuestos' => 'decimal:2',
        'total' => 'decimal:2',
        'overall_confidence' => 'decimal:3',
        'field_confidence' => 'array',
        'was_manually_edited' => 'boolean',
    ];

    // Campos que se consideran "dudosos" cuando su confianza es menor a este umbral
    public const LOW_CONFIDENCE_THRESHOLD = 0.6;

    public function lowConfidenceFields(): array
    {
        $fields = $this->field_confidence ?? [];

        return collect($fields)
            ->filter(fn ($score) => $score !== null && $score < self::LOW_CONFIDENCE_THRESHOLD)
            ->keys()
            ->all();
    }
}
