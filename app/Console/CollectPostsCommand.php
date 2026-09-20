<?php

declare(strict_types=1);

namespace App\Console;

use App\Jetstream\JetstreamSubscription;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;
use Tempest\Database\Query;

final class CollectPostsCommand
{
    use HasConsole;

    public function __construct(
        private readonly JetstreamSubscription $subscription,
    ) {}

    #[ConsoleCommand(name: 'posts:collect', description: 'Index matching posts from Jetstream until stopped')]
    public function __invoke(): void
    {
        // Two processes share this file: the collector writes while web
        // workers read and bump rate-limit counters. Under the default
        // rollback journal a write blocks every reader for its duration; WAL
        // lets them run side by side. The mode is stored in the file, so
        // setting it here covers the web process too.
        new Query('PRAGMA journal_mode = WAL')->execute();

        pcntl_async_signals(true);

        // Stop between messages rather than mid-write, so the cursor that gets
        // persisted on the way out matches what was actually indexed.
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () {
                $this->console->info('Stopping...');
                $this->subscription->stop();
            });
        }

        $this->subscription->run();
    }
}
