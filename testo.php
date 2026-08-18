<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    // For Codecov.
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests/Unit'],
        ),

        // End-to-end tests that hit the network (real GitHub downloads). Run deliberately, not as part
        // of the fast unit run.
        new SuiteConfig(
            name: 'Acceptance',
            location: ['tests/Acceptance'],
        ),

        // For inline tests and benchmarks right in the project source code, in the src folder.
        new SuiteConfig(
            name: 'Sources',
            location: ['src'],
        ),
    ],
);
