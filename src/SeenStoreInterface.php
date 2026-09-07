<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

/**
 * What `sitemap --new-only` needs: the fingerprint of every entry it announced before, so that a scheduled run
 * submits only what is new or changed — whatever `<lastmod>` says or does not say (many generators write none, and a
 * one-day `--changed-since` window misses in both directions on a deploy). The fingerprint is the URL plus its
 * `<lastmod>` (ISO 8601, or empty); a changed `lastmod` is a changed entry, an entry without one is new once.
 *
 * The runner calls {@see unseen()} on the stream of entries once (after the host filter, before the batches) and
 * {@see remember()} once per batch whose results did not fail — never with `--dry-run`. An adapter that has no store
 * passes none: the option then answers with one sentence and exit 2 (`ExitCode::INVALID`). Implementations live where
 * the state does: the `indexnow` CLI keeps one in its sqlite state file; a framework adapter may keep a table.
 */
interface SeenStoreInterface
{
    /**
     * The entries whose fingerprint is unknown or differs from the remembered one, in the order given, streamed:
     * an implementation looks each entry up as it passes and holds no list.
     *
     * @param iterable<SitemapEntry> $entries
     *
     * @return iterable<SitemapEntry>
     */
    public function unseen(iterable $entries): iterable;

    /**
     * Records the fingerprints of these entries (one batch of the runner) as seen, replacing what was known.
     *
     * @param iterable<SitemapEntry> $entries
     */
    public function remember(iterable $entries): void;
}
