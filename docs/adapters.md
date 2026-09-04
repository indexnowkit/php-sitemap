# Wiring the `sitemap` command into an adapter

The command body, the reader and the check live here; an adapter parses its own input and binds three objects.
Everything reads one `SitemapConfig`, built from the raw `sitemap` block of the adapter's configuration.

```php
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;

$sitemap = SitemapConfig::fromArray($raw['sitemap'] ?? []);              // throws ConfigurationException naming the key
$reader = SitemapReader::fromConfig($sitemap, $kit->transport ?? TransportFactory::lazy($kit->config), $logger);
$check = new SitemapSpoolCheck($sitemap);                                 // add it to the checks of your `check` command
$runner = new SitemapRunner($kit, $reader, $submitterFactory, $sitemap->url, $formatter, sitemapUrlOption: 'myfw.sitemap.url');

$exit = $runner->run($io, new SitemapOptions($argument, $changedSince, $allowForeignHosts, $force, $dryRun, $json));
```

- **Configuration.** Add `SitemapConfig::OPTIONS` to the keys your `Adapter\ConfigFactory` owns
  (`ownedOptions: [...MY_OPTIONS, ...SitemapConfig::OPTIONS]`), so a typo inside the block is warned about. Do not
  list a bare `sitemap` key: it would stop `Config::unknownOptions()` from looking inside the block. When the block
  is invalid, log at `critical` and fall back to `SitemapConfig::disabled()` the way the core `ConfigFactory::load()`
  does for the core options; when `enabled` is false, register no command (bundle) or refuse to run it with
  `sitemap.enabled is false.` and exit `INVALID` (Laravel, Yii2).
- **Transport.** The reader fetches over the transport the facade submits through, so `http.client` and
  `http.timeout` apply and nothing is discovered twice. `$kit->transport` is `null` only when the facade was built
  around a custom submitter; `Http\TransportFactory::lazy($kit->config)` covers that.
- **Source.** Type the command against `SitemapSourceInterface` and expose the reader under an alias of it, so an
  application can decorate the source (filter, rewrite) or replace it. `--allow-foreign-hosts` only reaches the
  shipped `SitemapReader`; the runner warns when the configured source is something else.
- **Output.** The runner streams, submits every `batch.max_urls` URLs through `Console\SubmitterFactory::choose()`
  (`--force`/`--dry-run` get a separate submitter), folds results into `Console\ResultSummary`, and submits the
  pending batch before reporting a mid-run failure; `--json` keeps stdout machine-readable (the error goes to
  stderr). Exit codes are `Console\ExitCode`.
- **Words.** The only framework-specific string is `sitemapUrlOption`, printed in
  `Give a sitemap URL, or configure <option> or base_url.` when no sitemap is known.

The reference wiring: `IndexNowKitLoader` of the Symfony bundle (services `indexnowkit.sitemap_config`,
`indexnowkit.sitemap_reader`, `indexnowkit.console.sitemap`), `IndexNowKitServiceProvider::registerDiagnostics()`
in Laravel, `IndexNowComponent::sitemapConfig()` / `sitemapSource()` in Yii2.
