<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Console;

use IndexNowKit\Console\ArgumentDefinition;
use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\OptionDefinition;

/**
 * The arguments and options of the `sitemap` command, declared once for every adapter (the core's
 * `Console\Definitions` does the same for the core commands). Covers {@see SitemapOptions}.
 */
final class Definitions
{
    private function __construct() {}

    /**
     * @param string $sitemapUrlOption the configuration option of the default sitemap, as printed in the help (`sitemap.url`, `indexnow.sitemap.url`)
     */
    public static function sitemap(string $sitemapUrlOption = 'sitemap.url'): CommandDefinition
    {
        return new CommandDefinition(
            'Submit every URL of a sitemap (or only those with lastmod after --changed-since)',
            [ArgumentDefinition::optional('sitemap', \sprintf('Sitemap URL or local file (default: %s from the config, else <base_url>/sitemap.xml)', $sitemapUrlOption))],
            [
                OptionDefinition::value('changed-since', 'Only URLs whose <lastmod> is newer, e.g. "1 day" or "2026-09-01"'),
                OptionDefinition::flag('allow-foreign-hosts', 'Follow nested sitemaps hosted on another origin (CDN) for this run'),
                OptionDefinition::flag('force', 'Ignore the debounce store', 'f'),
                OptionDefinition::flag('dry-run', 'List URLs without submitting'),
                OptionDefinition::flag('json', 'Machine-readable output'),
            ],
        );
    }
}
