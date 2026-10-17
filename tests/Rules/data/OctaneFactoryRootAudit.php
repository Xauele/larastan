<?php

declare(strict_types=1);

namespace ScopeFactoryRootAudit;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

#[Singleton]
class FactoryRoot
{
    public function __construct(public Request $request)
    {
    }
}

final class SafeFactoryRoot extends FactoryRoot
{
    public function __construct()
    {
    }
}

#[Singleton]
final class RealRoot
{
    public function __construct(public Request $request)
    {
    }
}

function register(Container $app): void
{
    // The singleton abstract has a Request constructor, but the actual
    // returned subtype never calls it. FactoryRoot::class remains shared
    // because of the native #[Singleton] attribute.
    $app->bind(FactoryRoot::class, static fn (): SafeFactoryRoot => new SafeFactoryRoot());
}

#[Singleton]
class ClassMappedRoot
{
    public function __construct(public Request $request)
    {
    }
}

final class SafeMappedRoot extends ClassMappedRoot
{
    public function __construct()
    {
    }
}

function registerMapped(Container $app): void
{
    // No factory here: a statically known explicit class-to-class binding
    // also supersedes the declared root constructor.
    $app->bind(ClassMappedRoot::class, SafeMappedRoot::class);
}
