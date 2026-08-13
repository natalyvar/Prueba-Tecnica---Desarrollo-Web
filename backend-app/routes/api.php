<?php

use App\Http\Controllers\Api\ExpenseDocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('expense-documents')->group(function () {
    Route::get('/', [ExpenseDocumentController::class, 'index']);
    Route::post('/', [ExpenseDocumentController::class, 'store']);
    Route::get('/{expenseDocument}', [ExpenseDocumentController::class, 'show']);
    Route::put('/{expenseDocument}', [ExpenseDocumentController::class, 'update']);
    Route::patch('/{expenseDocument}', [ExpenseDocumentController::class, 'update']);
    Route::delete('/{expenseDocument}', [ExpenseDocumentController::class, 'destroy']);
    Route::post('/{expenseDocument}/reprocess', [ExpenseDocumentController::class, 'reprocess']);
});
