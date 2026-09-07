<?php

declare(strict_types=1);

namespace Tests\Unit\Post;

use App\Feed\FeedConfig;
use App\Post\PostMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PostMatcherTest extends TestCase
{
    #[Test]
    public function matches_a_term_in_the_post_text(): void
    {
        self::assertSame(
            PostMatcher::REASON_TEXT,
            $this->matcher()->match(['text' => 'wandering around Kodamachi today']),
        );
    }

    #[Test]
    public function matching_is_case_insensitive(): void
    {
        self::assertSame(
            PostMatcher::REASON_TEXT,
            $this->matcher()->match(['text' => 'KODAMACHI']),
        );
    }

    #[Test]
    public function matches_any_configured_text_term(): void
    {
        $matcher = $this->matcher(textTerms: ['kodamachi', 'kodamachi.example']);

        self::assertSame(PostMatcher::REASON_TEXT, $matcher->match(['text' => 'see kodamachi.example']));
    }

    #[Test]
    public function matches_a_term_in_image_alt_text(): void
    {
        $record = [
            'text' => 'no term here',
            'embed' => [
                '$type' => 'app.bsky.embed.images',
                'images' => [
                    ['alt' => 'a street in kodamachi'],
                ],
            ],
        ];

        self::assertSame(PostMatcher::REASON_IMAGE_ALT, $this->matcher()->match($record));
    }

    /**
     * A quote post with attached media puts the images under `media` rather
     * than at the top level, so alt text there would otherwise be invisible.
     */
    #[Test]
    public function matches_alt_text_on_media_attached_to_a_quote(): void
    {
        $record = [
            'text' => '',
            'embed' => [
                '$type' => 'app.bsky.embed.recordWithMedia',
                'media' => [
                    '$type' => 'app.bsky.embed.images',
                    'images' => [
                        ['alt' => 'kodamachi at dusk'],
                    ],
                ],
            ],
        ];

        self::assertSame(PostMatcher::REASON_IMAGE_ALT, $this->matcher()->match($record));
    }

    #[Test]
    public function text_takes_precedence_over_alt_text(): void
    {
        $record = [
            'text' => 'kodamachi',
            'embed' => [
                '$type' => 'app.bsky.embed.images',
                'images' => [['alt' => 'kodamachi']],
            ],
        ];

        self::assertSame(PostMatcher::REASON_TEXT, $this->matcher()->match($record));
    }

    #[Test]
    public function ignores_a_post_with_no_matching_term(): void
    {
        self::assertNull($this->matcher()->match(['text' => 'an ordinary post']));
    }

    #[Test]
    public function ignores_alt_text_on_an_embed_that_is_not_images(): void
    {
        $record = [
            'text' => '',
            'embed' => [
                '$type' => 'app.bsky.embed.external',
                'external' => ['title' => 'kodamachi'],
            ],
        ];

        self::assertNull($this->matcher()->match($record));
    }

    #[Test]
    public function survives_a_record_with_missing_or_odd_fields(): void
    {
        $matcher = $this->matcher();

        self::assertNull($matcher->match([]));
        self::assertNull($matcher->match(['text' => null]));
        self::assertNull($matcher->match(['text' => '', 'embed' => 'not an array']));
        self::assertNull($matcher->match(['text' => '', 'embed' => ['$type' => 'app.bsky.embed.images']]));
        self::assertNull($matcher->match([
            'text' => '',
            'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => null], 'nope']],
        ]));
    }

    /**
     * @param list<string> $textTerms
     * @param list<string> $altTerms
     */
    private function matcher(array $textTerms = ['kodamachi'], array $altTerms = ['kodamachi']): PostMatcher
    {
        return new PostMatcher(new FeedConfig(
            hostname: 'feed.test',
            serviceDid: 'did:web:feed.test',
            publisherDid: 'did:plc:publisher',
            textTerms: $textTerms,
            altTerms: $altTerms,
        ));
    }
}
