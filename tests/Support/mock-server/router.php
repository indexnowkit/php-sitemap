<?php

declare(strict_types=1);

/**
 * Sitemap mock server (PHP built-in server edition) for the integration tests:
 * GET /sitemap.xml and /sitemap.xml.gz serve a urlset of 3000 entries (>100 KB), plain and gzip-compressed.
 * Run by hand: php -S 127.0.0.1:8089 packages/sitemap/tests/Support/mock-server/router.php
 */
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$respond = static function (int $status, string $body = '', string $type = 'text/plain'): void {
    http_response_code($status);
    header('Content-Type: ' . $type);
    echo $body;
};

$sitemapXml = static function (): string {
    $urls = '';
    for ($i = 0; $i < 3000; ++$i) {
        $urls .= '<url><loc>https://www.example.com/page-' . $i . '</loc></url>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
};

if ($method === 'GET' && $path === '/sitemap.xml') {
    $respond(200, $sitemapXml(), 'application/xml');

    return;
}

if ($method === 'GET' && $path === '/sitemap.xml.gz') {
    $respond(200, (string) gzencode($sitemapXml()), 'application/gzip');

    return;
}

$respond(404, 'not found');
