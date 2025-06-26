<?php

namespace App\Features\Import\Http\Routes;

use App\Features\Import\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('imports')->group(function () {
    Route::post('/{resource}/{mode}', [ImportController::class, 'importResource']);
});
