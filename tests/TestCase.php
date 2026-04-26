<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boots the application for tests.
     */
    public function createApplication()
    {
        $app = new \Illuminate\Foundation\Application(
            realpath(__DIR__ . '/../')
        );

        $app->singleton(
            \Illuminate\Contracts\Http\Kernel::class,
            \Illuminate\Foundation\Http\Kernel::class
        );

        $app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            \Illuminate\Foundation\Console\Kernel::class
        );

        $app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            \Illuminate\Foundation\Exceptions\Handler::class
        );

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app['config']->set('app.key', 'base64:uzL9J9yPpXz4W8eY9Xz4W8eY9Xz4W8eY9Xz4W8eY9Xz=');
        $app['config']->set('omniporter.cache.store', 'array');
        $app->register(\OmniPorter\OmniPorterServiceProvider::class);
        $app->register(\Maatwebsite\Excel\ExcelServiceProvider::class);

        return $app;
    }
}
