<div align="center">

# Rapira Testing

</div>

<div align="center">

[![PHP](https://img.shields.io/packagist/php-v/rapira/testing.svg?style=flat-square&logo=php)](https://packagist.org/packages/rapira/testing)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/rapira/testing.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/rapira/testing)
[![License](https://img.shields.io/packagist/l/rapira/testing.svg?style=flat-square)](LICENSE.md)

</div>

<br />

A [Testo](https://github.com/php-testo/testo) integration for testing PHP applications that run on
[rapira](https://rapira.rs/). It provisions the `rapira` binary for a test suite and starts a live
`rapira serve` process around annotated test cases, so tests can exercise the application over a real
socket instead of mocking the server.

## Installation

```bash
composer require --dev rapira/testing
```

The `rapira` binary itself is downloaded on demand via [dload](https://github.com/php-internal/dload) the
first time a suite that needs it runs. Your project must have a `dload.xml` describing where to fetch it
from (see the `dload-fetch-tool` skill or the dload documentation for how to register a software alias).

## Usage

### 1. Provision the binary for a suite

Attach `RunRapiraPlugin` to the suite in `testo.php`. It downloads the `rapira` binary (once, if missing)
and binds the application directory the server will run from.

```php
use Rapira\Testing\Testo\RunRapiraPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Integration',
            location: ['tests/Integration'],
            plugins: SuitePlugins::with(new RunRapiraPlugin(
                binary: __DIR__ . '/runtime/bin/rapira',
                workingDirectory: __DIR__ . '/tests/Integration/App',
                projectRoot: __DIR__,
            )),
        ),
    ],
);
```

### 2. Run the server around a test case

Annotate a test case with `#[RunRapira]`. Testo starts `rapira serve` before the case's tests and stops it
afterwards.

```php
use Rapira\Testing\Common\Mode;
use Rapira\Testing\Testo\Attribute\RunRapira;
use Testo\Attribute\Test;

#[RunRapira(mode: Mode::Worker, worker: 'worker.php', address: '127.0.0.1:8080')]
final class WorkerTest
{
    #[Test]
    public function respondsToRequests(): void
    {
        $response = file_get_contents('http://127.0.0.1:8080/');

        // ...assertions on $response
    }
}
```

`RunRapira` options:

| Option          | Default              | Description                                                      |
|-----------------|-----------------------|--------------------------------------------------------------------|
| `mode`          | `Mode::Worker`        | Run mode passed as `--mode` (`classic`, `worker`, or `dispatcher`). |
| `worker`        | `'worker.php'`        | Entrypoint script, absolute or relative to the working directory.  |
| `address`       | `'127.0.0.1:8080'`    | Listen address (`host:port`, `:port`, or `unix:<path>`).            |
| `readyTimeout`  | `5.0`                 | Seconds to wait for the server to accept connections.              |

## License

The BSD 3-Clause license. Please see [LICENSE.md](LICENSE.md) for more information.
