<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use OmniPorter\OmniPorterServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            OmniPorterServiceProvider::class,
            ExcelServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('omniporter.cache.store', 'array');
        $app['config']->set('app.key', 'base64:uzL9J9yPpXz4W8eY9Xz4W8eY9Xz4W8eY9Xz4W8eY9Xz=');
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
