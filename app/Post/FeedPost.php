<?php

declare(strict_types=1);

namespace App\Post;

use Aazsamir\Libphpsky\Model\App\Bsky\Feed\Defs\SkeletonFeedPost;

final readonly class FeedPost
{
    public function __construct(
        public string $uri,
        public string $cid,
        /** Milliseconds since the epoch; the feed cursor is this value as a string. */
        public int $indexedAt,
    ) {}

    public function toSkeleton(): SkeletonFeedPost
    {
        return SkeletonFeedPost::new(post: $this->uri);
    }
}
