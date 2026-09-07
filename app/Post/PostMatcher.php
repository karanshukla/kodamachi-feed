<?php

declare(strict_types=1);

namespace App\Post;

use App\Feed\FeedConfig;

/**
 * Decides whether a post belongs in the feed.
 *
 * Deliberately works on the decoded Jetstream record rather than on
 * libphpsky's typed Post model: this runs against every post created on the
 * network, several hundred a second, while matches arrive a handful at a
 * time. Hydrating a full model for each one would spend all of that work on
 * records that are about to be discarded. The matched record is hydrated
 * later, once, by the caller.
 */
final readonly class PostMatcher
{
    public const string REASON_TEXT = 'text';
    public const string REASON_IMAGE_ALT = 'image-alt';

    /** @var list<string> */
    private array $textTerms;

    /** @var list<string> */
    private array $altTerms;

    public function __construct(FeedConfig $config)
    {
        $this->textTerms = $config->textTerms;
        $this->altTerms = $config->altTerms;
    }

    /**
     * @param array<string, mixed> $record an app.bsky.feed.post record
     *
     * @return self::REASON_*|null why the post matched, or null if it did not
     */
    public function match(array $record): ?string
    {
        $text = $record['text'] ?? '';

        if (is_string($text) && self::containsAny(mb_strtolower($text), $this->textTerms)) {
            return self::REASON_TEXT;
        }

        foreach ($this->altTexts($record) as $alt) {
            if (self::containsAny(mb_strtolower($alt), $this->altTerms)) {
                return self::REASON_IMAGE_ALT;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return iterable<string>
     */
    private function altTexts(array $record): iterable
    {
        $embed = $record['embed'] ?? null;

        if (!is_array($embed)) {
            return;
        }

        // Images can sit at the top level or, when a post quotes a record and
        // attaches media, under `media`.
        $candidates = [$embed];

        if (isset($embed['media']) && is_array($embed['media'])) {
            $candidates[] = $embed['media'];
        }

        foreach ($candidates as $candidate) {
            $type = $candidate['$type'] ?? null;

            if ($type !== 'app.bsky.embed.images' && $type !== 'app.bsky.embed.images#main') {
                continue;
            }

            $images = $candidate['images'] ?? [];

            if (!is_array($images)) {
                continue;
            }

            foreach ($images as $image) {
                if (is_array($image) && is_string($image['alt'] ?? null)) {
                    yield $image['alt'];
                }
            }
        }
    }

    /**
     * @param list<string> $terms
     */
    private static function containsAny(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }
}
