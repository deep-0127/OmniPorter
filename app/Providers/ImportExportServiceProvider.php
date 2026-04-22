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
        $cachePath = base_path('bootstrap/cache/import_export_models.php');

        if (app()->environment('local')) {
            $this->scanAndRegisterDynamically();
            return;
        }

        if (file_exists($cachePath)) {
            $map = require $cachePath;

            foreach ($map['imports'] as $resource => $class) {
                ImportController::addToClassMap($resource, $class);
            }

            foreach ($map['exports'] as $resource => $class) {
                ExportController::addToClassMap($resource, $class);
            }
        }
    }

    private function scanAndRegisterDynamically(): void
    {
        $basePath = app_path('Features');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (
                !$file->isFile() ||
                $file->getExtension() !== 'php' ||
                !str_contains($file->getPathname(), 'Domain' . DIRECTORY_SEPARATOR) ||
                !str_contains($file->getPathname(), 'Models')
            ) {
                continue;
            }

            $modelPath = $file->getPathname();

            // Convert path to class name
            $model = str_replace(
                [
                    app_path() . DIRECTORY_SEPARATOR,
                    DIRECTORY_SEPARATOR,
                    '.php',
                ],
                [
                    'App\\',
                    '\\',
                    '',
                ],
                $modelPath
            );

            if (!class_exists($model)) {
                continue;
            }

            $resourceName = strtolower(class_basename($model));

            if (in_array(\App\Traits\HasImport::class, class_uses($model))) {
                ImportController::addToClassMap($resourceName, $model);
            }

            if (in_array(\App\Traits\HasExport::class, class_uses($model))) {
                ExportController::addToClassMap($resourceName, $model);
            }
        }
    }


}
