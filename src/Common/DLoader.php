<?php

declare(strict_types=1);

namespace Rapira\Testing\Common;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function escapeshellarg;
use function fclose;
use function is_resource;
use function preg_split;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;

use const PHP_BINARY;

/**
 * Downloads software through the dload CLI (`vendor/bin/dload get <software>`), logging its steps.
 *
 * Delegates to the CLI rather than dload's PHP API so the download stays fully configured by the
 * project's `dload.xml` (asset pattern, extract path, extracted files). dload is invoked from
 * {@see $projectRoot} so it reads that project's `dload.xml`.
 */
final readonly class DLoader
{
    /**
     * @param non-empty-string $projectRoot Absolute path to the project root, where `dload.xml` and
     * `vendor/bin/dload` live.
     * @param LoggerInterface $logger Receives the invoked command and each line of dload output, all at
     * debug level.
     */
    public function __construct(
        private string $projectRoot,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Fetch the given dload software alias. Throws when dload cannot start or exits non-zero.
     *
     * @param non-empty-string $software The dload software alias to download (see `dload.xml`).
     */
    public function download(string $software): void
    {
        $command = sprintf(
            '%s %s get %s --no-interaction',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->projectRoot . '/vendor/bin/dload'),
            escapeshellarg($software),
        );
        $this->logger->debug(sprintf('Downloading %s via dload: %s', $software, $command));

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start dload');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Surface dload's own progress and diagnostics through the logger.
        $this->logLines($stdout);
        $this->logLines($stderr);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "dload failed to download '%s' (exit %d):\n%s%s",
                $software,
                $exitCode,
                $stdout,
                $stderr,
            ));
        }
    }

    /**
     * Log each non-empty line of captured dload output at debug level.
     */
    private function logLines(string $output): void
    {
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line !== '') {
                $this->logger->debug($line);
            }
        }
    }
}
