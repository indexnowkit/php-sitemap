# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## [0.3.0] — 2026-09-06

### Changed

- Requires core 0.7: `Console\SubmitterFactory` / `Console\SubmitterFactoryInterface` are now
  `IndexNowKit\Adapter\SubmitterFactory` / `IndexNowKit\Adapter\SubmitterFactoryInterface`, `Console\ResultSummary` is
  `IndexNowKit\Submission\ResultSummary`. Application code that names them (a decorator of the `submitters` argument of `Console\\SitemapRunner`) changes the `use` line; nothing else.
- The test suite requires `indexnowkit/testing ^0.1` (`require-dev`): the conformance kits and the H01–H05 assertions
  moved there from the core (`Testing\Conformance\KeyFileAssertions`, `CheckOutputAssertions`, `ReadmeAssertions`).
- Requires `indexnowkit/console ^0.1` and `symfony/console`: the runners and the formatter `Console\SitemapRunner`
  builds on moved there from the core with their FQCN unchanged, and the runner renders through `SymfonyStyle` like
  every command body of the family; `symfony/console` leaves `suggest` (it is a requirement now).

### Added

- `SitemapConfig::loadOrDisabled(array $block, LoggerInterface $logger, string $checkCommand)`: the runtime path of
  an adapter — `fromArray()`, and for an invalid block one `critical` line `indexnow: invalid sitemap configuration,
  the sitemap command is disabled until it is fixed: {error} (run "{check}")` plus `disabled()`. Replaces the copies
  in Laravel and Yii2 (spec 17 §4.3).

### Documentation

- README: "Notes for AI assistants" (package, minimal complete snippet, verification, pitfalls across the adapters);
  `ReadmeAiNotesTest` keeps it consistent with the commands and configuration keys.
- README: `--force` re-announces URLs inside the debounce window (one-off, not for schedules); `batch.max_urls` is a
  ceiling, not a target.
- `homepage` in composer.json points at the docs site (https://indexnowkit.github.io/php/).

## [0.2.0] — 2026-09-05

### Changed

- Requires `indexnowkit/core ^0.6` (wave 0a of docs/spec/17: the staging check, the debounce fix). No change in this
  package's own API; upgrade together with the core.

### Added

- `psalm.xml` and a weekly Psalm taint analysis workflow in the monorepo: the reader parses untrusted XML, so tainted
  data flow is checked separately from the phpstan pipeline. Psalm is not a dev dependency.

### Documentation

- README: the Google paragraph, "notification, not indexing", and what `--changed-since` relies on (`<lastmod>`; a
  run without the option re-announces the whole sitemap; `lastmod = now()` makes every run a full run).

## [0.1.1] — 2026-09-05

### Added

- `Sitemap\Console\Definitions::sitemap(string $sitemapUrlOption = 'sitemap.url')`: the argument and options of the
  `sitemap` command declared once (`Console\CommandDefinition` of the core), rendered by every adapter; covers
  `SitemapOptions`.

### Changed

- Requires `indexnowkit/core ^0.5` (the `CommandDefinition` model).
- Dev tooling: a coverage floor (`tests/coverage-floor.txt`) checked by the monorepo CI.

## [0.1.0] — 2026-09-05

First release: the sitemap reader and the `sitemap` command body, extracted from `indexnowkit/core` 0.3
(docs/spec/16 §1). Requires `indexnowkit/core ^0.4`, PHP 8.2–8.5, `ext-xmlreader`.

### Added

- `Sitemap\SitemapReader`, `Spool`, `SpoolMode`, `SitemapEntry` and `SitemapSourceInterface` under the FQCN they had
  in the core; `Sitemap\Console\SitemapRunner` and `SitemapOptions` (were `Console\SitemapRunner`/`SitemapOptions`);
  `Sitemap\Check\SitemapSpoolCheck` (was `Check\SitemapSpoolCheck`).
- **`SitemapConfig`**: the validated `sitemap` block of an adapter (`fromArray()` with the coercion rules of
  `Config::fromArray()`, `disabled()`, dotted `OPTIONS` for `Config::unknownOptions()`), and
  `SitemapReader::fromConfig(SitemapConfig, TransportInterface, LoggerInterface)`. `SitemapSpoolCheck` takes it
  instead of the raw array; `SitemapRunner` takes the name of the option to print (`sitemapUrlOption:`) instead
  of `Vocabulary::$sitemapUrlOption`.

### Migration from core 0.3

| core 0.3 | sitemap 0.1 |
|---|---|
| `$kit->sitemap()` | `SitemapReader::fromConfig(SitemapConfig::fromArray($block), $kit->transport ?? TransportFactory::lazy($kit->config), $logger)` |
| `IndexNowKit::create(sitemap: $source)` | pass your `SitemapSourceInterface` to the runner / your command directly |
| `new SitemapReader($transport, $maxDepth, $logger, ...)` | unchanged, or `fromConfig()` |
| `Console\SitemapRunner(..., words: new Vocabulary(sitemapUrlOption: 'x'))` | `Sitemap\Console\SitemapRunner(..., sitemapUrlOption: 'x')` |
| `new Check\SitemapSpoolCheck($rawBlock)` | `new Sitemap\Check\SitemapSpoolCheck(SitemapConfig::fromArray($rawBlock))` |

[0.1.0]: https://github.com/indexnowkit/php-sitemap/releases/tag/0.1.0
