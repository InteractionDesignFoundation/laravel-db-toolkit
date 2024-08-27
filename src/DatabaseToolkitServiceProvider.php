<?php declare(strict_types=1);

namespace InteractionDesignFoundation\LaravelDatabaseToolkit;

use Illuminate\Support\ServiceProvider;
use InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands\FindInvalidDatabaseValues;
use InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands\FindRiskyDatabaseColumns;

final class DatabaseToolkitServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     * @see https://laravel.com/docs/master/packages#commands
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FindInvalidDatabaseValues::class,
                FindRiskyDatabaseColumns::class,
            ]);
        }
    }
}
