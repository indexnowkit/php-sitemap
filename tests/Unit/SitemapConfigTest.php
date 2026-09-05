<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SpoolMode;
use IndexNowKit\Testing\ArrayLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SitemapConfigTest extends TestCase
{
    #[TestDox('defaults match the reader; fromArray coerces numbers and booleans the way Config::fromArray() does')]
    public function testDefaultsAndCoercion(): void
    {
        $defaults = new SitemapConfig();
        self::assertTrue($defaults->enabled);
        self::assertNull($defaults->url);
        self::assertSame(3, $defaults->maxDepth);
        self::assertSame(SitemapReader::MAX_SITEMAPS, $defaults->maxSitemaps);
        self::assertSame(SitemapReader::MAX_XML_BYTES, $defaults->maxBytes);
        self::assertFalse($defaults->allowForeignHosts);
        self::assertSame(SpoolMode::Auto, $defaults->spool);
        self::assertNull($defaults->spoolDir);
        self::assertSame(2, $defaults->fetchRetries);
        self::assertEquals($defaults, SitemapConfig::fromArray([]));
        self::assertEquals($defaults, SitemapConfig::fromArray(['url' => '', 'spool' => '', 'spool_dir' => '', 'max_depth' => '']), 'empty strings (unset env vars) are defaults');

        $config = SitemapConfig::fromArray([
            'enabled' => '0', 'url' => 'https://www.example.com/sitemaps/root.xml', 'max_depth' => '1', 'max_sitemaps' => 5, 'max_bytes' => '2048',
            'allow_foreign_hosts' => 'true', 'spool' => 'MEMORY', 'spool_dir' => '/var/tmp', 'fetch_retries' => 0,
        ]);
        self::assertFalse($config->enabled);
        self::assertSame('https://www.example.com/sitemaps/root.xml', $config->url);
        self::assertSame(1, $config->maxDepth);
        self::assertSame(5, $config->maxSitemaps);
        self::assertSame(2048, $config->maxBytes);
        self::assertTrue($config->allowForeignHosts);
        self::assertSame(SpoolMode::Memory, $config->spool);
        self::assertSame('/var/tmp', $config->spoolDir);
        self::assertSame(0, $config->fetchRetries);

        self::assertFalse(SitemapConfig::disabled()->enabled);
        self::assertSame('/var/www/sitemap.xml', SitemapConfig::fromArray(['url' => '/var/www/sitemap.xml'])->url, 'a local path is a valid default');
        self::assertSame('file:///srv/sitemap.xml', SitemapConfig::fromArray(['url' => 'file:///srv/sitemap.xml'])->url);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'relative url' => [['url' => 'sitemap.xml'], '"sitemap.url"'];
        yield 'ftp url' => [['url' => 'ftp://www.example.com/sitemap.xml'], '"sitemap.url"'];
        yield 'negative depth' => [['max_depth' => -1], '"sitemap.max_depth" must be >= 0'];
        yield 'non-integer depth' => [['max_depth' => 'three'], '"sitemap.max_depth" must be an integer'];
        yield 'zero sitemaps' => [['max_sitemaps' => 0], '"sitemap.max_sitemaps" must be >= 1'];
        yield 'tiny max_bytes' => [['max_bytes' => 100], '"sitemap.max_bytes" must be >= 1024'];
        yield 'bad bool' => [['allow_foreign_hosts' => 'maybe'], '"sitemap.allow_foreign_hosts" must be a boolean'];
        yield 'bad spool' => [['spool' => 'tape'], '"sitemap.spool" must be one of auto, disk, memory'];
        yield 'negative retries' => [['fetch_retries' => -2], '"sitemap.fetch_retries" must be >= 0'];
    }

    #[DataProvider('invalid')]
    #[TestDox('an invalid value is a ConfigurationException naming the dotted key: $_dataName')]
    public function testInvalid(array $block, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        SitemapConfig::fromArray($block);
    }

    #[TestDox('loadOrDisabled() is fromArray(); an invalid block is one critical line naming the error and the check command, and a disabled config')]
    public function testLoadOrDisabled(): void
    {
        $logger = new ArrayLogger();

        $valid = SitemapConfig::loadOrDisabled(['spool' => 'memory', 'max_depth' => '2'], $logger, 'php artisan indexnow:check');
        self::assertTrue($valid->enabled);
        self::assertSame(SpoolMode::Memory, $valid->spool);
        self::assertSame(2, $valid->maxDepth);
        self::assertSame([], $logger->records);

        $disabled = SitemapConfig::loadOrDisabled(['spool' => 'tape'], $logger, 'php yii indexnow/check');
        self::assertFalse($disabled->enabled);
        self::assertSame(['indexnow: invalid sitemap configuration, the sitemap command is disabled until it is fixed: "sitemap.spool" must be one of auto, disk, memory, got "tape". (run "php yii indexnow/check")'], $logger->messages('critical'));
        self::assertInstanceOf(ConfigurationException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    #[TestDox('OPTIONS are dotted keys only, and together with Config::OPTIONS they let unknownOptions() see a typo inside the block')]
    public function testOptionsAreDotted(): void
    {
        foreach (SitemapConfig::OPTIONS as $option) {
            self::assertStringStartsWith('sitemap.', $option);
        }
        self::assertNotContains('sitemap', SitemapConfig::OPTIONS);
        $allowed = [...Config::OPTIONS, ...SitemapConfig::OPTIONS];
        self::assertSame([], Config::unknownOptions(['sitemap' => ['spool' => 'memory', 'url' => null]], $allowed));
        self::assertSame(['sitemap.spol'], Config::unknownOptions(['sitemap' => ['spol' => 'memory']], $allowed));
    }
}
