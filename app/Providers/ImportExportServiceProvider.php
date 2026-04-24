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
        } else {
            // Fallback: scan and create cache if it doesn't exist
            $this->scanAndRegisterDynamically();
        }
    }

    private function scanAndRegisterDynamically(): void
    {
        $modelPaths = glob(app_path('Features/**/Domain/**/Models/*.php'));
        $importMap = [];
        $exportMap = [];

        foreach ($modelPaths as $modelPath) {
            $model = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $modelPath);

            $resourceName = (explode('\\', $model));
            $resourceName = strtolower(end($resourceName));

            if (!class_exists($model)) {
                continue;
            }

            if (in_array('App\Traits\HasImport', class_uses($model))) {
                ImportController::addToClassMap($resourceName, $model);
                $importMap[$resourceName] = $model;
            }

            if (in_array('App\Traits\HasExport', class_uses($model))) {
                ExportController::addToClassMap($resourceName, $model);
                $exportMap[$resourceName] = $model;
            }
        }

        // Update cache file
        $cachePath = base_path('bootstrap/cache/import_export_models.php');
        $content = "<?php return " . var_export(['imports' => $importMap, 'exports' => $exportMap], true) . ";";
        file_put_contents($cachePath, $content);
    }


}
