# Backward compatibility

`indexnowkit/sitemap` follows SemVer and the tiers of the core's [docs/bc.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/bc.md).
**Before 1.0, minor versions may contain breaking changes**, listed under "Changed" in [CHANGELOG.md](../CHANGELOG.md).

| Tier | Members |
|---|---|
| **Call** — signatures only grow by appended, defaulted parameters; pass anything past the first argument by name | `SitemapReader` (constructor, `fromConfig()`, `read()`, `parse()`), `SitemapConfig` (constructor, `fromArray()`, `disabled()`), `Console\SitemapRunner`, `Console\SitemapOptions`, `Check\SitemapSpoolCheck`, `Spool::create()`, `probeDisk()`, `uri()`, `close()` |
| **Implement** — methods are not added without a major version | `SitemapSourceInterface` |
| **Value objects** — `final readonly`, properties only appended with defaults | `SitemapEntry`, `SitemapConfig` |
| **Constants** — referenced, not hard-coded; values may change in a minor | `SitemapReader::MAX_XML_BYTES`, `MAX_SITEMAPS`, `SitemapConfig::OPTIONS`, `DEFAULT_MAX_DEPTH`, `DEFAULT_FETCH_RETRIES`, `MIN_MAX_BYTES`, `Spool::SCHEME` |
| **Enum** — closed set | `SpoolMode` |

Not covered: `Spool`'s `stream_*` methods (the PHP stream-wrapper protocol, `@internal`), log and exception message
texts, anything under `tests/`.

Adapters pin `indexnowkit/sitemap ^0.1` and `indexnowkit/core ^0.4` together: a `SitemapReader` of this package
over a core 0.3 transport is refused by Composer, on purpose (the FQCN would clash with the classes core 0.3 still ships).
