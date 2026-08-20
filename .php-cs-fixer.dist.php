<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

return \Spiral\CodeStyle\Builder::create()
    ->include(__DIR__ . '/packages/http/src')
    ->include(__DIR__ . '/packages/http/tests')
    ->include(__DIR__ . '/packages/testing/src')
    ->include(__DIR__ . '/packages/testing/tests')
    ->include(__FILE__)
    ->build();
