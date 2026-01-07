<?php

namespace App\Features\Import\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ScanImportableModelsCommand extends Command
{
    protected $signature = 'import:discover';
    protected $description = 'Discover and cache all Importable/Exportable models';

    public function handle()
    {
        $modelPaths = glob(app_path('Features/**/Domain/**/Models/*.php'));
        $importMap = [];
        $exportMap = [];

        foreach ($modelPaths as $modelPath) {
            $modelClass = str_replace(
                [app_path(), '/', '.php'],
                ['App', '\\', ''],
                $modelPath
            );

            if (!class_exists($modelClass)) continue;

            $reflection = new \ReflectionClass($modelClass);
            if ($reflection->isAbstract()) continue;

            $resourceName = strtolower(class_basename($modelClass));

            if (in_array('App\Traits\HasImport', class_uses($modelClass))) {
                $importMap[$resourceName] = $modelClass;
            }

            if (in_array('App\Traits\HasExport', class_uses($modelClass))) {
                $exportMap[$resourceName] = $modelClass;
            }
        }

        $content = "<?php return " . var_export(['imports' => $importMap, 'exports' => $exportMap], true) . ";";

        file_put_contents(base_path('bootstrap/cache/import_export_models.php'), $content);

        $this->info('Discovery complete. Cache file created at bootstrap/cache/import_export_models.php');
    }
}
