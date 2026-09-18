<?php

declare(strict_types=1);

namespace App\Console;

use App\Feed\FeedConfig;
use App\Feed\FeedService;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final class PublishFeedCommand
{
    use HasConsole;

    public function __construct(
        private readonly FeedConfig $config,
        private readonly FeedService $feedService,
    ) {}

    #[ConsoleCommand(name: 'feed:publish', description: 'Publish the feed generator record to the publisher account')]
    public function publish(): void
    {
        $this->console->info("Publishing {$this->config->feedUri()}");
        $this->console->info("  service DID: {$this->config->serviceDid}");
        $this->console->info('  avatar:      ' . ($this->config->avatarPath ?? 'none (FEEDGEN_AVATAR is unset; an existing avatar is removed)'));

        if (!$this->console->confirm('Publish this feed record?')) {
            $this->console->info('Aborted');

            return;
        }

        $this->feedService->publish();
        $this->console->success('Published.');
    }

    #[ConsoleCommand(name: 'feed:unpublish', description: 'Delete the feed generator record')]
    public function unpublish(): void
    {
        if (!$this->console->confirm("Delete {$this->config->feedUri()}?")) {
            $this->console->info('Aborted');

            return;
        }

        $this->feedService->unpublish();
        $this->console->success('Deleted.');
    }
}
