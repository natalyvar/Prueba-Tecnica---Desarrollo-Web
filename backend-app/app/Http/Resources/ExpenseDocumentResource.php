<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'file_url' => asset('storage/'.$this->file_path),
            'mime_type' => $this->mime_type,
            'proveedor' => $this->proveedor,
            'numero_factura' => $this->numero_factura,
            'fecha' => $this->fecha?->format('Y-m-d'),
            'subtotal' => $this->subtotal !== null ? (float) $this->subtotal : null,
            'impuestos' => $this->impuestos !== null ? (float) $this->impuestos : null,
            'total' => $this->total !== null ? (float) $this->total : null,
            'moneda' => $this->moneda,
            'categoria' => $this->categoria,
            'field_confidence' => $this->field_confidence,
            'overall_confidence' => $this->overall_confidence !== null ? (float) $this->overall_confidence : null,
            'low_confidence_fields' => $this->lowConfidenceFields(),
            'status' => $this->status,
            'was_manually_edited' => $this->was_manually_edited,
            'ocr_raw_text' => $this->when($request->routeIs('*.show'), $this->ocr_raw_text),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
