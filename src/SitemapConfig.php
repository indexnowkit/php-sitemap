<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * The `sitemap` block of an adapter's configuration, validated once. Adapters build it with {@see fromArray()} from
 * the raw block, hand it to {@see SitemapReader::fromConfig()}, {@see Check\SitemapSpoolCheck} and their `sitemap`
 * command, and add {@see OPTIONS} to the keys they accept.
 */
final readonly class SitemapConfig
{
    /**
     * Every key of the block, dotted-path form, for `Config::unknownOptions()`. Only dotted keys: a bare `sitemap`
     * entry in an allowed list would stop the nested keys from being checked.
     */
    public const OPTIONS = [
        'sitemap.enabled', 'sitemap.url', 'sitemap.max_depth', 'sitemap.max_sitemaps', 'sitemap.max_bytes',
        'sitemap.allow_foreign_hosts', 'sitemap.spool', 'sitemap.spool_dir', 'sitemap.fetch_retries',
    ];

    public const DEFAULT_MAX_DEPTH = 3;
    public const DEFAULT_FETCH_RETRIES = 2;
    /** Smallest `max_bytes` accepted: below it no real sitemap fits. */
    public const MIN_MAX_BYTES = 1024;

    /**
     * @param bool        $enabled           false = the adapter registers no sitemap command and no reader
     * @param string|null $url               sitemap read when the command gets no argument: an absolute http(s) URL,
     *                                       a local path or a `file://` URL; null = `<base_url>/sitemap.xml`
     * @param int         $maxDepth          levels of <sitemapindex> followed below the root (0 = the root only)
     * @param int         $maxSitemaps       documents fetched per run, root included
     * @param int         $maxBytes          size cap of one uncompressed document
     * @param bool        $allowForeignHosts follow nested sitemaps on other origins (CDN-hosted parts)
     * @param SpoolMode   $spool             where a document is kept while parsing
     * @param string|null $spoolDir          directory of the temp files (null = `sys_get_temp_dir()`)
     * @param int         $fetchRetries      extra attempts after a network failure or 5xx while fetching a document
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public bool $enabled = true,
        public ?string $url = null,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxSitemaps = SitemapReader::MAX_SITEMAPS,
        public int $maxBytes = SitemapReader::MAX_XML_BYTES,
        public bool $allowForeignHosts = false,
        public SpoolMode $spool = SpoolMode::Auto,
        public ?string $spoolDir = null,
        public int $fetchRetries = self::DEFAULT_FETCH_RETRIES,
    ) {
        if ($url !== null && !self::isSitemapLocation($url)) {
            throw new ConfigurationException(\sprintf('"sitemap.url" must be an absolute http(s) URL, a local path or a file:// URL, got "%s".', $url));
        }
        if ($maxDepth < 0) {
            throw new ConfigurationException(\sprintf('"sitemap.max_depth" must be >= 0, got %d.', $maxDepth));
        }
        if ($maxSitemaps < 1) {
            throw new ConfigurationException(\sprintf('"sitemap.max_sitemaps" must be >= 1, got %d.', $maxSitemaps));
        }
        if ($maxBytes < self::MIN_MAX_BYTES) {
            throw new ConfigurationException(\sprintf('"sitemap.max_bytes" must be >= %d, got %d.', self::MIN_MAX_BYTES, $maxBytes));
        }
        if ($spoolDir === '') {
            throw new ConfigurationException('"sitemap.spool_dir" must be a directory path or null.');
        }
        if ($fetchRetries < 0) {
            throw new ConfigurationException(\sprintf('"sitemap.fetch_retries" must be >= 0, got %d.', $fetchRetries));
        }
    }

    /**
     * From the raw `sitemap` block of a framework configuration. Numbers and booleans are coerced the way
     * `Config::fromArray()` does it (`"3"`, `"true"`, `"0"`); anything else is a ConfigurationException naming the key.
     *
     * @param array<string, mixed> $block
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $block): self
    {
        $spool = $block['spool'] ?? null;
        if ($spool !== null && $spool !== '') {
            $mode = \is_string($spool) ? SpoolMode::tryFrom(strtolower($spool)) : null;
            if ($mode === null) {
                throw new ConfigurationException(\sprintf('"sitemap.spool" must be one of %s, got "%s".', implode(', ', array_map(static fn(SpoolMode $m): string => $m->value, SpoolMode::cases())), \is_scalar($spool) ? (string) $spool : get_debug_type($spool)));
            }
        } else {
            $mode = SpoolMode::Auto;
        }

        return new self(
            enabled: self::bool($block['enabled'] ?? null, true, 'sitemap.enabled'),
            url: self::str($block['url'] ?? null),
            maxDepth: self::int($block['max_depth'] ?? null, self::DEFAULT_MAX_DEPTH, 'sitemap.max_depth'),
            maxSitemaps: self::int($block['max_sitemaps'] ?? null, SitemapReader::MAX_SITEMAPS, 'sitemap.max_sitemaps'),
            maxBytes: self::int($block['max_bytes'] ?? null, SitemapReader::MAX_XML_BYTES, 'sitemap.max_bytes'),
            allowForeignHosts: self::bool($block['allow_foreign_hosts'] ?? null, false, 'sitemap.allow_foreign_hosts'),
            spool: $mode,
            spoolDir: self::str($block['spool_dir'] ?? null),
            fetchRetries: self::int($block['fetch_retries'] ?? null, self::DEFAULT_FETCH_RETRIES, 'sitemap.fetch_retries'),
        );
    }

    /**
     * The block of an adapter whose sitemap support is switched off.
     */
    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    /**
     * The runtime path of an adapter: {@see fromArray()}, and when the block is invalid one `critical` line naming the
     * error and the check command, then {@see disabled()} — the sitemap command refuses to run until the block is
     * fixed, nothing throws from the container. The same rule the core's `Adapter\ConfigFactory::load()` applies to
     * the core options.
     *
     * @param array<string, mixed> $block        the raw `sitemap` block
     * @param string               $checkCommand the adapter's check command, `php artisan indexnow:check`
     */
    public static function loadOrDisabled(array $block, LoggerInterface $logger, string $checkCommand): self
    {
        try {
            return self::fromArray($block);
        } catch (ConfigurationException $e) {
            $logger->critical('indexnow: invalid sitemap configuration, the sitemap command is disabled until it is fixed: {error} (run "{check}")', ['error' => $e->getMessage(), 'check' => $checkCommand, 'exception' => $e]);

            return self::disabled();
        }
    }

    private static function isSitemapLocation(string $url): bool
    {
        if (str_starts_with($url, 'file://') || str_starts_with($url, '/') || str_starts_with($url, '.')) {
            return true;
        }
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['scheme'], $parts['host']) && \in_array(strtolower($parts['scheme']), ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @throws ConfigurationException
     */
    private static function int(mixed $value, int $default, string $option): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value) || (string) (int) $value !== ltrim((string) $value, '+')) {
            throw new ConfigurationException(\sprintf('"%s" must be an integer, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * @throws ConfigurationException
     */
    private static function bool(mixed $value, bool $default, string $option): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        $parsed = \is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
        if ($parsed === null) {
            throw new ConfigurationException(\sprintf('"%s" must be a boolean, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return $parsed;
    }
}
