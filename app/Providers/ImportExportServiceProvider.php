<?php

namespace App\Providers;

use App\Features\Export\Http\Controllers\ExportController;
use App\Features\Import\Http\Controllers\ImportController;
use Illuminate\Support\ServiceProvider;

class ImportExportServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $modelPaths = glob(app_path('Features\**\Domain\**\Models\*.php'));
        foreach ($modelPaths as $modelPath) {
            $model = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $modelPath);

            $resourceName = (explode('\\', $model));
            $resourceName = strtolower(end($resourceName));

            if (!class_exists($model)) {
                continue;
            }
            if (in_array('App\Traits\HasImport', class_uses($model))) {
                ImportController::addToClassMap($resourceName, $model);
            }

            if (in_array('App\Traits\HasExport', class_uses($model))) {
                ExportController::addToClassMap($resourceName, $model);
            }
        }
    }
}
