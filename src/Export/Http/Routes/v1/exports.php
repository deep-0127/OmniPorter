<?php

namespace OmniPorter\Export\Http\Routes;

use OmniPorter\Export\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('exports')->group(function () {
    Route::get('/{resource}', [ExportController::class, 'exportResource']);
    Route::get('/download/{path}', [ExportController::class, 'download'])
        ->name('omniporter.export.download')
        ->where('path', '.*');
});
