<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use OctaneGeneratedGraphStress\WideFirstRoot;
use OctaneGeneratedGraphStress\WideLastRoot;

$root = dirname(__DIR__, 3);

require $root . '/vendor/autoload.php';
require $root . '/tests/Rules/data/OctaneGeneratedGraphStress.php';

$previous = Container::getInstance();

try {
    $app      = new Application();
    $requestA = Request::create('/first');
    $requestB = Request::create('/second');
    $app->instance('request', $requestA);

    $first = $app->make(WideFirstRoot::class);
    $last  = $app->make(WideLastRoot::class);

    if ($first->tail->next !== $requestA || $last->tail->next !== $requestA) {
        throw new RuntimeException('Wide graph did not capture the current HTTP request.');
    }

    $app->instance('request', $requestB);
    $app->forgetScopedInstances();

    // Both contain identical dependency sets. Only parameter ordering differs.
    // Actual Laravel retains the old request in both cases.
    if ($first->tail->next !== $requestA || $last->tail->next !== $requestA) {
        throw new RuntimeException('Expected request A to remain captured in both wide singletons.');
    }

    echo "WIDE_RUNTIME_ORACLE_PASS: both permutations retain request A\n";
} finally {
    Container::setInstance($previous);
}
