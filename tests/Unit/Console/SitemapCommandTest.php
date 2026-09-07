<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Unit\Console;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\Console\SitemapCommand;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\Tests\Support\Factory;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The `indexnow:sitemap` command over the runner: every argument and option of `Definitions::sitemap()` reaches the
 * runner in its type; the runner answers for a disabled block.
 */
final class SitemapCommandTest extends TestCase
{
    private const URLSET = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function command(array $overrides = [], ?string $default = null, bool $enabled = true): CommandTester
    {
        $kit = Factory::kit($this->transport, $overrides);
        $reader = SitemapReader::fromConfig(SitemapConfig::fromArray(['spool' => 'memory', 'fetch_retries' => 0]), $this->transport);

        return new CommandTester(new SitemapCommand(new SitemapRunner($kit, $reader, Factory::submitters($this->transport, $kit), $default, sitemapUrlOption: 'indexnowkit.sitemap.url', enabled: $enabled), 'indexnowkit.sitemap.url'));
    }

    /**
     * @return list<string>
     */
    private function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            $urls = [...$urls, ...$post['body']['urlList']];
        }

        return $urls;
    }

    #[TestDox('indexnow:sitemap [sitemap] with the options of Definitions::sitemap(); the help names the adapter\'s sitemap.url option')]
    public function testDefinition(): void
    {
        $kit = Factory::kit($this->transport);
        $reader = SitemapReader::fromConfig(SitemapConfig::fromArray([]), $this->transport);
        $command = SitemapServices::command(new SitemapRunner($kit, $reader, Factory::submitters($this->transport, $kit)), 'indexnowkit.sitemap.url');

        self::assertSame('indexnow:sitemap', $command->getName());
        self::assertSame('Submit every URL of a sitemap (or only those with lastmod after --changed-since)', $command->getDescription());
        self::assertSame(['sitemap'], array_keys($command->getDefinition()->getArguments()));
        self::assertFalse($command->getDefinition()->getArgument('sitemap')->isRequired());
        self::assertStringContainsString('indexnowkit.sitemap.url', $command->getDefinition()->getArgument('sitemap')->getDescription());
        self::assertSame(['changed-since', 'allow-foreign-hosts', 'force', 'dry-run', 'json', 'no-verify', 'new-only'], array_keys($command->getDefinition()->getOptions()));
        self::assertSame('f', $command->getDefinition()->getOption('force')->getShortcut());
    }

    #[TestDox('--new-only reaches the runner: without a store of seen URLs it is INVALID with the sentence naming the store')]
    public function testNewOnly(): void
    {
        $tester = $this->command();

        self::assertSame(ExitCode::INVALID, $tester->execute(['sitemap' => 'https://www.example.com/sitemap.xml', '--new-only' => true]));
        self::assertStringContainsString('--new-only needs a store of seen URLs', $tester->getDisplay());
        self::assertSame([], $this->transport->gets);
    }

    #[TestDox('the sitemap argument, --changed-since, --dry-run and --json reach the runner; no argument reads <base_url>/sitemap.xml or the default of the config')]
    public function testArgumentAndOptions(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sitemap');
        self::assertIsString($file);
        file_put_contents($file, self::URLSET . '<url><loc>https://www.example.com/s1</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/s2</loc><lastmod>2020-01-01</lastmod></url></urlset>');
        $tester = $this->command();
        try {
            self::assertSame(ExitCode::SUCCESS, $tester->execute(['sitemap' => $file, '--dry-run' => true]));
            self::assertStringContainsString(' * https://www.example.com/s1', $tester->getDisplay());
            self::assertStringContainsString('2 URL(s) found', $tester->getDisplay());
            self::assertSame([], $this->transport->posts, '--dry-run sends nothing');

            self::assertSame(ExitCode::SUCCESS, $tester->execute(['sitemap' => $file]));
            self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s2'], $this->sentUrls());

            $this->transport->posts = [];
            self::assertSame(ExitCode::SUCCESS, $tester->execute(['sitemap' => $file, '--changed-since' => '2021-01-01', '--json' => true]));
            self::assertStringContainsString('"url_count": 1', $tester->getDisplay());
            self::assertSame(['https://www.example.com/s1'], $this->sentUrls());

            self::assertSame(ExitCode::INVALID, $tester->execute(['sitemap' => $file, '--changed-since' => 'not a date']));
            self::assertStringContainsString('--changed-since', $tester->getDisplay());
        } finally {
            @unlink($file);
        }

        $this->transport->posts = [];
        $this->transport->onGet('https://www.example.com/sitemap.xml', new Response(200, self::URLSET . '<url><loc>https://www.example.com/d1</loc></url></urlset>'));
        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertSame(['https://www.example.com/d1'], $this->sentUrls());

        $this->transport->posts = [];
        $this->transport->onGet('https://www.example.com/news.xml', new Response(200, self::URLSET . '<url><loc>https://www.example.com/n1</loc></url></urlset>'));
        self::assertSame(ExitCode::SUCCESS, $this->command([], 'https://www.example.com/news.xml')->execute([]));
        self::assertSame(['https://www.example.com/n1'], $this->sentUrls(), 'the default of the config');

        $none = $this->command(['base_url' => null]);
        self::assertSame(ExitCode::INVALID, $none->execute([]));
        self::assertStringContainsString('indexnowkit.sitemap.url', $none->getDisplay(), 'the adapter\'s name of the option');
    }

    #[TestDox('--force (-f) bypasses the debounce window; --allow-foreign-hosts and --no-verify reach the runner as flags')]
    public function testForceAndFlags(): void
    {
        $this->transport->onGet('https://www.example.com/sitemap.xml', new Response(200, self::URLSET . '<url><loc>https://www.example.com/f1</loc></url></urlset>'));
        $tester = $this->command(['debounce' => ['per_url' => 600]]);

        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--json' => true]));
        self::assertCount(1, $this->transport->posts, 'the second run is debounced');
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['-f' => true]));
        self::assertCount(2, $this->transport->posts, '-f is --force');

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--force' => true, '--allow-foreign-hosts' => true, '--no-verify' => true]));
        self::assertCount(3, $this->transport->posts);
    }

    #[TestDox('sitemap.enabled: false -> the runner answers "sitemap.enabled is false." and INVALID without reading anything')]
    public function testDisabled(): void
    {
        $tester = $this->command(enabled: false);

        self::assertSame(ExitCode::INVALID, $tester->execute(['sitemap' => 'https://www.example.com/sitemap.xml']));
        self::assertStringContainsString('sitemap.enabled is false.', $tester->getDisplay());
        self::assertSame([], $this->transport->gets, 'nothing fetched');
        self::assertSame([], $this->transport->posts);
    }
}
