<?php declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InteractionDesignFoundation\LaravelDatabaseToolkit\DatabaseToolkitServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }
    /**
     * Load package service provider.
     *
     * @param  \Illuminate\Foundation\Application $app
     * @return list<string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DatabaseToolkitServiceProvider::class,
        ];
    }

    protected function tearDown(): void
    {
        collect(Schema::getTableListing())
            ->filter(fn($table) => Str::startsWith($table, 'dummy'))
            ->each(fn($tableName) => Schema::dropIfExists($tableName));

        parent::tearDown();
    }
}
