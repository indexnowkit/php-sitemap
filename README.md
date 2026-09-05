# IndexNow sitemap reader — `indexnowkit/sitemap`

Re-announce a site's URLs to Yandex, Bing and the other [IndexNow](https://www.indexnow.org) engines from its own
sitemap: a sitemap index, gzip-compressed and text sitemaps are streamed entry by entry and submitted in batches, so
a million-URL sitemap never lives in memory. The `sitemap` command of every framework adapter of the family
(`indexnowkit/symfony-bundle`, `laravel`, `yii2`) is this package; in plain PHP it is three lines over
[`indexnowkit/core`](https://github.com/indexnowkit/php/tree/main/packages/core).

**Google: no.** Google does not support IndexNow, its sitemap ping endpoint is gone and the Indexing API is limited to
`JobPosting` / `BroadcastEvent`. Keep your sitemap for Google; this package announces it to the IndexNow engines only.
IndexNow is a notification, not indexing: the engine decides whether and when to crawl.

A run without `--changed-since` re-announces the whole sitemap: do that once, then schedule `--changed-since "1 day"`.
`--changed-since` relies on `<lastmod>`; a generator that writes `lastmod = now()` for every URL turns every run into a
full run, and entries without `lastmod` are skipped when the option is set.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/sitemap)](https://packagist.org/packages/indexnowkit/sitemap)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/sitemap)](https://packagist.org/packages/indexnowkit/sitemap)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![Coverage](https://img.shields.io/badge/coverage-%E2%89%A5%2090%25%20enforced-brightgreen)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/sitemap)](LICENSE)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Install

```bash
composer require indexnowkit/sitemap        # brings indexnowkit/core; needs ext-xmlreader, ext-zlib for .gz
```

With a framework adapter you install nothing: the adapter requires this package and registers the command
(`bin/console indexnow:sitemap`, `php artisan indexnow:sitemap`, `php yii indexnow/sitemap`) with its `sitemap`
configuration block.

## Plain PHP

```php
use IndexNowKit\Config;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;

$kit = IndexNowKit::create(Config::fromEnv());
$reader = SitemapReader::fromConfig(SitemapConfig::fromArray(['spool' => 'auto']), $kit->transport);

$batch = [];
foreach ($reader->read('https://www.example.com/sitemap.xml', new DateTimeImmutable('-1 day')) as $entry) {
    $batch[] = $entry->url;
    if (\count($batch) === $kit->config->batchMaxUrls) {
        $kit->submit($batch);
        $batch = [];
    }
}
$kit->submit($batch);
```

`read()` yields `SitemapEntry` objects (`url`, `lastmod`), optionally only those whose `<lastmod>` is newer than
`$changedSince` (entries without `lastmod` are then skipped). The root may be an http(s) URL, a local path or a
`file://` URL; nested sitemaps of an index are fetched over the transport you pass (the one the facade submits
through, so `http.client` and `http.timeout` apply). `$kit->transport` is `null` when the facade was built around a
custom submitter: use `Http\TransportFactory::lazy($kit->config)` then.

## Configuration

`SitemapConfig::fromArray()` reads the `sitemap` block every adapter exposes; `SitemapConfig::OPTIONS` lists its
dotted keys for `Config::unknownOptions()`.

| Key | Default | |
|---|---|---|
| `sitemap.enabled` | `true` | `false`: the adapter registers no command and no reader |
| `sitemap.url` | `null` | sitemap read when the command gets no argument; `null` = `<base_url>/sitemap.xml` |
| `sitemap.max_depth` | `3` | levels of `<sitemapindex>` followed below the root (`0` = the root only) |
| `sitemap.max_sitemaps` | `1000` | documents fetched per run, root included |
| `sitemap.max_bytes` | `52428800` | size cap of one uncompressed document (50 MiB, the protocol maximum; at least 1024) |
| `sitemap.allow_foreign_hosts` | `false` | follow nested sitemaps on other origins (CDN-hosted parts); `--allow-foreign-hosts` enables it for one run |
| `sitemap.spool` | `auto` | where a document is kept while parsing: `auto` = temp file, memory when the temp dir is not writable; `disk` = temp file or fail; `memory` |
| `sitemap.spool_dir` | `null` | directory of the temp files (`sys_get_temp_dir()`); point it at a writable volume on a read-only filesystem |
| `sitemap.fetch_retries` | `2` | extra attempts (1 s, 2 s, 4 s apart) after a network failure or 5xx while fetching a document; 4xx and broken documents are never retried |

## How it stays safe and small

Memory stays flat whatever the sitemap size: every document is spooled (`Sitemap\Spool`: a temp file, or memory on a
read-only filesystem; straight from the socket when the transport implements `Http\StreamingTransportInterface`, as
`Psr18Transport` does), gzip is inflated chunk by chunk into a second spool, and `XMLReader` walks the spool through
the `indexnowkit-spool://` wrapper with a few KiB of buffers. Nested sitemaps must live on the origin of the root
unless `allow_foreign_hosts` says otherwise; recursion depth, document count and document size (before and after
gunzip) are capped; external entities and network access are disabled in the XML parser. A failing nested sitemap is
logged and skipped; a failing root throws `Http\Exception\TransportException`, and a response shorter than its
`Content-Length` is a truncated download, never a document. Details in [SECURITY.md](SECURITY.md).

## The command

`Sitemap\Console\SitemapRunner` is the body of `sitemap [url]` (`--changed-since "1 day"`, `--allow-foreign-hosts`,
`--force`, `--dry-run`, `--json`); it streams, submits every `batch.max_urls` URLs, and submits the pending batch
before reporting a mid-run failure (the re-run is idempotent, what was read is still worth announcing). The
`check` command of every adapter carries `Sitemap\Check\SitemapSpoolCheck`: where documents are spooled, and whether
that directory is writable — the kind of thing that otherwise only shows up on the first scheduled run.

An application decorates the source (filter, rewrite) or replaces it (another format, a database) by implementing
`Sitemap\SitemapSourceInterface` and binding it under the adapter's alias. Writing an adapter?
[docs/adapters.md](docs/adapters.md).

## Requirements

PHP 8.2+, `ext-xmlreader`, `indexnowkit/core ^0.6`; `ext-zlib` for gzip-compressed sitemaps; `symfony/console` for
the command body.

## Notes for AI assistants

- Composer package `indexnowkit/sitemap` (add-on of `indexnowkit/core`; `ext-xmlreader`, `ext-zlib` for gzip). With a framework adapter it is the `sitemap` command — nothing to wire.
- Minimal complete snippet (every `use` included):

```php
use IndexNowKit\Config;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;

$indexNow = IndexNowKit::create(Config::fromEnv());
$reader = SitemapReader::fromConfig(SitemapConfig::fromArray(['spool' => 'auto']), $indexNow->transport);
foreach ($reader->read('https://www.example.com/sitemap.xml') as $entry) { $indexNow->collect([$entry->url]); }
$indexNow->flush();                                            // batches of batch.max_urls, debounced
```

- Verify: the adapter's `check` command prints the `sitemap:` spool line; `bin/console indexnow:sitemap --dry-run`, `php artisan indexnow:sitemap --dry-run`, `php yii indexnow/sitemap --dry-run`.
- Pitfalls:
  - `dispatch: auto` exists in Symfony (`auto` | `messenger` | `sync` | `none`) and Yii2 (`auto` | `queue` | `sync` | `none`), **not** in Laravel (`queue` | `sync` | `none`).
  - Locales: `router.locales` in Laravel, `router.languages` in Yii2, `framework.enabled_locales` in Symfony; `locales: 'all'` on a rule uses that list.
  - `url:` names an accessor (method or property) that returns the URL; `urls:` is a list of literal URLs. Never put a literal in `url:`.
  - A string in `when:` is an accessor read as truthy (`published`, `isPublished`). A status string needs `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Manual submission is `submitEntity()` in Symfony, `submitModel()` in Laravel, `submitRecord()` in Yii2; the commands are `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record`. Bulk queries (`update()`, `DB::table()`, `updateAll()`) fire no hooks: submit afterwards with those.
  - Laravel has two classes called `IndexNowKit`: the facade `IndexNowKit\Laravel\Facades\IndexNowKit` and the core service `IndexNowKit\IndexNowKit` (inject by type). Yii2 exposes the core through `Yii::$app->indexnow->kit()`.
  - Outside production a configured key with `dry_run` unset makes `check` fail (a staging copy would submit real URLs): set `dry_run: true` there, or `dry_run: false` explicitly when it submits on purpose.
  - Unknown configuration keys are warned about at boot (typos such as debounce.per_urls); the key list is `Config::OPTIONS` plus the adapter's own keys.


## Versioning

SemVer; until 1.0 minor versions may contain breaking changes, listed in [CHANGELOG.md](CHANGELOG.md). What the
compatibility promise covers: [docs/bc.md](docs/bc.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
