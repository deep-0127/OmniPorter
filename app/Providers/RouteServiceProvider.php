<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        });
    }

    public static function loadVersionedRoutes(string $version): void
    {
        $featuresPath = app_path('Features');

        $featureDirectories = File::directories($featuresPath);

        foreach ($featureDirectories as $featureDir) {
            $routePath = "{$featureDir}/Http/Routes/{$version}";

            if (File::isDirectory($routePath)) {
                $files = File::files($routePath);

                foreach ($files as $file) {
                    Route::prefix($version)
                        ->middleware('api')
                        ->group($file->getPathname());
                }
            }
        }
    }
}
