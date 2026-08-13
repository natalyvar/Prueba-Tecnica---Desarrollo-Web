<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateExpenseDocumentRequest;
use App\Http\Resources\ExpenseDocumentResource;
use App\Models\ExpenseDocument;
use App\Services\ExpenseExtractionService;
use App\Services\OcrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExpenseDocumentController extends Controller
{
    public function __construct(
        protected OcrService $ocrService,
        protected ExpenseExtractionService $extractionService,
    ) {
    }

    /**
     * GET /api/expense-documents
     * Listado con filtros: fecha_desde, fecha_hasta, categoria
     */
    public function index(Request $request)
    {
        $query = ExpenseDocument::query();

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->input('fecha_hasta'));
        }

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->input('categoria'));
        }

        if ($request->filled('proveedor')) {
            $query->where('proveedor', 'like', '%'.$request->input('proveedor').'%');
        }

        $documents = $query->orderByDesc('created_at')->paginate(
            $request->integer('per_page', 15)
        );

        return ExpenseDocumentResource::collection($documents);
    }

    /**
     * POST /api/expense-documents
     * 1) Carga de documento -> 2) OCR -> 3) Extracción de campos
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $file = $request->file('file');
        $storedPath = $file->store('expense-documents', 'public');
        $absolutePath = Storage::disk('public')->path($storedPath);

        // 2. OCR
        $ocrText = $this->ocrService->extractText($absolutePath, $file->getMimeType());

        // 3. Extracción de información estructurada + 4. Confiabilidad
        $extracted = $this->extractionService->extract($ocrText);

        $document = ExpenseDocument::create([
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'mime_type' => $file->getMimeType(),
            'ocr_raw_text' => $ocrText,
            'ocr_engine' => 'tesseract',
            ...$extracted,
            'status' => 'pendiente_revision',
            'was_manually_edited' => false,
        ]);

        return (new ExpenseDocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/expense-documents/{expenseDocument}
     */
    public function show(ExpenseDocument $expenseDocument)
    {
        return new ExpenseDocumentResource($expenseDocument);
    }

    /**
     * PUT/PATCH /api/expense-documents/{expenseDocument}
     * 5. Revisión humana: corregir campos, completar información, cambiar categoría.
     */
    public function update(UpdateExpenseDocumentRequest $request, ExpenseDocument $expenseDocument)
    {
        $validated = $request->validated();
        $validated['status'] = $validated['status'] ?? 'revisado';
        $validated['was_manually_edited'] = true;

        $expenseDocument->update($validated);

        return new ExpenseDocumentResource($expenseDocument);
    }

    /**
     * DELETE /api/expense-documents/{expenseDocument}
     */
    public function destroy(ExpenseDocument $expenseDocument)
    {
        Storage::disk('public')->delete($expenseDocument->file_path);
        $expenseDocument->delete();

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }

    /**
     * POST /api/expense-documents/{expenseDocument}/reprocess
     * Utilidad extra: vuelve a correr OCR + extracción sobre el archivo
     * original (útil si se mejora la lógica de extracción).
     */
    public function reprocess(ExpenseDocument $expenseDocument)
    {
        $absolutePath = Storage::disk('public')->path($expenseDocument->file_path);

        if (! file_exists($absolutePath)) {
            throw ValidationException::withMessages([
                'file' => 'El archivo original ya no existe en el almacenamiento.',
            ]);
        }

        $ocrText = $this->ocrService->extractText($absolutePath, $expenseDocument->mime_type);
        $extracted = $this->extractionService->extract($ocrText);

        $expenseDocument->update([
            'ocr_raw_text' => $ocrText,
            ...$extracted,
            'status' => 'pendiente_revision',
            'was_manually_edited' => false,
        ]);

        return new ExpenseDocumentResource($expenseDocument);
    }
}
