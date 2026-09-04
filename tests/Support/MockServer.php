<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Support;

use RuntimeException;

/**
 * Starts tests/Support/mock-server/router.php with PHP's built-in server for the integration tests.
 */
final class MockServer
{
    /** @var resource */
    private $process;

    private function __construct(private readonly string $host, private readonly int $port, $process)
    {
        $this->process = $process;
    }

    public static function start(string $host = '127.0.0.1'): self
    {
        $port = self::freePort();
        $router = __DIR__ . '/mock-server/router.php';
        $cmd = \sprintf('exec php -S %s:%d %s', $host, $port, escapeshellarg($router));
        $process = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new RuntimeException('Cannot start mock server.');
        }
        $server = new self($host, $port, $process);
        for ($i = 0; $i < 50; ++$i) {
            usleep(100_000);
            $sock = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (\is_resource($sock)) {
                fclose($sock);

                return $server;
            }
        }
        $server->stop();

        throw new RuntimeException('Mock server did not start.');
    }

    public function baseUrl(): string
    {
        return \sprintf('http://%s:%d', $this->host, $this->port);
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new RuntimeException('Cannot allocate port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}
