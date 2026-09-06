<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Adapter;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Psr\Log\LoggerInterface;

/**
 * What every framework adapter wires for this package, in one place: the predicate, the owned options, the validated
 * block, the reader, the spool check and the body of the `sitemap` command. The `*For()` methods take the core's
 * runtime graph (`Adapter\Services`: Yii, plain PHP); the others take the pieces one by one, for a container that
 * binds them itself (Laravel, Symfony). An adapter keeps only where the block comes from and how its command reads
 * its options.
 */
final class SitemapServices
{
    /**
     * The one predicate for `indexnowkit/sitemap` (safe to call without the package: `::class` on an absent class is
     * a string); null = detect, false = wire as if the package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/sitemap', SitemapReader::class, 'sitemap', $installed);
    }

    /**
     * The dotted keys of the `sitemap` block, for the adapter's `ConfigFactory` (`SitemapConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return SitemapConfig::OPTIONS;
    }

    /**
     * The validated `sitemap` block; a broken value disables the sitemap command with a critical log line naming
     * $checkCommand, like the core options.
     *
     * @param array<string, mixed> $block
     */
    public static function config(array $block, LoggerInterface $logger, string $checkCommand): SitemapConfig
    {
        return SitemapConfig::loadOrDisabled($block, $logger, $checkCommand);
    }

    /** The reader over the graph's transport (`sitemap.max_*`, `spool`, `fetch_retries`). */
    public static function reader(SitemapConfig $config, TransportInterface $transport, LoggerInterface $logger): SitemapSourceInterface
    {
        return SitemapReader::fromConfig($config, $transport, $logger);
    }

    public static function readerFor(SitemapConfig $config, Services $services): SitemapSourceInterface
    {
        return self::reader($config, $services->transport(), $services->logger);
    }

    /** The `sitemap.spool` line of `check`: whether the spool directory is usable. */
    public static function spoolCheck(SitemapConfig $config): SitemapSpoolCheck
    {
        return new SitemapSpoolCheck($config);
    }

    /**
     * The body of the `sitemap` command.
     *
     * @param string                        $sitemapUrlOption the adapter's name of `sitemap.url` in its error texts (`indexnow.sitemap.url`, `sitemap.url`)
     * @param SubmitterFactoryInterface|null $unverified      the plain command submitter factory for `--no-verify` (null = the same as $submitters)
     */
    public static function runner(IndexNowKit $indexNow, SitemapSourceInterface $source, SubmitterFactoryInterface $submitters, SitemapConfig $config, ResultFormatterInterface $formatter, string $sitemapUrlOption, ?SubmitterFactoryInterface $unverified): SitemapRunner
    {
        return new SitemapRunner($indexNow, $source, $submitters, $config->url, $formatter, $sitemapUrlOption, $unverified);
    }
}
