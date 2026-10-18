<?php

declare(strict_types=1);

namespace Tests\Rules;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use OctaneExtendedLifetimeCases\AttributeOwnerOverriddenToTransient;
use OctaneExtendedLifetimeCases\BaselineNativeSingleton;
use OctaneExtendedLifetimeCases\BaselineTransient;
use OctaneExtendedLifetimeCases\ConstructorOnlyOwner;
use OctaneExtendedLifetimeCases\ContextFromScopedBinding;
use OctaneExtendedLifetimeCases\ContextOverriddenToSingleton;
use OctaneExtendedLifetimeCases\InterfaceOnlyOwner;
use OctaneExtendedLifetimeCases\OptionalBoundConsumer;
use OctaneExtendedLifetimeCases\OptionalUnboundConsumer;
use OctaneExtendedLifetimeCases\OwnerWithExplicitlySharedDependency;
use OctaneExtendedLifetimeCases\ProviderSharedOwner;
use OctaneExtendedLifetimeCases\ProviderSingletonMap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_keys;
use function class_exists;
use function OctaneExtendedLifetimeCases\registerLifetimeCases;

final class OctaneExtendedRuntimeOracleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/data/OctaneExtendedLifetimeCases.php';
    }

    public function testProviderSingletonPropertyActuallyCapturesTheFirstRequest(): void
    {
        $previous = Container::getInstance();

        try {
            $app    = new Application();
            $first  = Request::create('/first');
            $second = Request::create('/second');
            $app->instance('request', $first);
            $app->register(ProviderSingletonMap::class);

            $owner = $app->make(ProviderSharedOwner::class);
            self::assertSame($first, $owner->request);

            $app->instance('request', $second);
            $app->forgetScopedInstances();

            self::assertSame($owner, $app->make(ProviderSharedOwner::class));
            self::assertSame($first, $app->make(ProviderSharedOwner::class)->request);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function testOptionalInjectionDependsOnContainerBindingNotOnlyItsSignature(): void
    {
        $previous = Container::getInstance();

        try {
            $app = new Application();

            $unboundOptional = $app->make(OptionalUnboundConsumer::class);

            // Laravel 11 autowires optional classes before using defaults;
            // Laravel 12+ honors the default when the class is unbound.
            if (class_exists(Singleton::class)) {
                self::assertNull($unboundOptional->context);
            } else {
                self::assertInstanceOf(ContextFromScopedBinding::class, $unboundOptional->context);
            }

            registerLifetimeCases($app);

            $context = $app->make(ContextFromScopedBinding::class);
            $owner   = $app->make(OptionalBoundConsumer::class);
            self::assertSame($context, $owner->context);

            $app->forgetScopedInstances();

            self::assertNotSame($context, $app->make(ContextFromScopedBinding::class));
            self::assertSame($context, $app->make(OptionalBoundConsumer::class)->context);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function testOverridesAndInterfaceBindingsAreResolvedAtRuntime(): void
    {
        $previous = Container::getInstance();

        try {
            $app    = new Application();
            $first  = Request::create('/tenant-a');
            $second = Request::create('/tenant-b');
            $app->instance('request', $first);
            registerLifetimeCases($app);

            $sharedContext = $app->make(ContextOverriddenToSingleton::class);
            $owner         = $app->make(OwnerWithExplicitlySharedDependency::class);
            $interface     = $app->make(InterfaceOnlyOwner::class);
            $native        = $app->make(BaselineNativeSingleton::class);
            $transient     = $app->make(BaselineTransient::class);

            self::assertSame($sharedContext, $owner->context);
            self::assertSame($first, $interface->carrier->request);
            self::assertSame($first, $native->request);
            self::assertSame($first, $transient->request);

            $app->instance('request', $second);
            $app->forgetScopedInstances();

            self::assertSame($sharedContext, $app->make(ContextOverriddenToSingleton::class));
            self::assertSame($owner, $app->make(OwnerWithExplicitlySharedDependency::class));
            self::assertSame($first, $app->make(InterfaceOnlyOwner::class)->carrier->request);
            self::assertSame($first, $app->make(BaselineNativeSingleton::class)->request);
            self::assertNotSame($transient, $app->make(BaselineTransient::class));
            self::assertSame($second, $app->make(BaselineTransient::class)->request);

            if (class_exists(Singleton::class)) {
                $overridden = $app->make(AttributeOwnerOverriddenToTransient::class);
                self::assertSame($second, $overridden->request);
                // Native #[Singleton] still wins over an explicit bind(..., shared: false).
                self::assertSame($overridden, $app->make(AttributeOwnerOverriddenToTransient::class));
            }
        } finally {
            Container::setInstance($previous);
        }
    }

    public function testConstructorOnlyUseDoesNotRetainRequestAsProperty(): void
    {
        if (! class_exists(Singleton::class)) {
            self::markTestSkipped('Native Singleton attribute is unavailable in Laravel 11.');
        }

        $previous = Container::getInstance();

        try {
            $app = new Application();
            $app->instance('request', Request::create('/first'));
            $service = $app->make(ConstructorOnlyOwner::class);

            self::assertSame([], array_keys((new ReflectionClass($service))->getProperties()));

            $app->instance('request', Request::create('/second'));

            self::assertSame($service, $app->make(ConstructorOnlyOwner::class));
            self::assertSame([], array_keys((new ReflectionClass($service))->getProperties()));
        } finally {
            Container::setInstance($previous);
        }
    }
}
