<?php

declare(strict_types=1);

namespace App\Console;

use App\Jetstream\JetstreamSubscription;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final class CollectPostsCommand
{
    use HasConsole;

    public function __construct(
        private readonly JetstreamSubscription $subscription,
    ) {}

    #[ConsoleCommand(name: 'posts:collect', description: 'Index matching posts from Jetstream until stopped')]
    public function __invoke(): void
    {
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
