<?php

declare(strict_types=1);

namespace Tests\Rules;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ScopeCompatibilityNullableRequest\BoundOptionalRequestConsumer;

final class OctaneOptionalRequestRuntimeTest extends TestCase
{
    public function testOptionalRequestIsInjectedWhenBoundToCurrentRequest(): void
    {
        $container = new Container();
        $container->alias('request', Request::class);

        $current = Request::create('/worker-request');
        $container->instance('request', $current);

        $singleton = $container->make(BoundOptionalRequestConsumer::class);

        self::assertSame($current, $singleton->request);
    }

    public function testOptionalRequestFallsBackToNullWhenUnbound(): void
    {
        $container = new Container();
        $singleton = $container->make(BoundOptionalRequestConsumer::class);

        self::assertNull($singleton->request);
    }
}
