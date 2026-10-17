<?php

declare(strict_types=1);

namespace OctaneExtendedLifetimeCases;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class ProviderSharedOwner
{
    public function __construct(public Request $request)
    {
    }
}

final class ProviderSingletonMap extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        ProviderSharedOwner::class => ProviderSharedOwner::class,
    ];

    public function register(): void
    {
    }
}

#[Scoped]
final class ContextFromScopedBinding
{
}

final class OptionalBoundConsumer
{
    public function __construct(public ?ContextFromScopedBinding $context = null)
    {
    }
}

final class OptionalUnboundConsumer
{
    public function __construct(public ?ContextFromScopedBinding $context = null)
    {
    }
}

#[Scoped]
final class ContextOverriddenToSingleton
{
}

final class OwnerWithExplicitlySharedDependency
{
    public function __construct(public ContextOverriddenToSingleton $context)
    {
    }
}

#[Singleton]
final class AttributeOwnerOverriddenToTransient
{
    public function __construct(public Request $request)
    {
    }
}

#[Singleton]
final class ConstructorOnlyOwner
{
    public function __construct(Request $request)
    {
        // Deliberately used only during construction; not captured.
        $request->path();
    }
}

final class InterfaceOnlyOwner
{
    public function __construct(public RequestCarrier $carrier)
    {
    }
}

interface RequestCarrier
{
}

final class RequestCarrierImplementation implements RequestCarrier
{
    public function __construct(public Request $request)
    {
    }
}

final class BaselineNativeSingleton
{
    public function __construct(public Request $request)
    {
    }
}

final class BaselineTransient
{
    public function __construct(public Request $request)
    {
    }
}

function registerLifetimeCases(Container $app): void
{
    $app->scoped(ContextFromScopedBinding::class);
    $app->singleton(OptionalBoundConsumer::class);
    $app->singleton(ContextOverriddenToSingleton::class);
    $app->singleton(OwnerWithExplicitlySharedDependency::class);
    $app->bind(AttributeOwnerOverriddenToTransient::class);
    $app->singleton(InterfaceOnlyOwner::class);
    $app->bind(RequestCarrier::class, RequestCarrierImplementation::class);
    $app->singleton(BaselineNativeSingleton::class);
    $app->bind(BaselineTransient::class);
}
