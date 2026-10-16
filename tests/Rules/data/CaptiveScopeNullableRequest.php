<?php

declare(strict_types=1);

namespace ScopeCompatibilityNullableRequest;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

final class RequiredNullableConsumer
{
    public function __construct(?Request $request)
    {
    }
}

final class OptionalNullableConsumer
{
    public function __construct(?Request $request = null)
    {
    }
}

function register(Container $app): void
{
    $app->singleton(RequiredNullableConsumer::class);
    $app->singleton(OptionalNullableConsumer::class);
}
