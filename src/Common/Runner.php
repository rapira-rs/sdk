<?php

declare(strict_types=1);

namespace Rapira\Testing\Common;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function ctype_alpha;
use function dirname;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_exists;
use function fsockopen;
use function getenv;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;

/**
 * Starts and stops a single `rapira serve` process, logging its steps.
 *
 * {@see start()} launches the server (mode, listen address, and worker entrypoint given per call) and
 * blocks until it accepts connections; {@see stop()} terminates it. The invoked command and readiness
 * are reported through the injected logger at debug level.
 */
final class Runner
{
    /** @var resource|null Running process handle. */
    private $process = null;

    /**
     * @param non-empty-string $binary Absolute path to the rapira executable.
     * @param non-empty-string $workingDirectory Absolute path to the application directory containing
     * the worker script and `rapira.toml`. The server runs with this as its working directory, and
     * relative worker paths resolve against it.
     * @param LoggerInterface $logger Receives the invoked command and readiness at debug level.
     */
    public function __construct(
        private readonly string $binary,
        private readonly string $workingDirectory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Start the server and wait until it accepts connections. A no-op if one is already running.
     *
     * @param non-empty-string $worker Entrypoint PHP script; absolute, or relative to the working
     * directory.
     * @param non-empty-string $address Listen address (`--listen`): `host:port`, `:port`, or
     * `unix:<path>`. Also used to detect readiness.
     * @param float $readyTimeout Seconds to wait for the server to accept connections before failing.
     */
    public function start(Mode $mode, string $worker, string $address, float $readyTimeout = 5.0): void
    {
        if ($this->process !== null) {
            return;
        }

        if (!file_exists($this->binary)) {
            throw new RuntimeException("rapira binary not found at: {$this->binary} (was it downloaded?)");
        }

        $workerPath = $this->resolveWorker($worker);
        if (!file_exists($workerPath)) {
            throw new RuntimeException("rapira worker script not found at: {$workerPath}");
        }

        $command = $this->serveCommand($mode, $address, $workerPath);
        $this->logger->debug("Starting rapira: {$command}");

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->workingDirectory, $this->serverEnv());
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start rapira process');
        }
        $this->process = $process;

        $this->waitForReady($address, $readyTimeout);
        $this->logger->debug("rapira is ready on {$address}");
    }

    /**
     * Terminate the running server, if any.
     *
     * On Windows, uses `taskkill` to kill the process tree. On Unix, sends SIGTERM.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            if (DIRECTORY_SEPARATOR === '\\') {
                exec(sprintf('taskkill /F /T /PID %d 2>NUL', $status['pid']));
            } else {
                proc_terminate($this->process, 15);
            }
        }

        proc_close($this->process);
        $this->process = null;
    }

    /**
     * Build the `rapira serve` command: run mode, listen address, and the worker entrypoint. These CLI
     * flags override the corresponding keys in the application's `rapira.toml`.
     *
     * @param non-empty-string $worker Absolute path to the worker script.
     */
    private function serveCommand(Mode $mode, string $address, string $worker): string
    {
        return sprintf(
            '%s serve --mode %s --listen %s %s',
            escapeshellarg($this->binary),
            escapeshellarg($mode->value),
            escapeshellarg($address),
            escapeshellarg($worker),
        );
    }

    /**
     * Resolve a worker path against the working directory when it is relative.
     */
    private function resolveWorker(string $worker): string
    {
        return $this->isAbsolutePath($worker)
            ? $worker
            : $this->workingDirectory . '/' . $worker;
    }

    /**
     * Environment for the `rapira serve` process.
     *
     * `dload.xml` extracts `libphp.so`/`libphp.dylib` next to the binary (in `runtime/bin`) instead of
     * the rpath-expected `../lib/rapira`, so the dynamic loader has to be pointed at the binary's own
     * directory. Returns `null` on Windows (self-contained `.exe`, nothing to inject) so `proc_open`
     * inherits the parent environment as-is.
     *
     * @return array<string, string>|null
     */
    private function serverEnv(): ?array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        $binaryDir = dirname($this->binary);

        /** @var array<string, string> $env */
        $env = getenv();
        foreach (['LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH'] as $var) {
            $env[$var] = isset($env[$var]) && $env[$var] !== ''
                ? $binaryDir . PATH_SEPARATOR . $env[$var]
                : $binaryDir;
        }

        return $env;
    }

    /**
     * Poll the listen address until the server accepts connections.
     */
    private function waitForReady(string $address, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($this->accepts($address)) {
                return;
            }
            usleep(50_000); // 50ms between attempts
        }

        $this->stop();
        throw new RuntimeException("rapira did not start within {$timeout} seconds on {$address}");
    }

    /**
     * Whether the server is accepting connections on the listen address (TCP or Unix socket).
     */
    private function accepts(string $address): bool
    {
        if (str_starts_with($address, 'unix:')) {
            $socket = @fsockopen('unix://' . substr($address, 5), -1, $errno, $errstr, 0.1);
        } else {
            [$host, $port] = $this->splitHostPort($address);
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.1);
        }

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Split a `host:port` (or `:port`, meaning all interfaces) address into host and port. An empty
     * host is polled on the loopback interface.
     *
     * @return array{0: string, 1: int}
     */
    private function splitHostPort(string $address): array
    {
        $pos = strrpos($address, ':');
        $host = $pos === false ? $address : substr($address, 0, $pos);
        $port = $pos === false ? 0 : (int) substr($address, $pos + 1);

        return [$host === '' ? '127.0.0.1' : $host, $port];
    }

    /**
     * Whether the path is absolute (Unix `/…`, or Windows `\…` / `C:\…`).
     */
    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':')
        );
    }
}
