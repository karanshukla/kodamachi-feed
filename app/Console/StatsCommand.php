<?php

declare(strict_types=1);

namespace App\Console;

use App\Feed\FeedConfig;
use App\Post\FeedPostRepository;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final class StatsCommand
{
    use HasConsole;

    public function __construct(
        private readonly FeedConfig $config,
        private readonly FeedPostRepository $repository,
    ) {}

    #[ConsoleCommand(name: 'feed:stats', description: 'Show what is indexed and where the subscription is')]
    public function __invoke(): void
    {
        $newest = $this->repository->newestIndexedAt();
        $cursor = $this->repository->cursor($this->config->subscriptionEndpoint);

        $this->console->writeln("feed:      {$this->config->feedUri()}");
        $this->console->writeln("service:   {$this->config->serviceDid}");
        $this->console->writeln('matching:  text ' . implode(', ', $this->config->textTerms)
            . ' | alt ' . implode(', ', $this->config->altTerms));
        $this->console->writeln("posts:     {$this->repository->count()}");
        $this->console->writeln('newest:    ' . ($newest === null
            ? 'none indexed'
            : date('c', intdiv($newest, 1000))));
        $this->console->writeln('cursor:    ' . ($cursor === null
            ? 'live tip'
            : $cursor . ' (' . date('c', intdiv($cursor, 1_000_000)) . ')'));
    }
}
