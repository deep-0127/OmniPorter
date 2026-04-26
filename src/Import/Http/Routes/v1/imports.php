<?php

namespace OmniPorter\Import\Http\Routes;

use OmniPorter\Import\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('imports')->group(function () {
    Route::post('/{resource}/{mode}', [ImportController::class, 'importResource']);
});
