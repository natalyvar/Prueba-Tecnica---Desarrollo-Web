<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_documents', function (Blueprint $table) {
            $table->id();

            // Archivo original
            $table->string('original_filename');
            $table->string('file_path');       // ruta en storage del archivo original (jpg/png/pdf)
            $table->string('mime_type')->nullable();

            // Resultado crudo del OCR (auditable / trazable)
            $table->longText('ocr_raw_text')->nullable();
            $table->string('ocr_engine')->default('tesseract');

            // Campos extraídos (editables por el usuario)
            $table->string('proveedor')->nullable();
            $table->string('numero_factura')->nullable();
            $table->date('fecha')->nullable();
            $table->decimal('subtotal', 14, 2)->nullable();
            $table->decimal('impuestos', 14, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->string('moneda', 10)->nullable();

            // Categoría
            $table->enum('categoria', ['Alimentacion', 'Transporte', 'Tecnologia', 'Servicios', 'Otros'])
                ->default('Otros');

            // Confiabilidad: score 0-1 por campo (json) + score global
            $table->json('field_confidence')->nullable();
            $table->decimal('overall_confidence', 4, 3)->nullable();

            // Estado del flujo de revisión humana
            $table->enum('status', ['pendiente_revision', 'revisado'])->default('pendiente_revision');

            // Auditoría de edición manual
            $table->boolean('was_manually_edited')->default(false);

            $table->timestamps();

            $table->index(['fecha', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_documents');
    }
};
