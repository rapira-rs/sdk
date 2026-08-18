<?php

declare(strict_types=1);

namespace Rapira\Testing\Testo;

use Internal\Container\Container;
use Override;
use Psr\Log\LoggerInterface;
use Rapira\Testing\Common\DLoader;
use Rapira\Testing\Testo\Attribute\RunRapira;
use Rapira\Testing\Testo\Internal\RapiraServer;
use Rapira\Testing\Testo\Internal\RunRapiraInterceptor;
use RuntimeException;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Common\EventListenerCollector;
use Testo\Common\Messenger;
use Testo\Common\PluginConfigurator;
use Testo\Event\TestSuite\TestSuiteStarting;

use function file_exists;

/**
 * Testo plugin that provisions the `rapira` binary for a suite.
 *
 * When the suite starts, it downloads the binary via dload (unless it is already present). Starting the
 * server itself is left to {@see RunRapiraInterceptor}, which the {@see RunRapira} attribute wires up on
 * its own; this plugin only binds {@see RapiraServer} into the container so that interceptor can be
 * built. The plugin should be attached to a specific suite via {@see SuitePlugins::with()}.
 *
 * @see https://rapira.rs/
 */
final class RunRapiraPlugin implements PluginConfigurator
{
    public const CHANNEL_DLOAD = 'dload';

    /**
     * @param non-empty-string $binary Absolute path to the rapira executable. When missing, it is
     * downloaded via dload.
     * @param non-empty-string $workingDirectory Absolute path to the application directory containing
     * `worker.php` and `rapira.toml`, from which the server is run.
     * @param non-empty-string $projectRoot Absolute path to the project root, where `dload.xml` and
     * `vendor/bin/dload` live. dload is invoked from here so it reads the project's `dload.xml`.
     * @param non-empty-string $dloadSoftware The dload software alias to download (see `dload.xml`).
     */
    public function __construct(
        private readonly string $binary,
        private readonly string $workingDirectory,
        private readonly string $projectRoot,
        private readonly string $dloadSoftware = 'rapira',
    ) {}

    #[Override]
    public function configure(Container $container): void
    {
        $messenger = $container->get(Messenger::class);

        // Download the binary once, when the suite starts.
        $container->get(EventListenerCollector::class)->addListener(
            TestSuiteStarting::class,
            fn() => $this->ensureBinary($messenger->channel(self::CHANNEL_DLOAD)),
        );

        // Expose the binary and application directory so Testo's injector can build
        // RunRapiraInterceptor when it wires up the #[RunRapira] attribute.
        $container->set(new RapiraServer($this->binary, $this->workingDirectory));
    }

    /**
     * Ensure the rapira binary is present, downloading it via dload if needed.
     */
    private function ensureBinary(LoggerInterface $logger): void
    {
        if (file_exists($this->binary)) {
            return;
        }

        (new DLoader($this->projectRoot, $logger))->download($this->dloadSoftware);

        if (!file_exists($this->binary)) {
            throw new RuntimeException("rapira binary not found at: {$this->binary} (dload did not produce it)");
        }
    }
}
