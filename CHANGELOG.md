# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## [0.8.0] — Unreleased

### Added

- **`Console\SitemapCommand`** (wave L, spec 18): the `indexnow:sitemap` command itself as a symfony/console class over
  `Console\SitemapRunner`, with `#[AsCommand]`; `Sitemap\Adapter\SitemapServices::command($runner, $sitemapUrlOption)`
  builds it. The Symfony bundle and the Yii3 package register this class instead of a copy of their own; an adapter
  without the package registers `Console\Command\SitemapNotInstalledCommand` of `indexnowkit/console` under the same
  name. Constructor: `(SitemapRunner $runner, string $sitemapUrlOption = 'sitemap.url')` — the second names the
  adapter's `sitemap.url` option in the help.
- **`Console\SitemapRunner` takes `bool $enabled = true`** (appended) and answers `sitemap.enabled is false.` with exit 2
  when it is off; `SitemapServices::runner()` passes `SitemapConfig::$enabled`. Before, the Yii3 command checked the flag
  itself and the bundle registered no command at all (it still does); the one place is now the runner, whatever the
  adapter chose.

### Changed

- Requires `indexnowkit/console ^0.5` (the command classes and the stubs live there).

- **A `<loc>` on a host this site has no key for is dropped** (`Console\SitemapRunner`), before anything fetches or
  submits it: only the hosts `KeyProviderInterface::managedHosts()` names — `base_url` plus the `hosts` map — pass,
  whatever source produced the entries. One line in the run summary (on stderr with `--json`) says how many URLs were
  skipped and on which hosts. Nested sitemaps were already restricted to the origin of the root; the entries were not,
  so a sitemap built from user content (or a swapped one) could make the pre-flight of `indexnowkit/verify` GET
  internal addresses. With no key at all (neither `base_url` nor a `hosts` map) nothing can be compared: the entries go
  as they are, with one warning. `strict_hosts: true` is documented as the recommendation for a sitemap built from user
  content.
- `Sitemap\Adapter\SitemapServices::package()` delegates to the core's `Adapter\OptionalPackage::sitemap()` (core
  0.13.0): the name, the marker and the feature word live there, so an adapter asks about the package without loading
  this class. Same object, same texts; adapters should call `OptionalPackage::sitemap()` directly.
- Requires `indexnowkit/core ^0.13`.

## [0.7.0] — 2026-09-07

### Added

- **`Sitemap\Adapter\SitemapServices`** — what every framework adapter wires for this package, in one place: `package()`,
  `options()`, `config()`, `reader()` / `readerFor()`, `spoolCheck()`, `runner()`. The three adapters build on it.

## [0.6.1] — 2026-09-07

### Changed

- Requires `indexnowkit/core ^0.12`.

## [0.6.0] — 2026-09-07

### Changed

- The libxml error buffer is cleared before a sitemap is parsed: an error another library left in the process
  (`DOMDocument::loadHTML`, a SOAP client) was read as this sitemap's and a correct file was refused.
- `<lastmod>` without a time zone (`2026-09-06`) is UTC, not the process time zone; an unparsable `<lastmod>` is a debug
  line naming the URL instead of silence.
- A nested sitemap on the same host with an explicit default port (`https://x.com:443`) is the same origin.
- A local sitemap index may only reference local parts inside its own directory (`<loc>/etc/passwd</loc>` was read and
  echoed line by line into the log as a "text sitemap").
- Requires `indexnowkit/core ^0.11`.

## [0.5.1] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.10` (`Attribute\ParamExtractor` became an injected object; nothing else in the core changed).

## [0.5.0] — 2026-09-06

### Added

- **`--no-verify`** in `Console\Definitions::sitemap()` and `SitemapOptions::$noVerify`: `SitemapRunner` takes an appended
  `?SubmitterFactoryInterface $unverifiedSubmitters` and submits through it (a fresh submitter, `--force` or not) when the
  flag is given — past the pre-flight of `indexnowkit/verify` the adapters decorate the regular factory with. Without a
  second factory (no verify package) the flag is accepted and changes nothing.

### Changed

- Requires `indexnowkit/core ^0.9` and `indexnowkit/console ^0.3`.

## [0.4.0] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.8` and `indexnowkit/console ^0.2`.

### Added

- `Check\SitemapSpoolCheck` lines carry the code `sitemap.spool` (`SitemapSpoolCheck::CODE`; core 0.8 `check --json`).
- `indexnow:sitemap` warns after a run without `--changed-since` that submitted more than `batch.max_urls` URLs (the
  whole sitemap: engines see every page as changed): run it once, then schedule it with `--changed-since` (spec 17
  §3.3/§5.7). With `--json` the warning goes to stderr.

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
