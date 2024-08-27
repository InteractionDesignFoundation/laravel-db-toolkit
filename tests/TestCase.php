<?php declare(strict_types=1);

namespace Tests;

use InteractionDesignFoundation\LaravelDatabaseToolkit\DatabaseToolkitServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Load package service provider.
     * @param \Illuminate\Foundation\Application $app
     * @return list<string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DatabaseToolkitServiceProvider::class,
        ];
    }
}
