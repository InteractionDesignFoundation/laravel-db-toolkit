<?php declare(strict_types=1);

namespace InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\DatabaseInspectionCommand;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('database:find-invalid-values')]
final class FindInvalidDatabaseValues extends DatabaseInspectionCommand
{
    private const CHECK_TYPE_NULL = 'null';
    private const CHECK_TYPE_DATETIME = 'datetime';
    private const CHECK_TYPE_LONG_TEXT = 'long_text';
    private const CHECK_TYPE_LONG_STRING = 'long_string';

    /** @var string The name and signature of the console command. */
    protected $signature = 'database:find-invalid-values {connection=default} {--check=* : Check only specific types of issues. Available types: {null, datetime, long_text, long_string}}';

    /** @var string The console command description. */
    protected $description = 'Find invalid data created in non-strict SQL mode.';

    private int $valuesWithIssuesFound = 0;

    public function handle(ConnectionResolverInterface $connections): int
    {
        $connection = $this->getConnection($connections);
        if (!$connection instanceof MySqlConnection) {
            throw new \InvalidArgumentException('Command supports MySQL DBs only.');
        }

        $tables = Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();

        foreach ($tables as $tableName) {
            $columns = Schema::getConnection()->getDoctrineSchemaManager()->listTableColumns($tableName);
            foreach ($columns as $column) {
                $this->processColumn($column, $tableName, $connection);
            }
        }

        if ($this->valuesWithIssuesFound === 0) {
            return self::SUCCESS;
        }

        $this->error("Found {$this->valuesWithIssuesFound} Database values with issues.");

        return self::FAILURE;
    }

    private function processColumn(object $column, string $tableName, Connection $connection): void
    {
        $this->info("{$tableName}.{$column->getName()}:\t{$column->getType()->getName()}", 'vvv');

        if ($this->shouldRunCheckType(self::CHECK_TYPE_NULL)) {
            $this->checkNullOnNotNullableColumn($column, $connection, $tableName);
        }

        if ($this->shouldRunCheckType(self::CHECK_TYPE_DATETIME)) {
            $this->checkForInvalidDatetimeValues($column, $connection, $tableName);
        }

        if ($this->shouldRunCheckType(self::CHECK_TYPE_LONG_TEXT)) {
            $this->checkForTooLongTextTypeValues($column, $connection, $tableName);
        }

        if ($this->shouldRunCheckType(self::CHECK_TYPE_LONG_STRING)) {
            $this->checkForTooLongStringTypeValues($column, $connection, $tableName);
        }
    }

    private function getConnection(ConnectionResolverInterface $connections): Connection
    {
        $connectionName = $this->argument('connection');
        if ($connectionName === 'default') {
            $connectionName = config('database.default');
        }

        $connection = $connections->connection($connectionName);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function checkNullOnNotNullableColumn(object $column, Connection $connection, string $tableName): void
    {
        if ($column->getNotnull()) {
            $columnName = $column->getName();

            $nullsOnNotNullableColumnCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$tableName}` WHERE `{$columnName}` IS NULL")->count;
            if ($nullsOnNotNullableColumnCount > 0) {
                $this->error("{$tableName}.{$columnName} has {$nullsOnNotNullableColumnCount} NULLs but the column is not nullable.");
                $this->valuesWithIssuesFound += $nullsOnNotNullableColumnCount;
            } else {
                $this->comment("\t".self::CHECK_TYPE_NULL.': OK', 'vvv');
            }
        }
    }

    private function checkForInvalidDatetimeValues(object $column, Connection $connection, string $tableName): void
    {
        $columnType = $column->getType()->getName();
        $columnName = $column->getName();
        
        $integerProbablyUsedForTimestamp = in_array($columnType, ['integer', 'bigint'], true) && (str_contains($columnName, 'timestamp') || str_ends_with($columnName, '_at'));
        if (
            $integerProbablyUsedForTimestamp
            || in_array($columnType, ['date', 'datetime', 'timestamp'], true)
        ) {
            $invalidDatetimeRecordsCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$tableName}` WHERE `{$columnName}` <= 1")->count;
            if ($invalidDatetimeRecordsCount > 0) {
                $this->error("{$tableName}.{$columnName} has {$invalidDatetimeRecordsCount} invalid datetime values.");
                $this->valuesWithIssuesFound += $invalidDatetimeRecordsCount;
            } else {
                $this->comment("\t".self::CHECK_TYPE_DATETIME.': OK', 'vvv');
            }
        }
    }

    private function checkForTooLongTextTypeValues(object $column, Connection $connection, string $tableName): void
    {
        if ($column->getType()->getName() === 'text') {
            $columnName = $column->getName();

            $tooLongTextValuesCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$tableName}` WHERE LENGTH(`{$columnName}`) > @@max_allowed_packet;")->count;
            if ($tooLongTextValuesCount > 0) {
                $this->error("{$tableName}.{$columnName} has {$tooLongTextValuesCount} too long text values.");
                $this->valuesWithIssuesFound += $tooLongTextValuesCount;
            } else {
                $this->comment("\t".self::CHECK_TYPE_LONG_TEXT.': OK', 'vvv');
            }
        }
    }

    private function checkForTooLongStringTypeValues(object $column, Connection $connection, string $tableName): void
    {
        if (in_array($column->getType()->getName(), ['string', 'ascii_string'], true)) {
            $columnName = $column->getName();

            $maxLength = $column->getLength();

            if (is_int($maxLength) && $maxLength !== 0) {
                $tooLongStringValuesCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$tableName}` WHERE LENGTH(`{$columnName}`) > {$maxLength};")->count;
                if ($tooLongStringValuesCount > 0) {
                    $this->error("{$tableName}.{$columnName} has {$tooLongStringValuesCount} too long string values (longer than {$maxLength} chars).");
                    $this->valuesWithIssuesFound += $tooLongStringValuesCount;
                } else {
                    $this->comment("\t".self::CHECK_TYPE_LONG_STRING.': OK', 'vvv');
                }
            } else {
                $this->warn("Could not find max length for {$tableName}.{$columnName} column.");
            }
        }
    }

    private function shouldRunCheckType(string $type): bool
    {
        $checks = $this->option('check');

        return $checks === []
            || (is_array($checks) && in_array($type, $checks, true));
    }
}
