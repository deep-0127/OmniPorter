<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OmniPorter\Export\Http\Controllers\ExportController;
use OmniPorter\Import\Http\Controllers\ImportController;
use OmniPorter\Import\Http\Controllers\ProgressController;

/*
|--------------------------------------------------------------------------
| OmniPorter API Routes
|--------------------------------------------------------------------------
|
| These routes handle CSV/Excel import and export operations.
|
*/

// Import routes
Route::prefix('imports')->group(function () {
    Route::post('{resource}/{mode}', [ImportController::class, 'importResource'])
        ->where('mode', 'create|update');

    Route::get('progress/{batchId}', [ProgressController::class, 'show']);
});

// Export routes
Route::prefix('exports')->group(function () {
    Route::get('{resource}', [ExportController::class, 'exportResource']);
});
