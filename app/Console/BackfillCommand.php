<?php

declare(strict_types=1);

namespace App\Console;

use App\Backfill\BackfillService;
use App\Post\RetentionService;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final class BackfillCommand
{
    use HasConsole;

    public function __construct(
        private readonly BackfillService $backfill,
        private readonly RetentionService $retention,
    ) {}

    #[ConsoleCommand(name: 'posts:backfill', description: 'Recover matching posts missed while the subscription was down')]
    public function backfill(): void
    {
        $this->console->success("Indexed {$this->backfill->run()} new posts.");
    }

    #[ConsoleCommand(name: 'posts:prune', description: 'Delete posts older than the retention window')]
    public function prune(): void
    {
        $this->console->success("Pruned {$this->retention->prune()} posts.");
    }
}
