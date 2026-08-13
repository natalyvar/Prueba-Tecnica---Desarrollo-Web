<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proveedor' => ['nullable', 'string', 'max:255'],
            'numero_factura' => ['nullable', 'string', 'max:100'],
            'fecha' => ['nullable', 'date'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'impuestos' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'moneda' => ['nullable', 'string', 'max:10'],
            'categoria' => ['required', Rule::in(['Alimentacion', 'Transporte', 'Tecnologia', 'Servicios', 'Otros'])],
            'status' => ['nullable', Rule::in(['pendiente_revision', 'revisado'])],
        ];
    }

    public function messages(): array
    {
        return [
            'categoria.in' => 'La categoria debe ser una de las categorias permitidas.',
        ];
    }
}
