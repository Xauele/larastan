<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Octane;

use Illuminate\Http\Request;
use PHPStan\Reflection\ClassReflection;

use function count;
use function in_array;
use function str_contains;
use function str_replace;

/**
 * Conservative counterpart to .NET DI scope validation.
 *
 * Only constructor-injected, non-optional object dependencies with an
 * unambiguous class type are followed. Runtime factories, interface bindings
 * and dynamic container aliases are intentionally not guessed.
 *
 * @internal
 */
final class ScopedDependencyDetector
{
    private const MAX_CLASSES = 64;

    private const MAX_DEPTH = 8;

    public function isSingleton(ClassReflection $class): bool
    {
        return $this->hasAttribute($class, 'Illuminate\Container\Attributes\Singleton');
    }

    /** @return non-empty-list<string>|null */
    public function findCaptiveDependency(ClassReflection $class): array|null
    {
        // Count attempts, not unique classes: a shorter path may expose
        // a dependency hidden behind the depth limit on an earlier path.
        $explored = 0;

        return $this->walk($class, [], $explored);
    }

    /**
     * @param list<string> $path
     *
     * @return non-empty-list<string>|null
     */
    private function walk(ClassReflection $class, array $path, int &$explored): array|null
    {
        if (count($path) >= self::MAX_DEPTH || $explored >= self::MAX_CLASSES) {
            return null;
        }

        $name = $class->getName();

        // Only a cycle on the current path is unsafe to traverse. A global
        // visited set loses valid shorter routes after a depth-limited walk.
        if (in_array($name, $path, true)) {
            return null;
        }

        $explored++;
        $path[] = $name;

        if ($this->isScoped($class)) {
            return $path;
        }

        if (! $class->hasNativeMethod('__construct')) {
            return null;
        }

        $variants = $class->getNativeMethod('__construct')->getVariants();

        if (count($variants) !== 1) {
            return null;
        }

        foreach ($variants[0]->getParameters() as $parameter) {
            // Laravel may use a default instead of resolving an optional service.
            // A union of unrelated types cannot be resolved confidently either.
            if ($parameter->isOptional() || $parameter->isVariadic() || ! $parameter->getType()->isObject()->yes()) {
                continue;
            }

            $dependencies = $parameter->getType()->getObjectClassReflections();

            if (count($dependencies) !== 1) {
                continue;
            }

            $dependency = $dependencies[0];

            if ($this->isScoped($dependency)) {
                return [...$path, $dependency->getName()];
            }

            if ($dependency->isInterface() || $dependency->isAbstract()) {
                // An interface may resolve to a different implementation at runtime.
                continue;
            }

            $file = $dependency->getFileName();

            // Only follow application code; do not guess transitive dependencies
            // through the internals of third-party packages or PHP extensions.
            if ($file === null || str_contains(str_replace('\\', '/', $file), '/vendor/')) {
                continue;
            }

            $found = $this->walk($dependency, $path, $explored);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function isScoped(ClassReflection $class): bool
    {
        // Request subclasses have a request lifetime even without #[Scoped].
        return $class->is(Request::class) || $this->hasAttribute($class, 'Illuminate\Container\Attributes\Scoped');
    }

    private function hasAttribute(ClassReflection $class, string $attribute): bool
    {
        foreach ($class->getNativeReflection()->getAttributes() as $reflectionAttribute) {
            if ($reflectionAttribute->getName() === $attribute) {
                return true;
            }
        }

        return false;
    }
}
