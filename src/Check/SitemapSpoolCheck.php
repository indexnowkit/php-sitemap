<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\Spool;
use IndexNowKit\Sitemap\SpoolMode;

/**
 * Where the sitemap command keeps documents while parsing: a read-only container without a writable temp dir is the
 * kind of thing that otherwise only shows up on the first scheduled run. One line in the adapter's `check` command;
 * a disabled sitemap block prints nothing.
 */
final class SitemapSpoolCheck implements CheckInterface
{
    /** The code of every line this check prints ({@see \IndexNowKit\Check\CheckItem::$code}). */
    public const CODE = 'sitemap.spool';

    public function __construct(private readonly SitemapConfig $config) {}

    public function check(CheckReport $report): void
    {
        $config = $this->config;
        if (!$config->enabled) {
            return;
        }
        if ($config->spool === SpoolMode::Memory) {
            $report->ok(\sprintf('sitemap: documents are spooled in memory (sitemap.spool: memory, at most %s per document)', self::bytes($config->maxBytes)), self::CODE);

            return;
        }
        $problem = Spool::probeDisk($config->spoolDir);
        if ($problem === null) {
            $report->ok(\sprintf('sitemap: documents are spooled to temp files in %s', $config->spoolDir ?? sys_get_temp_dir()), self::CODE);
        } elseif ($config->spool === SpoolMode::Disk) {
            $report->error(\sprintf('sitemap: %s and sitemap.spool is "disk": the sitemap command will fail. Mount a writable volume, set sitemap.spool_dir, or use "auto" / "memory".', $problem), self::CODE);
        } else {
            $report->warning(\sprintf('sitemap: %s: the sitemap command will spool documents in memory (at most %s each). Mount a writable temp dir or set sitemap.spool_dir.', $problem, self::bytes($config->maxBytes)), self::CODE);
        }
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? \sprintf('%d MiB', intdiv($bytes, 1_048_576)) : \sprintf('%d KiB', intdiv($bytes, 1024));
    }
}
