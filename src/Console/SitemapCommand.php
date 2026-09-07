<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:sitemap [sitemap] [--changed-since=] [--allow-foreign-hosts] [-f|--force] [--dry-run] [--json] [--no-verify]`:
 * streams a sitemap (or sitemap index) and submits it in batches of `batch.max_urls`. The command every adapter on
 * symfony/console registers (wave L, spec 18): the adapter builds the {@see SitemapRunner} — the source under
 * `SitemapSourceInterface` (the shipped reader, or the application's decorator), the submitter factories, the
 * `sitemap.url` default, `sitemap.enabled` — and hands it over; without the package the adapter registers
 * `Console\Command\SitemapNotInstalledCommand` of `indexnowkit/console` under the same name.
 */
#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (or only those with lastmod after --changed-since)')]
final class SitemapCommand extends Command
{
    /**
     * @param string $sitemapUrlOption the adapter's name of `sitemap.url` in the help text (`indexnowkit.sitemap.url`, `sitemap.url`)
     */
    public function __construct(private readonly SitemapRunner $runner, private readonly string $sitemapUrlOption = 'sitemap.url')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::sitemap($this->sitemapUrlOption)->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sitemap = $input->getArgument('sitemap');
        $since = $input->getOption('changed-since');

        return $this->runner->run(new SymfonyStyle($input, $output), new SitemapOptions(
            sitemap: \is_string($sitemap) ? $sitemap : null,
            changedSince: \is_string($since) ? $since : null,
            allowForeignHosts: (bool) $input->getOption('allow-foreign-hosts'),
            force: (bool) $input->getOption('force'),
            dryRun: (bool) $input->getOption('dry-run'),
            json: (bool) $input->getOption('json'),
            noVerify: (bool) $input->getOption('no-verify'),
        ));
    }
}
