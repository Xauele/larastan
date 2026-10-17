<?php

declare(strict_types=1);

namespace OctaneAdversarialNativeCases;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

#[sCoPeD]
final class MixedCaseScopedContext {}

#[Scoped]
final class StandardScopedContext {}

#[sInGlEtOn]
final class MixedCaseSingleton
{
    public function __construct(public MixedCaseScopedContext $context) {}
}

#[Singleton]
final class StandardSingletonMixedCaseScope
{
    public function __construct(public MixedCaseScopedContext $context) {}
}

#[sInGlEtOn]
final class MixedCaseSingletonStandardScope
{
    public function __construct(public StandardScopedContext $context) {}
}

#[Singleton]
final class BaselineSingleton
{
    public function __construct(public StandardScopedContext $context) {}
}

#[Singleton]
final class SafeSingleton
{
    public function __construct(public \stdClass $object) {}
}

final class SharedViaPositionalBind
{
    public function __construct(public Request $request) {}
}

final class SharedViaNamedBind
{
    public function __construct(public Request $request) {}
}

final class TransientViaDefaultBind
{
    public function __construct(public Request $request) {}
}

final class TransientViaExplicitFalseBind
{
    public function __construct(public Request $request) {}
}

final class BaselineExplicitSingleton
{
    public function __construct(public Request $request) {}
}

interface SharedContract {}

final class SharedImplementation implements SharedContract
{
    public function __construct(public Request $request) {}
}

function registerBindings(Container $app): void
{
    $app->bind(SharedViaPositionalBind::class, null, true);
    $app->bind(abstract: SharedViaNamedBind::class, shared: true);
    $app->bind(SharedContract::class, SharedImplementation::class, true);
    $app->bind(TransientViaDefaultBind::class);
    $app->bind(TransientViaExplicitFalseBind::class, shared: false);
    $app->singleton(BaselineExplicitSingleton::class);
}
