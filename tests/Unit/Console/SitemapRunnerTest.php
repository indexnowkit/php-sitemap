<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Unit\Console;

use DateTimeImmutable;
use IndexNowKit\Config;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapEntry;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Sitemap\Tests\Support\Factory;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SitemapRunnerTest extends TestCase
{
    private const URLSET = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

    private FakeTransport $transport;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->output = new BufferedOutput();
    }

    private function io(): SymfonyStyle
    {
        return Factory::io($this->output);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function kit(array $overrides = []): IndexNowKit
    {
        return Factory::kit($this->transport, $overrides);
    }

    private function runner(IndexNowKit $kit, ?string $default = null, string $option = 'sitemap.url'): SitemapRunner
    {
        $reader = SitemapReader::fromConfig(SitemapConfig::fromArray(['spool' => 'memory', 'fetch_retries' => 0]), $this->transport);

        return new SitemapRunner($kit, $reader, Factory::submitters($this->transport, $kit), $default, sitemapUrlOption: $option);
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

    /**
     * @return list<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    private function sitemapFile(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sitemap');
        self::assertIsString($file);
        file_put_contents($file, $body);

        return $file;
    }

    #[TestDox('streams a local sitemap in batches of batch.max_urls and prints a summary; --dry-run lists as text or JSON; --changed-since filters')]
    public function testSitemap(): void
    {
        $kit = $this->kit(['batch' => ['max_urls' => 1]]);
        $runner = $this->runner($kit);
        $file = $this->sitemapFile(self::URLSET . '<url><loc>https://www.example.com/s1</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/s2</loc><lastmod>2020-01-01</lastmod></url></urlset>');
        try {
            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions($file, dryRun: true)));
            $display = $this->output->fetch();
            self::assertStringContainsString(' * https://www.example.com/s1', $display);
            self::assertStringContainsString('2 URL(s) found', $display);
            self::assertSame([], $this->transport->posts);

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions($file, dryRun: true, json: true)));
            self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s2'], $this->json());

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions($file)));
            $display = $this->output->fetch();
            self::assertStringContainsString('2 URL(s) found', $display);
            self::assertStringContainsString('batches', $display);
            self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s2'], $this->sentUrls());
            self::assertCount(2, $this->transport->posts, 'batch.max_urls: 1 gives one request per URL');

            $this->transport->posts = [];
            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions($file, changedSince: '2021-01-01', json: true)));
            $rows = $this->json();
            self::assertIsArray($rows[0]);
            self::assertSame(1, $rows[0]['url_count']);
            self::assertSame(['https://www.example.com/s1'], $this->sentUrls());
        } finally {
            @unlink($file);
        }
    }

    #[TestDox('no argument uses sitemap.url, else <base_url>/sitemap.xml; without either it is INVALID and names the option; an unparseable --changed-since is INVALID; an unreadable sitemap is a FAILURE')]
    public function testDefaultsAndErrors(): void
    {
        $kit = $this->kit();
        $runner = $this->runner($kit, option: 'indexnow.sitemap.url');
        $this->transport->onGet('https://www.example.com/sitemap.xml', new Response(200, self::URLSET . '<url><loc>https://www.example.com/d1</loc></url></urlset>'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions()));
        self::assertSame(['https://www.example.com/d1'], $this->sentUrls());

        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SitemapOptions(changedSince: 'not a date')));
        self::assertStringContainsString('--changed-since', $this->output->fetch());

        $this->transport->onGet('https://www.example.com/missing.xml', new Response(404));
        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), new SitemapOptions('https://www.example.com/missing.xml')));
        self::assertStringContainsString('Cannot read', $this->output->fetch());

        $configured = $this->runner($kit, 'https://www.example.com/sitemap.xml');
        self::assertSame(ExitCode::SUCCESS, $configured->run($this->io(), new SitemapOptions(dryRun: true)));
        self::assertStringContainsString('https://www.example.com/d1', $this->output->fetch());

        $noBase = IndexNowKit::create(Config::fromArray(['key' => Factory::KEY, 'hosts' => ['www.example.com' => Factory::KEY]]), $this->transport);
        self::assertSame(ExitCode::INVALID, $this->runner($noBase, option: 'indexnow.sitemap.url')->run($this->io(), new SitemapOptions()));
        self::assertStringContainsString('configure indexnow.sitemap.url or base_url', $this->output->fetch());
    }

    #[TestDox('a truncated sitemap submits what was read and exits 1, as text or as JSON with the error on stderr')]
    public function testPartialFailure(): void
    {
        $kit = $this->kit(['batch' => ['max_urls' => 1]]);
        $runner = $this->runner($kit);
        $file = $this->sitemapFile(self::URLSET . '<url><loc>https://www.example.com/p1</loc></url><url><loc>https://www.example.com/p2</loc></url><url><loc>https://www.example.com/p3');
        try {
            self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), new SitemapOptions($file)));
            $display = $this->output->fetch();
            self::assertStringContainsString('Cannot read', $display);
            self::assertStringContainsString('read before the error were submitted', $display);
            self::assertSame(['https://www.example.com/p1', 'https://www.example.com/p2'], $this->sentUrls());

            $this->transport->posts = [];
            self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), new SitemapOptions($file, json: true)));
            // BufferedOutput has no separate stderr: the error block lands before the JSON here, on a real console it goes to stderr.
            $display = $this->output->fetch();
            $start = strpos($display, "[\n");
            self::assertIsInt($start);
            $rows = json_decode(substr($display, $start), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($rows);
            self::assertIsArray($rows[0]);
            self::assertSame(2, $rows[0]['batches'], 'stdout stays one JSON document');
        } finally {
            @unlink($file);
        }
    }

    #[TestDox('a custom source is streamed as given; --allow-foreign-hosts only reaches the shipped reader and is warned about otherwise; an empty source is "nothing submitted"')]
    public function testCustomSource(): void
    {
        $kit = $this->kit();
        $source = new class implements SitemapSourceInterface {
            public bool $empty = false;

            public function read(string $sitemap, ?DateTimeImmutable $changedSince = null): iterable
            {
                if (!$this->empty) {
                    yield new SitemapEntry('https://www.example.com/custom', null);
                }
            }
        };
        $runner = new SitemapRunner($kit, $source, Factory::submitters($this->transport, $kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions(allowForeignHosts: true)));
        self::assertStringContainsString('--allow-foreign-hosts is an option of the shipped SitemapReader', $this->output->fetch());
        self::assertSame(['https://www.example.com/custom'], $this->sentUrls());

        $source->empty = true;
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SitemapOptions()));
        self::assertStringContainsString('Nothing submitted: the source yielded no URL.', $this->output->fetch());
    }
}
