<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Console;

use DateTimeImmutable;
use Exception;
use Generator;
use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapEntry;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Submission\ResultSummary;
use IndexNowKit\Url\Punycode;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:sitemap [sitemap]`: reads a sitemap (or sitemap index) as a stream and submits it in batches of
 * `batch.max_urls`, so the URL list never has to fit in memory. The source is whatever implements
 * {@see SitemapSourceInterface} (the shipped {@see SitemapReader}, or the application's decorator/replacement);
 * `--allow-foreign-hosts` only reaches the shipped reader.
 *
 * Whatever the source, a `<loc>` on a host this site has no key for is dropped before anything fetches or submits it
 * ({@see onManagedHosts()}): the document decides which URLs are read, and the pre-flight of indexnowkit/verify would
 * otherwise GET every address it names.
 */
final class SitemapRunner
{
    /**
     * @param string|null $defaultSitemap   `sitemap.url` from the adapter config ({@see SitemapConfig::$url}); falls back to <base_url>/sitemap.xml
     * @param string      $sitemapUrlOption how the adapter names that option (`indexnowkit.sitemap.url`), printed when no sitemap is known
     * @param SubmitterFactoryInterface|null $unverifiedSubmitters the plain factory `--no-verify` submits through when the adapter
     *                                                             decorated `$submitters` with the pre-flight of indexnowkit/verify;
     *                                                             null = `$submitters` (the flag then changes nothing)
     */
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SitemapSourceInterface $reader,
        private readonly SubmitterFactoryInterface $submitters,
        private readonly ?string $defaultSitemap = null,
        private readonly ResultFormatterInterface $formatter = new ResultRenderer(),
        private readonly string $sitemapUrlOption = 'sitemap.url',
        private readonly ?SubmitterFactoryInterface $unverifiedSubmitters = null,
    ) {}

    /**
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, SitemapOptions $options): int
    {
        $json = $options->json;
        $sitemap = $this->sitemapUrl($options->sitemap);
        if ($sitemap === null) {
            $io->error(\sprintf('Give a sitemap URL, or configure %s or base_url.', $this->sitemapUrlOption));

            return ExitCode::INVALID;
        }
        try {
            $since = self::changedSince($options->changedSince);
        } catch (Exception $e) {
            $io->error(\sprintf('--changed-since: %s', $e->getMessage()));

            return ExitCode::INVALID;
        }
        $allowForeignHosts = $options->allowForeignHosts ? true : null;
        if ($allowForeignHosts === true && !$this->reader instanceof SitemapReader) {
            $io->warning(\sprintf('--allow-foreign-hosts is an option of the shipped SitemapReader; the configured source (%s) decides on its own.', $this->reader::class));
        }
        $entries = $this->reader instanceof SitemapReader ? $this->reader->read($sitemap, $since, $allowForeignHosts) : $this->reader->read($sitemap, $since);
        $found = 0;

        // A <loc> names any host it likes, and a sitemap built from user content (or a swapped one) would otherwise
        // make the pre-flight of indexnowkit/verify GET internal addresses. Only hosts this site has a key for pass.
        $managed = $this->indexNow->keys->managedHosts();
        $skipped = [];
        if ($managed === []) {
            ($json ? $io->getErrorStyle() : $io)->warning('No host can be derived from base_url or the hosts map, so the <loc> entries are submitted whatever host they name. Configure base_url (and strict_hosts: true when the sitemap is built from user content).');
        } else {
            $entries = self::onManagedHosts($entries, $managed, $skipped);
        }

        if ($options->dryRun) {
            try {
                $found = $json ? self::listJson($io, $entries) : self::listText($io, $entries);
            } catch (TransportException $e) {
                $io->error(\sprintf('Cannot read %s: %s', $sitemap, $e->getMessage()));

                return ExitCode::FAILURE;
            }
            if (!$json) {
                $io->text(self::foundLine($found, $sitemap, $since));
            }
            self::skippedNote($io, $skipped, $json);

            return ExitCode::SUCCESS;
        }

        // --no-verify: the plain factory, and a fresh submitter from it even without --force (the application's own is the decorated one).
        $submitter = $options->noVerify && $this->unverifiedSubmitters !== null
            ? $this->unverifiedSubmitters->create($options->force, false)
            : SubmitterFactory::choose($this->submitters, $this->indexNow, $options->force, false);
        $batchSize = max(1, $this->indexNow->config->batchMaxUrls);
        $summary = new ResultSummary();
        $batch = [];
        $batches = 0;
        try {
            foreach ($entries as $entry) {
                ++$found;
                $batch[] = $entry->url;
                if (\count($batch) >= $batchSize) {
                    $summary->add($submitter->submit($batch));
                    $batch = [];
                    ++$batches;
                    if (!$json && $io->isVerbose()) {
                        $io->text(\sprintf('  batch %d: %d URL(s) read so far', $batches, $found));
                    }
                }
            }
        } catch (TransportException $e) {
            // Whatever was read before the failure is still worth announcing; the re-run is idempotent anyway.
            if ($batch !== []) {
                $summary->add($submitter->submit($batch));
                ++$batches;
            }
            $error = \sprintf('Cannot read %s: %s', $sitemap, $e->getMessage());
            if ($json) {
                // stdout stays machine-readable: the partial summary as JSON, the error on stderr.
                $io->getErrorStyle()->error($error);
                $this->formatter->summary($io, $summary, true);

                return ExitCode::FAILURE;
            }
            $io->error($error);
            if ($batches > 0) {
                $io->text(\sprintf('%d URL(s) read before the error were submitted in %d batch(es); re-run the command once the sitemap is reachable.', $found, $batches));
                $this->formatter->summary($io, $summary, false);
            }
            self::skippedNote($io, $skipped, $json);

            return ExitCode::FAILURE;
        }
        if ($batch !== []) {
            $summary->add($submitter->submit($batch));
        }
        if (!$json) {
            $io->text(self::foundLine($found, $sitemap, $since));
        }
        self::skippedNote($io, $skipped, $json);
        if ($since === null && $found > $batchSize) {
            // A full run is the one-off after installation; scheduled runs pass --changed-since or re-announce everything.
            ($json ? $io->getErrorStyle() : $io)->warning(\sprintf('%d URL(s) submitted from the whole sitemap in %d batches without --changed-since: engines see every page as changed and may crawl them all again. Run the full sitemap once, then schedule this command with --changed-since (e.g. "1 day").', $found, (int) ceil($found / $batchSize)));
        }

        return $this->formatter->summary($io, $summary, $json);
    }

    private function sitemapUrl(?string $argument): ?string
    {
        if ($argument !== null && $argument !== '') {
            return $argument;
        }
        if ($this->defaultSitemap !== null && $this->defaultSitemap !== '') {
            return $this->defaultSitemap;
        }
        $baseUrl = $this->indexNow->config->baseUrl;

        return $baseUrl === null ? null : rtrim($baseUrl, '/') . '/sitemap.xml';
    }

    /**
     * "1 day" means one day ago; anything else is handed to DateTimeImmutable as it is.
     *
     * @throws Exception on an unparseable value
     */
    public static function changedSince(?string $option): ?DateTimeImmutable
    {
        if ($option === null || $option === '') {
            return null;
        }

        return new DateTimeImmutable(preg_match('/^\d+\s*\w+$/', $option) === 1 ? '-' . $option : $option);
    }

    /**
     * The entries whose host this site has a key for; every other `<loc>` is counted in $skipped by host and dropped.
     * With `strict_hosts: true` an unmanaged host would be skipped by the submitter anyway (`Reason::NoKey`), but the
     * pre-flight of indexnowkit/verify runs before that decision and would GET the address — so the document never
     * gets to name one. A `<loc>` without a host is left alone: `base_url` resolves it, like every other relative URL.
     *
     * @param iterable<SitemapEntry>   $entries
     * @param list<string>           $managed hosts with a key (`$indexNow->keys->managedHosts()`)
     * @param array<string, int>     $skipped host => how many of its URLs were dropped, filled while streaming
     *
     * @return Generator<int, SitemapEntry>
     */
    private static function onManagedHosts(iterable $entries, array $managed, array &$skipped): Generator
    {
        foreach ($entries as $entry) {
            $host = self::hostOf($entry->url);
            if ($host !== null && !\in_array($host, $managed, true)) {
                $skipped[$host] = ($skipped[$host] ?? 0) + 1;

                continue;
            }
            yield $entry;
        }
    }

    /** The host of a `<loc>` as `managedHosts()` spells it (lower-case, punycode), null when the URL names none. */
    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return null;
        }

        try {
            return strtolower(Punycode::encodeHost($host));
        } catch (InvalidUrlException) {
            return strtolower($host); // never in managedHosts(): dropped, and the count says so
        }
    }

    /**
     * One line naming how many URLs were dropped and on which hosts. With `--json` it goes to stderr: stdout stays
     * the machine-readable document.
     *
     * @param array<string, int> $skipped
     */
    private static function skippedNote(SymfonyStyle $io, array $skipped, bool $json): void
    {
        if ($skipped === []) {
            return;
        }
        $hosts = array_keys($skipped);
        sort($hosts);
        $shown = \array_slice($hosts, 0, 5);

        ($json ? $io->getErrorStyle() : $io)->warning(\sprintf(
            '%d URL(s) skipped on %d host(s) this site does not manage (%s%s): a sitemap can name any host, and the pre-flight would fetch it. Add the host to the hosts map if it is yours, and keep strict_hosts: true when the sitemap is built from user content.',
            array_sum($skipped),
            \count($hosts),
            implode(', ', $shown),
            \count($hosts) > \count($shown) ? ', …' : '',
        ));
    }

    private static function foundLine(int $found, string $sitemap, ?DateTimeImmutable $since): string
    {
        return \sprintf('%d URL(s) found in %s%s', $found, $sitemap, $since !== null ? ' changed since ' . $since->format(DATE_ATOM) : '');
    }

    /**
     * @param iterable<SitemapEntry> $entries
     */
    private static function listText(SymfonyStyle $io, iterable $entries): int
    {
        $found = 0;
        foreach ($entries as $entry) {
            ++$found;
            $io->writeln(' * ' . $entry->url);
        }

        return $found;
    }

    /**
     * Streams a JSON array of URLs, one element per line, without holding the list.
     *
     * @param iterable<SitemapEntry> $entries
     */
    private static function listJson(SymfonyStyle $io, iterable $entries): int
    {
        $found = 0;
        $io->write('[');
        foreach ($entries as $entry) {
            $io->write(($found === 0 ? "\n    " : ",\n    ") . json_encode($entry->url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            ++$found;
        }
        $io->writeln($found === 0 ? ']' : "\n]");

        return $found;
    }
}
