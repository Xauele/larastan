<?php

declare(strict_types=1);

namespace Tests\Rules;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use ScopeFactoryRootAudit\FactoryRoot;
use ScopeFactoryRootAudit\ClassMappedRoot;
use ScopeFactoryRootAudit\SafeMappedRoot;
use ScopeFactoryRootAudit\SafeFactoryRoot;

use function ScopeFactoryRootAudit\register;
use function ScopeFactoryRootAudit\registerMapped;

final class OctaneFactoryRootAuditRuntimeTest extends TestCase
{
    public function testFactoryDoesNotCallAttributedSingletonRootConstructor(): void
    {
        require_once __DIR__ . '/data/OctaneFactoryRootAudit.php';

        $container = new Container();
        register($container);

        $first = $container->make(FactoryRoot::class);

        self::assertInstanceOf(SafeFactoryRoot::class, $first);
        self::assertFalse((new ReflectionProperty(FactoryRoot::class, 'request'))->isInitialized($first));

        $container->forgetScopedInstances();

        self::assertSame($first, $container->make(FactoryRoot::class));
    }

    public function testKnownConcreteMappingDoesNotInvokeAttributedRootConstructor(): void
    {
        require_once __DIR__ . '/data/OctaneFactoryRootAudit.php';

        $container = new Container();
        registerMapped($container);

        $first = $container->make(ClassMappedRoot::class);

        self::assertInstanceOf(SafeMappedRoot::class, $first);
        self::assertFalse((new ReflectionProperty(ClassMappedRoot::class, 'request'))->isInitialized($first));

        $container->forgetScopedInstances();

        self::assertSame($first, $container->make(ClassMappedRoot::class));
    }
}
