# Чтение sitemap для IndexNow — `indexnowkit/sitemap`

Переотправьте URL сайта Яндексу, Bing и остальным поисковикам с поддержкой [IndexNow](https://yandex.ru/support/webmaster/ru/indexing-options/index-now)
по его собственному sitemap: индекс, gzip и текстовые sitemap читаются потоково, запись за записью, и отправляются
порциями, так что sitemap на миллион URL никогда не лежит в памяти. Команда `sitemap` каждого адаптера семейства
(`indexnowkit/symfony-bundle`, `laravel`, `yii2`) — это этот пакет; в чистом PHP это три строки поверх
[`indexnowkit/core`](https://github.com/indexnowkit/php/tree/main/packages/core).

**Google — нет.** Google не поддерживает IndexNow, ping-endpoint для sitemap закрыт, а Indexing API ограничен
`JobPosting` / `BroadcastEvent`. Для Google остаётся sitemap; этот пакет объявляет его только движкам IndexNow.
IndexNow — уведомление, не индексация: обходить ли и когда — решает поисковик.

Прогон без `--changed-since` объявляет весь sitemap заново: сделайте это один раз, дальше — по расписанию с
`--changed-since "1 day"`. `--changed-since` опирается на `<lastmod>`; генератор, пишущий `lastmod = now()` для каждого
URL, превращает каждый прогон в полный, а записи без `lastmod` при этой опции пропускаются.

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Установка

```bash
composer require indexnowkit/sitemap        # тянет indexnowkit/core; нужен ext-xmlreader, для .gz — ext-zlib
```

С адаптером фреймворка ставить ничего не нужно: адаптер требует этот пакет и регистрирует команду
(`bin/console indexnow:sitemap`, `php artisan indexnow:sitemap`, `php yii indexnow/sitemap`) с блоком `sitemap` в
своей конфигурации.

## Чистый PHP

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

`read()` отдаёт объекты `SitemapEntry` (`url`, `lastmod`), при необходимости только те, чей `<lastmod>` новее
`$changedSince` (записи без `lastmod` тогда пропускаются). Корень — http(s)-URL, локальный путь или `file://`;
вложенные sitemap индекса загружаются через переданный транспорт (тот же, через который фасад отправляет, так что
действуют `http.client` и `http.timeout`). `$kit->transport` равен `null`, если фасад собран вокруг своего
submitter'а: тогда `Http\TransportFactory::lazy($kit->config)`.

## Конфигурация

`SitemapConfig::fromArray()` читает блок `sitemap`, который есть у каждого адаптера; `SitemapConfig::OPTIONS` —
его dotted-ключи для `Config::unknownOptions()`.

| Ключ | По умолчанию | |
|---|---|---|
| `sitemap.enabled` | `true` | `false`: адаптер не регистрирует ни команду, ни reader |
| `sitemap.url` | `null` | sitemap, который читает команда без аргумента; `null` = `<base_url>/sitemap.xml` |
| `sitemap.max_depth` | `3` | уровней `<sitemapindex>` ниже корня (`0` = только корень) |
| `sitemap.max_sitemaps` | `1000` | документов за прогон, включая корень |
| `sitemap.max_bytes` | `52428800` | предел размера одного распакованного документа (50 МиБ, максимум протокола; не меньше 1024) |
| `sitemap.allow_foreign_hosts` | `false` | следовать за вложенными sitemap на других origin (части на CDN); `--allow-foreign-hosts` включает на один прогон |
| `sitemap.spool` | `auto` | где лежит документ при разборе: `auto` = временный файл, память при недоступном temp; `disk` = файл или ошибка; `memory` |
| `sitemap.spool_dir` | `null` | каталог временных файлов (`sys_get_temp_dir()`); на read-only ФС укажите записываемый том |
| `sitemap.fetch_retries` | `2` | дополнительные попытки (через 1, 2, 4 с) после сетевого сбоя или 5xx при загрузке документа; 4xx и битые документы не повторяются |

## Безопасность и память

Память не зависит от размера sitemap: каждый документ складывается в spool (`Sitemap\Spool`: временный файл либо
память на read-only ФС; прямо из сокета, если транспорт реализует `Http\StreamingTransportInterface`, как
`Psr18Transport`), gzip распаковывается по кускам во второй spool, `XMLReader` идёт по spool через обёртку
`indexnowkit-spool://` с буферами в несколько КиБ. Вложенные sitemap должны лежать на origin корня, если
`allow_foreign_hosts` не говорит иначе; глубина рекурсии, число документов и размер документа (до и после gunzip)
ограничены; внешние сущности и сеть в XML-парсере отключены. Сбой вложенного sitemap логируется и пропускается,
сбой корня — `Http\Exception\TransportException`; ответ короче своего `Content-Length` — недокачка, а не документ.
Подробности — в [SECURITY.md](SECURITY.md).

`<loc>` может назвать любой хост, поэтому команда оставляет только записи на хостах, для которых у сайта есть ключ
(`base_url` плюс карта `hosts` — `KeyProviderInterface::managedHosts()`); остальные отбрасываются до того, как их
кто-либо запросит или отправит, и одна строка сообщает, сколько и на каких хостах. Важнее всего это с
[`indexnowkit/verify`](https://github.com/indexnowkit/php/tree/main/packages/verify): его pre-flight делает GET на
каждый URL батча, и без фильтра sitemap, **собранный из пользовательских данных** (или подменённый), мог бы обойти
внутренние адреса. **Если sitemap собирается из пользовательских данных, ставьте и `strict_hosts: true`** — тогда
проскочивший хост получит отказ вместо ключа по умолчанию. Если ключа нет вовсе (ни `base_url`, ни карты `hosts`),
сравнивать не с чем: записи идут как есть, с предупреждением.

## Команда

`Sitemap\Console\SitemapRunner` — тело `sitemap [url]` (`--changed-since "1 day"`, `--allow-foreign-hosts`,
`--force`, `--dry-run`, `--json`, `--no-verify`, `--new-only`): читает потоково, отправляет каждые `batch.max_urls` URL и досылает
накопленную порцию перед сообщением о сбое посреди прогона (повтор идемпотентен, прочитанное стоит объявить).
`--new-only` отправляет только новые или изменившиеся с прошлого прогона URL: раннер хранит отпечаток каждой
объявленной записи (URL плюс `<lastmod>` или его отсутствие) в `Sitemap\SeenStoreInterface`, который даёт приложение —
[CLI `indexnow`](https://github.com/indexnowkit/php/tree/main/packages/cli) держит его в файле состояния, так что
sitemap без `lastmod` тоже объявляет каждое изменение один раз; у адаптеров фреймворков такого хранилища пока нет, и
они отвечают `--new-only needs a store of seen URLs` с кодом 2. Каждый прогон без `--dry-run` учит хранилище (кроме
упавших батчей): «весь sitemap один раз, дальше `--new-only` в cron» объявляет каждое изменение ровно один раз.
Команда `check` каждого адаптера несёт `Sitemap\Check\SitemapSpoolCheck`: куда складываются документы и
записываем ли этот каталог — иначе это всплывает на первом запуске по расписанию.

Приложение декорирует источник (фильтр, переписывание) или заменяет его (другой формат, база), реализуя
`Sitemap\SitemapSourceInterface` и привязывая под алиасом адаптера. Пишете адаптер?
[docs/adapters.md](docs/adapters.md).

## Авторам адаптеров

`Sitemap\Adapter\SitemapServices` — всё, что адаптер фреймворка подключает для этого пакета, в одном месте: предикат (`package()`), свои опции,
проверенный блок, ридер, проверку спула, тело команды `sitemap` (`runner()`) и саму команду (`command()`: `Sitemap\Console\SitemapCommand` — класс, который адаптер на symfony/console регистрирует вместо своего) — статические функции над частями, с двойниками `*For()` поверх
`Adapter\Services` ядра для рантайм-графа. Symfony-бандл, адаптеры Laravel и Yii2 построены на нём; см.
[adapters.md](https://github.com/indexnowkit/php-core/blob/main/docs/adapters.md) ядра.

## Требования

PHP 8.2+, `ext-xmlreader`, `indexnowkit/core ^0.13`, `indexnowkit/console ^0.5` (команда и её тело печатают через
`symfony/console`); `ext-zlib` для gzip.

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/sitemap` (дополнение к `indexnowkit/core`; `ext-xmlreader`, `ext-zlib` для gzip). С адаптером фреймворка это команда `sitemap` — ничего подключать не надо.
- Минимальный полный сниппет (все `use` на месте):

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

- Проверка: команда `check` адаптера печатает строку `sitemap:` про spool; `bin/console indexnow:sitemap --dry-run`, `php artisan indexnow:sitemap --dry-run`, `php yii indexnow/sitemap --dry-run`.
- Ловушки:
  - `dispatch: auto` есть в Symfony (`auto` | `messenger` | `sync` | `none`) и Yii2 (`auto` | `queue` | `sync` | `none`), в Laravel **нет** (`queue` | `sync` | `none`).
  - Локали: `router.locales` в Laravel, Yii2 и Yii3, `framework.enabled_locales` в Symfony; `locales: 'all'` у правила берёт этот список.
  - `url:` — имя аксессора (метод или свойство), который возвращает URL; `urls:` — список литеральных URL. Литерал в `url:` не ставить.
  - Строка в `when:` — аксессор, читаемый как truthy (`published`, `isPublished`). Строка статуса требует `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Ручная отправка: `submitEntity()` в Symfony, `submitModel()` в Laravel, `submitRecord()` в Yii2 и Yii3; команды — `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record` (Yii2), `indexnow:submit-record` (Yii3). Массовые запросы (`update()`, `DB::table()`, `updateAll()`) хуков не вызывают — отправляйте ими после.
  - В Laravel два класса `IndexNowKit`: фасад `IndexNowKit\Laravel\Facades\IndexNowKit` и сервис ядра `IndexNowKit\IndexNowKit` (инжектится по типу). В Yii2 ядро — `Yii::$app->indexnow->kit()`.
  - Вне production настроенный ключ с незаданным `dry_run` делает `check` красным (стейджинг отправил бы боевые URL): задайте там `dry_run: true`, либо явный `dry_run: false`, если отправка нарочно.
  - Неизвестные ключи конфигурации дают warning при загрузке (опечатки вроде debounce.per_urls); список — `Config::OPTIONS` плюс ключи адаптера.


## Версионирование

SemVer; до 1.0 минорные версии могут ломать совместимость, изменения перечислены в [CHANGELOG.md](CHANGELOG.md).
Что покрывает обещание совместимости: [docs/bc.md](docs/bc.md).

MIT. IndexNow — товарный знак его владельца; проект независимый и не связан с Microsoft, Яндексом или indexnow.org.
