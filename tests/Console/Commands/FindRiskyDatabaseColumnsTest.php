<?php declare(strict_types=1);

namespace Tests\Console\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\Concerns\InteractsWithConsole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands\FindRiskyDatabaseColumns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(FindRiskyDatabaseColumns::class)]
final class FindRiskyDatabaseColumnsTest extends TestCase
{
    use InteractsWithConsole;

    #[Test]
    public function it_works_with_default_threshold(): void
    {
        Schema::create(
            'dummy_table_1', function (Blueprint $table) {
                $table->tinyIncrements('id')->startingValue(100);
                $table->string('name')->nullable();
            }
        );
        DB::table('dummy_table_1')->insert(['name' => 'foo']);

        $pendingCommand = $this->artisan(FindRiskyDatabaseColumns::class);

        assert($pendingCommand instanceof PendingCommand);
        $pendingCommand->assertExitCode(0);
    }

    #[Test]
    public function it_works_with_custom_threshold(): void
    {
        Schema::create(
            'dummy_table_2', function (Blueprint $table) {
                $table->tinyIncrements('id')->startingValue(130);
                $table->string('name')->nullable();
            }
        );
        DB::table('dummy_table_2')->insert(['name' => 'foo']);

        $pendingCommand = $this->artisan(FindRiskyDatabaseColumns::class, ['--threshold' => 50]);

        assert($pendingCommand instanceof PendingCommand);
        $pendingCommand->assertExitCode(1);
    }

    #[Test]
    public function it_fails_with_exceeding_threshold_tinyint(): void
    {
        Schema::create(
            'dummy_table_3', function (Blueprint $table) {
                $table->tinyIncrements('id')->startingValue(200);
                $table->string('name')->nullable();
            }
        );
        DB::table('dummy_table_3')->insert(['name' => 'foo']);

        $pendingCommand = $this->artisan(FindRiskyDatabaseColumns::class);

        assert($pendingCommand instanceof PendingCommand);
        $pendingCommand->assertExitCode(1);
    }
}
