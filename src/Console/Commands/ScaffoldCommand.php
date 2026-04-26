<?php

namespace OmniPorter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ScaffoldCommand extends Command
{
    protected $signature = 'omniporter:scaffold {model : The model class name (e.g. User)} {--path=app/OmniPorter : Where to save the files} {--force : Overwrite existing files}';
    protected $description = 'Scaffold validation and exportable files for a model';

    public function handle()
    {
        $modelName = $this->argument('model');
        $path = $this->option('path');
        $force = $this->option('force');
        
        // Attempt to find the full model class if only basename is given
        $modelClass = $this->resolveModelClass($modelName);
        if (!$modelClass || !class_exists($modelClass)) {
            $this->error("Model class [{$modelName}] not found. Please provide the full namespace if it's not in App\\Models.");
            return 1;
        }

        $fullPath = base_path($path);
        if (!File::exists($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }

        $this->scaffoldValidation($modelName, $fullPath, $force);
        $this->scaffoldExportable($modelName, $fullPath, $force);

        $this->info("Scaffolding completed for {$modelName} in {$path}");
        $this->warn("Don't forget to register these in your model using the 'omni_porter_config' property.");
    }

    private function resolveModelClass($name)
    {
        if (class_exists($name)) return $name;
        $appModel = "App\\Models\\{$name}";
        if (class_exists($appModel)) return $appModel;
        return null;
    }

    private function scaffoldValidation($model, $path, $force = false)
    {
        $className = "{$model}Validation";
        $file = "{$path}/{$className}.php";
        
        if (File::exists($file) && !$force) {
            $this->error("{$className} already exists! Use --force to overwrite.");
            return;
        }

        $customStub = base_path('resources/stubs/vendor/omniporter/validation.stub');
        $stubPath = File::exists($customStub) ? $customStub : __DIR__ . '/../../../resources/stubs/validation.stub';
        
        $stub = File::get($stubPath);
        $content = str_replace(
            ['{{namespace}}', '{{className}}'],
            ['App\OmniPorter', $className],
            $stub
        );

        File::put($file, $content);
        $this->line("Created: {$file}");
    }

    private function scaffoldExportable($model, $path, $force = false)
    {
        $className = "{$model}Exportable";
        $file = "{$path}/{$className}.php";

        if (File::exists($file) && !$force) {
            $this->error("{$className} already exists! Use --force to overwrite.");
            return;
        }

        $customStub = base_path('resources/stubs/vendor/omniporter/exportable.stub');
        $stubPath = File::exists($customStub) ? $customStub : __DIR__ . '/../../../resources/stubs/exportable.stub';

        $stub = File::get($stubPath);
        $content = str_replace(
            ['{{namespace}}', '{{className}}'],
            ['App\OmniPorter', $className],
            $stub
        );

        File::put($file, $content);
        $this->line("Created: {$file}");
    }
}
