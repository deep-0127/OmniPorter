<?php

namespace OmniPorter\Import\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ScanImportableModelsCommand extends Command
{
    protected $signature = 'omniporter:discover';
    protected $description = 'Discover and cache all Importable/Exportable models for OmniPorter';

    public function handle()
    {
        $patterns = config('omniporter.discovery.model_paths', []);
        $importMap = [];
        $exportMap = [];

        foreach ($patterns as $pattern) {
            $modelPaths = glob(base_path($pattern)) ?: [];
            
            foreach ($modelPaths as $modelPath) {
                // Normalize slashes for Windows compatibility
                $modelPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $modelPath);
                $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, base_path());

                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $modelPath);
                
                $modelClass = str_replace(
                    [DIRECTORY_SEPARATOR, '.php'],
                    ['\\', ''],
                    $relativePath
                );
                
                // Handle different base directories (app/ vs Features/)
                if (str_starts_with($modelClass, 'app\\')) {
                    $modelClass = Str::replaceFirst('app\\', 'App\\', $modelClass);
                }

                if (!class_exists($modelClass)) {
                    continue;
                }

                $reflection = new \ReflectionClass($modelClass);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $resourceName = strtolower(class_basename($modelClass));
                $traits = class_uses_recursive($modelClass);

                if (in_array(\OmniPorter\Traits\HasImport::class, $traits, true)) {
                    $importMap[$resourceName] = $modelClass;
                }

                if (in_array(\OmniPorter\Traits\HasExport::class, $traits, true)) {
                    $exportMap[$resourceName] = $modelClass;
                }
            }
        }

        $content = "<?php\n\nreturn " . var_export([
            'imports' => $importMap,
            'exports' => $exportMap,
        ], true) . ";\n";

        $cachePath = base_path('bootstrap/cache/omniporter_models.php');
        
        if (!file_exists(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }

        file_put_contents($cachePath, $content);
        
        $this->info('Discovery complete. Cache file created at bootstrap/cache/omniporter_models.php');
    }
}
