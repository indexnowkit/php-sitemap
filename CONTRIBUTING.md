# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/sitemap`).
Please open issues and pull requests there; releases are tagged in the monorepo as `sitemap@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every change comes with tests; the reader's safety rules (origin, depth, size caps) are covered by `SitemapReaderTest`.
- phpstan level 9 and php-cs-fixer must pass.
- The package is a consumer of `indexnowkit/core`: nothing here may require a change in the core to work.
