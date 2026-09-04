<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Support;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Config;
use IndexNowKit\Console\SubmitterFactory;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\UrlNormalizer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Factory
{
    public const KEY = 'abcdef1234567890abcdef1234567890';

    /**
     * @param array<string, mixed> $overrides
     */
    public static function config(array $overrides = []): Config
    {
        return Config::fromArray($overrides + ['key' => self::KEY, 'base_url' => 'https://www.example.com', 'debounce' => ['per_url' => 0]]);
    }

    /**
     * A facade over a fake transport, the way the runner tests need it.
     *
     * @param array<string, mixed> $overrides
     */
    public static function kit(FakeTransport $transport, array $overrides = []): IndexNowKit
    {
        return IndexNowKit::create(self::config($overrides), $transport, resolver: new AttributeUrlResolver(new AttributeReader()));
    }

    public static function submitters(FakeTransport $transport, IndexNowKit $kit): SubmitterFactory
    {
        return new SubmitterFactory($transport, $kit->keys, $kit->config, new MemoryDebounceStore(), new NullThrottle(), new UrlNormalizer($kit->config->baseUrl, $kit->config->maxUrlLength));
    }

    public static function io(BufferedOutput $output): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output);
    }
}
