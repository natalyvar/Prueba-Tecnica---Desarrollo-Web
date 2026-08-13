<?php

use App\Http\Controllers\Api\ExpenseDocumentController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::prefix('expense-documents')->group(function () {
    Route::get('/', [ExpenseDocumentController::class, 'index']);
    Route::post('/', [ExpenseDocumentController::class, 'store']);
    Route::get('/{expenseDocument}', [ExpenseDocumentController::class, 'show']);
    Route::put('/{expenseDocument}', [ExpenseDocumentController::class, 'update']);
    Route::patch('/{expenseDocument}', [ExpenseDocumentController::class, 'update']);
    Route::delete('/{expenseDocument}', [ExpenseDocumentController::class, 'destroy']);
    Route::post('/{expenseDocument}/reprocess', [ExpenseDocumentController::class, 'reprocess']);
});

// Sirve los archivos originales (imágenes/PDF) directamente desde el disco,
// SIN depender del enlace simbólico de `storage:link`. El servidor de
// desarrollo de PHP (`php artisan serve`) no sigue bien los symlinks en
// Windows, lo que causa 403 Forbidden al acceder a /storage/*. Esta ruta
// evita ese problema por completo.
Route::get('/files/{path}', function (string $path) {
    if (! Storage::disk('public')->exists($path)) {
        abort(404);
    }

    return response()->file(Storage::disk('public')->path($path));
})->where('path', '.*')->name('files.show');
