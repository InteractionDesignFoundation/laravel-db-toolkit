<?php declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Modules\Publication\Models\Quote;
use Illuminate\Testing\PendingCommand;
use InteractionDesignFoundation\LaravelDatabaseToolkit\Console\Commands\FindRiskyDatabaseColumns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApplicationTestCase;
use Tests\Factories\Publication\QuoteFactory;

#[CoversClass(\App\Modules\Infrastructure\Console\Commands\FindRiskyDatabaseColumns::class)]
final class FindRiskyDatabaseColumnsTest extends ApplicationTestCase
{
    #[Test]
    public function it_works_with_default_threshold(): void
    {
        $pendingCommand = $this->artisan(FindRiskyDatabaseColumns::class);

        assert($pendingCommand instanceof PendingCommand);
        $pendingCommand->assertExitCode(0);
    }

    #[Test]
    public function it_fails_on_very_low_threshold(): void
    {
        /** We can create {@see \App\Modules\Logging\Models\VisitorActivity} instances, but they are slow to create (too many indexes) */
        QuoteFactory::new()->createOne(['id' => 42_000]);

        $pendingCommand = $this->artisan(FindRiskyDatabaseColumns::class, ['--threshold' => 0.001]);

        assert($pendingCommand instanceof PendingCommand);
        $pendingCommand->assertExitCode(1);
        $pendingCommand->expectsOutputToContain(get_table_name(Quote::class).'.id is full for '); // check line like "ixdf.quote.id is 0.0148% full (threshold for allowed usage is 0.0000001%)\n"
    }
}
