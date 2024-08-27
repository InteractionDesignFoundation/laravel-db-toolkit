<?php declare(strict_types=1);

namespace Tests\Console\Commands;

use Illuminate\Foundation\Testing\Concerns\InteractsWithConsole;
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
        $pendingCommand = $this->artisan(FindRiskyDatabaseColumns::class);

        assert($pendingCommand instanceof PendingCommand);
        $pendingCommand->assertExitCode(0);
    }
}
