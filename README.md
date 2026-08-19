<div align="center">

# Rapira SDK

</div>

<br />

An SDK for building PHP applications and tooling on top of [rapira](https://rapira.rs/). It collects the
shared building blocks that frameworks and their rapira bridges keep re-implementing — PSR-7 request
factories, reusable wrappers and helpers, API clients, and testing utilities — under a single,
framework-neutral package.

Everything lives under the `Rapira\Testing` namespace, organised by concern:

- **`Common`** — framework-neutral core: binary provisioning (via [dload](https://github.com/php-internal/dload))
  and the `rapira serve` process lifecycle.
- **`Testo`** — a thin adapter that wires the core into the [Testo](https://github.com/php-testo/testo)
  test framework. It is the only framework bridge shipped at the moment; support for others may follow.

Its testing side provisions the `rapira` binary for a suite and starts a live `rapira serve` process
around your test cases, so tests can exercise the application over a real socket instead of mocking the
server.

## Installation

```bash
composer require rapira/sdk
```

[![PHP](https://img.shields.io/packagist/php-v/rapira/sdk.svg?style=flat-square&logo=php)](https://packagist.org/packages/rapira/sdk)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/rapira/sdk.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/rapira/sdk)
[![License](https://img.shields.io/packagist/l/rapira/sdk.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/rapira/sdk.svg?style=flat-square)](https://packagist.org/packages/rapira/sdk/stats)

The `rapira` binary itself is downloaded on demand via [dload](https://github.com/php-internal/dload) the
first time a suite that needs it runs. Your project must have a `dload.xml` describing where to fetch it
from (see the `dload-fetch-tool` skill or the dload documentation for how to register a software alias).

## Usage with Testo

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
