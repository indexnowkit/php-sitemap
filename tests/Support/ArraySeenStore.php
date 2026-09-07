<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Support;

use IndexNowKit\Sitemap\SeenStoreInterface;
use IndexNowKit\Sitemap\SitemapEntry;

/**
 * A store of seen URLs in an array: the fingerprint is the URL plus its lastmod (ISO 8601, or empty), as the CLI's
 * sqlite store keeps it. Counts the `remember()` calls, so a test sees which batches were recorded.
 */
final class ArraySeenStore implements SeenStoreInterface
{
    /** @var array<string, string> url => fingerprint */
    public array $seen = [];

    /** @var list<list<string>> the URLs of every remember() call, in order */
    public array $remembered = [];

    public function unseen(iterable $entries): iterable
    {
        foreach ($entries as $entry) {
            if (($this->seen[$entry->url] ?? null) !== self::fingerprint($entry)) {
                yield $entry;
            }
        }
    }

    public function remember(iterable $entries): void
    {
        $urls = [];
        foreach ($entries as $entry) {
            $this->seen[$entry->url] = self::fingerprint($entry);
            $urls[] = $entry->url;
        }
        $this->remembered[] = $urls;
    }

    public static function fingerprint(SitemapEntry $entry): string
    {
        return $entry->url . "\n" . ($entry->lastmod?->format(DATE_ATOM) ?? '');
    }
}
