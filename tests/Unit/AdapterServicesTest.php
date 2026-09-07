<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Unit;

use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\Tests\Support\ArraySeenStore;
use IndexNowKit\Sitemap\Tests\Support\Factory;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** `Sitemap\Adapter\SitemapServices`: what every framework adapter wires, in one place. */
final class AdapterServicesTest extends TestCase
{
    #[TestDox('package(), options(), config(), the reader, the spool check and the runner')]
    public function testPieces(): void
    {
        self::assertSame('indexnowkit/sitemap', SitemapServices::package()->package);
        self::assertSame(SitemapReader::class, SitemapServices::package()->marker);
        self::assertFalse(SitemapServices::package(false)->installed());
        self::assertSame(SitemapConfig::OPTIONS, SitemapServices::options());

        $logger = new ArrayLogger();
        $sitemap = SitemapServices::config(['url' => 'https://www.example.com/sitemap.xml'], $logger, 'php x check');
        self::assertSame('https://www.example.com/sitemap.xml', $sitemap->url);
        self::assertFalse(SitemapServices::config(['url' => 'not a url'], $logger, 'php x check')->enabled, 'a broken block disables the command');
        self::assertStringContainsString('php x check', implode("\n", $logger->messages('critical')));

        $transport = new FakeTransport();
        $services = (new ServicesBuilder(Factory::config(), $logger))->transport($transport)->build();
        self::assertInstanceOf(SitemapReader::class, SitemapServices::readerFor($sitemap, $services));
        self::assertInstanceOf(SitemapReader::class, SitemapServices::reader($sitemap, $transport, $logger));
        self::assertInstanceOf(SitemapSpoolCheck::class, SitemapServices::spoolCheck($sitemap));
        self::assertInstanceOf(SitemapRunner::class, SitemapServices::runner($services->kit(), SitemapServices::readerFor($sitemap, $services), $services->submitterFactory(), $sitemap, new ResultRenderer(), 'sitemap.url', null));
        self::assertInstanceOf(SitemapRunner::class, SitemapServices::runner($services->kit(), SitemapServices::readerFor($sitemap, $services), $services->submitterFactory(), $sitemap, new ResultRenderer(), 'sitemap.url', null, seen: new ArraySeenStore()), 'the store of seen URLs is the appended, named argument');
    }
}
