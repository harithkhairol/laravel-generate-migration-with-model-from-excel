<?php

namespace Harithkhairol\ExcelMigration;

use Illuminate\Support\ServiceProvider;
use Harithkhairol\ExcelMigration\Console\Commands\GenerateMigrationFromExcel;

class ExcelMigrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     * composer require harithkhairol/excel-migration
     *
     * @return void
     */
    public function register()
    {
        $this->commands([
            GenerateMigrationFromExcel::class,
        ]);

        // php artisan vendor:publish --tag=harithkhairol-spreadsheets
        $this->publishes([
            __DIR__.'/../resources/spreadsheets/migration_data.xlsx' => storage_path('spreadsheets/migration_data.xlsx'),
        ], 'harithkhairol-spreadsheets');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
