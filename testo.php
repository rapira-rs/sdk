<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;

return new ApplicationConfig(
    // Source roots for coverage, one per package.
    src: new FinderConfig([
        'packages/http/src',
        'packages/testing/src',
    ]),
    // Each package contributes its own suites; the root run aggregates them.
    suites: \array_merge(
        require __DIR__ . '/packages/http/tests/suites.php',
        require __DIR__ . '/packages/testing/tests/suites.php',
    ),
);
