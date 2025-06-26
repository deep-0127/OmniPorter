<?php

namespace App\Features\Export\Http\Routes;

use App\Features\Export\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('exports')->group(function () {
    Route::get('/{resource}', [ExportController::class, 'exportResource']);
});
