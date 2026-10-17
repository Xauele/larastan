<?php

declare(strict_types=1);

namespace Tests\Rules;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use OctaneAdversarialNativeCases\MixedCaseScopedContext;
use OctaneAdversarialNativeCases\MixedCaseSingleton;
use OctaneAdversarialNativeCases\SharedContract;
use OctaneAdversarialNativeCases\SharedViaNamedBind;
use OctaneAdversarialNativeCases\SharedViaPositionalBind;
use OctaneAdversarialNativeCases\TransientViaDefaultBind;
use OctaneAdversarialNativeCases\TransientViaExplicitFalseBind;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function class_exists;
use function OctaneAdversarialNativeCases\registerBindings;

final class OctaneAdversarialRuntimeOracleTest extends TestCase
{
    public function testNativeCaseVariantAttributesHaveRealSingletonAndScopedLifetimes(): void
    {
        if (! class_exists(Scoped::class) || ! class_exists(Singleton::class)) {
            self::markTestSkipped('Native lifetime attributes require Laravel 12+.');
        }

        require_once __DIR__ . '/data/OctaneAdversarialNativeCases.php';

        self::assertCount(1, (new ReflectionClass(MixedCaseSingleton::class))->getAttributes(Singleton::class));
        self::assertCount(1, (new ReflectionClass(MixedCaseScopedContext::class))->getAttributes(Scoped::class));

        $previous = Container::getInstance();

        try {
            $app          = new Application();
            $scopedOne    = $app->make(MixedCaseScopedContext::class);
            $singletonOne = $app->make(MixedCaseSingleton::class);

            self::assertSame($scopedOne, $singletonOne->context);

            $app->forgetScopedInstances();

            self::assertNotSame($scopedOne, $app->make(MixedCaseScopedContext::class));
            self::assertSame($singletonOne, $app->make(MixedCaseSingleton::class));
            self::assertSame($scopedOne, $app->make(MixedCaseSingleton::class)->context);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function testBindSharedTrueActuallyRetainsRequestAcrossRequestBoundaries(): void
    {
        require_once __DIR__ . '/data/OctaneAdversarialNativeCases.php';

        $previous = Container::getInstance();

        try {
            $app      = new Application();
            $requestA = Request::create('/tenant-a');
            $requestB = Request::create('/tenant-b');
            $app->instance('request', $requestA);
            registerBindings($app);

            $positional = $app->make(SharedViaPositionalBind::class);
            $named      = $app->make(SharedViaNamedBind::class);
            $contract   = $app->make(SharedContract::class);
            $transient  = $app->make(TransientViaDefaultBind::class);

            self::assertSame($requestA, $positional->request);
            self::assertSame($requestA, $named->request);
            self::assertSame($requestA, $contract->request);

            $app->instance('request', $requestB);
            $app->forgetScopedInstances();

            self::assertSame($positional, $app->make(SharedViaPositionalBind::class));
            self::assertSame($named, $app->make(SharedViaNamedBind::class));
            self::assertSame($contract, $app->make(SharedContract::class));
            self::assertSame($requestA, $positional->request);
            self::assertSame($requestA, $named->request);
            self::assertSame($requestA, $contract->request);

            self::assertNotSame($transient, $app->make(TransientViaDefaultBind::class));
            self::assertSame($requestB, $app->make(TransientViaDefaultBind::class)->request);
            self::assertSame($requestB, $app->make(TransientViaExplicitFalseBind::class)->request);
        } finally {
            Container::setInstance($previous);
        }
    }
}
