# Security

Report vulnerabilities privately to the maintainer (see `composer.json` → `authors`) or through GitHub's private
vulnerability reporting on [indexnowkit/php](https://github.com/indexnowkit/php/security). Please do not open a
public issue for an unfixed vulnerability. Reports are acknowledged within a few days; fixes ship as patch releases
of the affected minor.

## What the reader does with untrusted documents

A sitemap is a document from the network that names other URLs. The reader:

- follows nested sitemaps only on the origin of the root sitemap (scheme, host and port) unless
  `allow_foreign_hosts` is set — then the sitemap decides which http(s) hosts your server fetches from, so enable
  it only for a sitemap you control; `file://` and local parts are never followed from a remote root;
- caps recursion depth (`max_depth`), the number of fetched documents (`max_sitemaps`) and the size of every
  document before and after gunzip (`max_bytes`), and treats a response shorter than its `Content-Length` or a
  connection lost mid-body as a `TransportException`, never as a document;
- spools documents to anonymous temp files (or memory, bounded by the size cap) and reads them back through the
  `indexnowkit-spool://` stream wrapper, which only resolves spools this process opened;
- disables external entities, DTD loading and network access in the XML parser (`LIBXML_NONET`, no entity
  substitution), so a sitemap cannot make the parser read local files or call out;
- escapes control characters and cuts long values before a sitemap value reaches a log line, so a document cannot
  forge log entries.

Only the URLs are taken from the document; they are then submitted under the keys of `hosts` / `key` like any
other URL, with the core's normalization and host checks (`strict_hosts` applies).
