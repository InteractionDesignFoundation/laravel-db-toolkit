<?php declare(strict_types=1);

namespace InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\DatabaseInspectionCommand;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('database:find-invalid-values')]
final class FindInvalidDatabaseValues extends DatabaseInspectionCommand
{
    private const CHECK_TYPE_NULL = 'null';
    private const CHECK_TYPE_DATETIME = 'datetime';
    private const CHECK_TYPE_LONG_TEXT = 'long_text';
    private const CHECK_TYPE_LONG_STRING = 'long_string';

    /**
     * @var string The name and signature of the console command. 
     */
    protected $signature = 'database:find-invalid-values {connection=default} {--check=* : Check only specific types of issues. Available types: {null, datetime, long_text, long_string}}';

    /**
     * @var string The console command description. 
     */
    protected $description = 'Find invalid data created in non-strict SQL mode.';

    private int $valuesWithIssuesFound = 0;

    /**
     * @throws \Doctrine\DBAL\Exception 
     */
    public function handle(ConnectionResolverInterface $connections): int
    {
        $connection = $this->getConnection($connections);
        $schema = $connection->getDoctrineSchemaManager();
        if (!$connection instanceof MySqlConnection) {
            throw new \InvalidArgumentException('Command supports MySQL DBs only.');
        }

        $this->registerTypeMappings($schema->getDatabasePlatform());

        foreach ($schema->listTables() as $table) {
            foreach ($table->getColumns() as $column) {
                $this->processColumn($column, $table, $connection);
            }
        }

        if ($this->valuesWithIssuesFound === 0) {
            return self::SUCCESS;
        }

        $this->error("Found {$this->valuesWithIssuesFound} Database values with issues.");

        return self::FAILURE;
    }

    private function processColumn(Column $column, Table $table, Connection $connection): void
    {
        $this->info("{$table->getName()}.{$column->getName()}:\t{$column->getType()->getName()}", 'vvv');

        if ($this->shouldRunCheckType(self::CHECK_TYPE_NULL)) {
            $this->checkNullOnNotNullableColumn($column, $connection, $table);
        }

        if ($this->shouldRunCheckType(self::CHECK_TYPE_DATETIME)) {
            $this->checkForInvalidDatetimeValues($column, $connection, $table);
        }

        if ($this->shouldRunCheckType(self::CHECK_TYPE_LONG_TEXT)) {
            $this->checkForTooLongTextTypeValues($column, $connection, $table);
        }

        if ($this->shouldRunCheckType(self::CHECK_TYPE_LONG_STRING)) {
            $this->checkForTooLongStringTypeValues($column, $connection, $table);
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

    private function checkNullOnNotNullableColumn(Column $column, Connection $connection, Table $table): void
    {
        if ($column->getNotnull()) {
            $columnName = $column->getName();

            $nullsOnNotNullableColumnCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$table->getName()}` WHERE `{$columnName}` IS NULL")->count;
            if ($nullsOnNotNullableColumnCount > 0) {
                $this->error("{$table->getName()}.{$columnName} has {$nullsOnNotNullableColumnCount} NULLs but the column is not nullable.");
                $this->valuesWithIssuesFound += $nullsOnNotNullableColumnCount;
            } else {
                $this->comment("\t".self::CHECK_TYPE_NULL.': OK', 'vvv');
            }
        }
    }

    private function checkForInvalidDatetimeValues(Column $column, Connection $connection, Table $table): void
    {
        $integerProbablyUsedForTimestamp = in_array($column->getType()->getName(), [Types::INTEGER, Types::BIGINT], true) && (str_contains($column->getName(), 'timestamp') || str_ends_with($column->getName(), '_at'));
        if ($integerProbablyUsedForTimestamp
            || in_array($column->getType()->getName(), [Types::DATE_MUTABLE, Types::DATE_IMMUTABLE, Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE], true)
        ) {
            $columnName = $column->getName();

            $invalidDatetimeRecordsCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$table->getName()}` WHERE `{$columnName}` <= 1")->count;
            if ($invalidDatetimeRecordsCount > 0) {
                $this->error("{$table->getName()}.{$columnName} has {$invalidDatetimeRecordsCount} invalid datetime values.");
                $this->valuesWithIssuesFound += $invalidDatetimeRecordsCount;
            } else {
                $this->comment("\t".self::CHECK_TYPE_DATETIME.': OK', 'vvv');
            }
        }
    }

    private function checkForTooLongTextTypeValues(Column $column, Connection $connection, Table $table): void
    {
        if ($column->getType()->getName() === Types::TEXT) {
            $columnName = $column->getName();

            $tooLongTextValuesCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$table->getName()}` WHERE LENGTH(`{$columnName}`) > @@max_allowed_packet;")->count;
            if ($tooLongTextValuesCount > 0) {
                $this->error("{$table->getName()}.{$columnName} has {$tooLongTextValuesCount} too long text values.");
                $this->valuesWithIssuesFound += $tooLongTextValuesCount;
            } else {
                $this->comment("\t".self::CHECK_TYPE_LONG_TEXT.': OK', 'vvv');
            }
        }
    }

    private function checkForTooLongStringTypeValues(Column $column, Connection $connection, Table $table): void
    {
        if (in_array($column->getType()->getName(), [Types::STRING, Types::ASCII_STRING], true)) {
            $columnName = $column->getName();

            $maxLength = $column->getLength();

            if (is_int($maxLength) && $maxLength !== 0) {
                $tooLongStringValuesCount = DB::selectOne("SELECT COUNT(`{$columnName}`) AS `count` FROM {$connection->getDatabaseName()}.`{$table->getName()}` WHERE LENGTH(`{$columnName}`) > {$maxLength};")->count;
                if ($tooLongStringValuesCount > 0) {
                    $this->error("{$table->getName()}.{$columnName} has {$tooLongStringValuesCount} too long string values (longer than {$maxLength} chars).");
                    $this->valuesWithIssuesFound += $tooLongStringValuesCount;
                } else {
                    $this->comment("\t".self::CHECK_TYPE_LONG_STRING.': OK', 'vvv');
                }
            } else {
                $this->warn("Could not find max length for {$table->getName()}.{$columnName} column.");
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
