<?php declare(strict_types=1);

namespace InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\DatabaseInspectionCommand;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;
use function Laravel\Prompts\table;

/**
 * Inspired by @see https://medium.com/beyn-technology/ill-never-forget-this-number-4294967295-0xffffffff-c9ad4b72f53a
 * namely this code: @see https://gist.github.com/ilyasozkurt/48287a665fe9158c23fda716867bfcb6
 *
 * The command supports:
 * MySQL 5.7:
 * - https://dev.mysql.com/doc/refman/5.7/en/integer-types.html
 * - https://dev.mysql.com/doc/refman/5.7/en/fixed-point-types.html
 * MySQL 8.0:
 * - https://dev.mysql.com/doc/refman/8.0/en/integer-types.html
 * - https://dev.mysql.com/doc/refman/8.0/en/fixed-point-types.html
 */
#[AsCommand('database:find-risky-columns')]
final class FindRiskyDatabaseColumns extends DatabaseInspectionCommand
{
    /**
     * @var string The name and signature of the console command.
     */
    protected $signature = 'database:find-risky-columns {connection=default} {--threshold=70 : Percentage occupied rows number on which the command should treat it as an issue}';

    /**
     * @var string The console command description.
     */
    protected $description = 'Find risky auto-incremental columns on databases which values are close to max possible values.';

    /**
     * @var array<string, array{min: int|float, max: int|float}>
     */
    private array $columnMinsAndMaxs = [
        'integer' => [
            'min' => -2_147_483_648,
            'max' => 2_147_483_647,
        ],
        'int unsigned' => [
            'min' => 0,
            'max' => 4_294_967_295,
        ],
        'bigint' => [
            'min' => -9_223_372_036_854_775_808,
            'max' => 9_223_372_036_854_775_807,
        ],
        'bigint unsigned' => [
            'min' => 0,
            'max' => 18_446_744_073_709_551_615,
        ],
        'tinyint' => [
            'min' => -128,
            'max' => 127,
        ],
        'tinyint unsigned' => [
            'min' => 0,
            'max' => 255,
        ],
        'smallint' => [
            'min' => -32_768,
            'max' => 32_767,
        ],
        'smallint unsigned' => [
            'min' => 0,
            'max' => 65_535,
        ],
        'mediumint' => [
            'min' => -8_388_608,
            'max' => 8_388_607,
        ],
        'mediumint unsigned' => [
            'min' => 0,
            'max' => 16_777_215,
        ],
        'decimal' => [
            'min' => -99999999999999999999999999999.99999999999999999999999999999,
            'max' => 99999999999999999999999999999.99999999999999999999999999999,
        ],
        'decimal unsigned' => [
            'min' => 0,
            'max' => 99999999999999999999999999999.99999999999999999999999999999,
        ],
    ];

    public function handle(ConnectionResolverInterface $connections): int
    {
        $thresholdAlarmPercentage = (float) $this->option('threshold');
        $connection = Schema::getConnection();
        if (! $connection instanceof MySqlConnection) {
            throw new \InvalidArgumentException('Command supports MySQL DBs only.');
        }

        $outputTable = [];

        foreach (Schema::getTables() as $table) {
            $riskyColumnsInfo = $this->processTable($table, $connection, $thresholdAlarmPercentage);
            if (is_array($riskyColumnsInfo)) {
                $outputTable = [...$outputTable, ...$riskyColumnsInfo];
            }
        }

        if (count($outputTable) === 0) {
            $this->info('No issues found.');
            return self::SUCCESS;
        }

        $this->error(sprintf('%d auto-incremental column(s) found where %s%% of the total possible values have already been used.', count($outputTable), $thresholdAlarmPercentage), 'quiet');

        $keys = array_column($outputTable, 'percentage');
        array_multisort($keys, \SORT_DESC, $outputTable);

        $this->table(['Table', 'Column', 'Type', 'Size', 'Cur. Val', 'Max. Val', 'Occupancy (%)'], $outputTable);

        return self::FAILURE;
    }

    /**
     * @return list<array<string, string>>|null
     */
    private function processTable(array $table, Connection $connection, float $thresholdAlarmPercentage): ?array
    {
        $tableName = Arr::get($table, 'name');
        $this->comment("Table {$connection->getDatabaseName()}.{$tableName}: checking...", 'v');

        $tableSize = Arr::get($table, 'size');

        if ($tableSize === null) {
            $tableSize = -1; // not critical info, we can skip this issue
        }

        /**
         * @var \Illuminate\Support\Collection<int, Schema> $getColumns
         */
        $columns = collect(Schema::getColumns($tableName))->filter(
            static fn($column): bool => Arr::get($column, 'auto_increment') === true
        );

        $riskyColumnsInfo = [];

        foreach ($columns as $column) {
            $columnName = Arr::get($column, 'name');
            $columnType = Arr::get($column, 'type');

            $this->comment("\t{$columnName} is autoincrement.", 'vvv');

            $maxValueForColumnKey = $this->getMaxValueForColumn($columnType);
            $currentHighestValue = $this->getCurrentHighestValueForColumn($connection->getDatabaseName(), $tableName, $columnName);

            $percentageUsed = round($currentHighestValue / $maxValueForColumnKey * 100, 4);

            if ($percentageUsed >= $thresholdAlarmPercentage) {
                $this->error("{$connection->getDatabaseName()}.{$tableName}.{$columnName} is full for {$percentageUsed}%  (threshold for allowed usage is {$thresholdAlarmPercentage}%)", 'quiet');

                $riskyColumnsInfo[] = [
                    'table' => "{$connection->getDatabaseName()}.{$tableName}",
                    'column' => $columnName,
                    'type' => $columnType,
                    'size' => $this->formatBytes($tableSize, 2),
                    'current' => number_format($currentHighestValue),
                    'max' => number_format($maxValueForColumnKey),
                    'percentage' => sprintf('<bg=red;options=bold>%s</>', round($percentageUsed, 4)),
                ];
            }
        }

        $this->comment("Table {$connection->getDatabaseName()}.{$tableName}: OK", 'vv');

        return count($riskyColumnsInfo) > 0
            ? $riskyColumnsInfo
            : null;
    }

    private function getMaxValueForColumn(string $columnType): int | float
    {
        if (array_key_exists($columnType, $this->columnMinsAndMaxs)) {
            return $this->columnMinsAndMaxs[$columnType]['max'];
        }

        throw new \RuntimeException("Could not find max value for `{$columnType}` column type.");
    }

    private function getCurrentHighestValueForColumn(string $database, string $tableName, string $columnName): int
    {
        $currentHighestValue = DB::select("SELECT MAX(`{$columnName}`) FROM `{$database}`.`{$tableName}`");
        $currentHighestFieldName = array_keys((array) $currentHighestValue[0])[0];

        return $currentHighestValue[0]->{$currentHighestFieldName} ?? 0;
    }

    private function formatBytes(int $size, int $precision): string
    {
        if ($size === 0) {
            return '0';
        }

        $base = log($size) / log(1024);
        $suffixes = [' bytes', ' KB', ' MB', ' GB', ' TB'];
        $index = (int) floor($base);
        if (! array_key_exists($index, $suffixes)) {
            throw new \RuntimeException('Unknown size unit.');
        }

        $suffix = $suffixes[$index];
        return round(1024 ** ($base - floor($base)), $precision).$suffix;
    }
}
