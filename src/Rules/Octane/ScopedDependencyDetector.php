<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Octane;

use Illuminate\Http\Request;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\TypeCombinator;

use function count;
use function str_contains;
use function str_replace;

/**
 * Conservative counterpart to .NET DI scope validation.
 *
 * Only required constructor-injected object dependencies with an
 * unambiguous class type are followed, including nullable required classes. Runtime factories, interface bindings
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
        /** @var list<array{class: ClassReflection, path: non-empty-list<string>}> $queue */
        $queue = [['class' => $class, 'path' => [$class->getName()]]];

        /** @var array<string, true> $seen */
        $seen = [$class->getName() => true];

        // Breadth-first traversal gives the shortest statically known chain.
        // Unlike a depth-first global visited set, a class cannot be visited
        // first through a longer path and hide a shorter valid dependency.
        // Distinct visited classes and path length are strictly bounded.
        for ($index = 0; $index < count($queue); $index++) {
            $current = $queue[$index]['class'];
            $path    = $queue[$index]['path'];

            // The root is a singleton by definition of this validation.
            // An explicitly bound singleton may also carry #[Scoped];
            // inspect its dependencies, never report the root as captive.
            if (count($path) >= self::MAX_DEPTH || ! $current->hasNativeMethod('__construct')) {
                continue;
            }

            $variants = $current->getNativeMethod('__construct')->getVariants();

            if (count($variants) !== 1) {
                continue;
            }

            foreach ($variants[0]->getParameters() as $parameter) {
                // An optional class uses its default only when it is not bound
                // to the container. Laravel binds the current HTTP Request on
                // each request, so even ?Request $request = null is injected.
                // Other optional class bindings can be dynamic: skip those.
                if ($parameter->isVariadic()) {
                    continue;
                }

                $type = TypeCombinator::removeNull($parameter->getType());

                if (! $type->isObject()->yes()) {
                    continue;
                }

                $dependencies = $type->getObjectClassReflections();

                if (count($dependencies) !== 1) {
                    continue;
                }

                $dependency = $dependencies[0];

                // The base HTTP Request is always bound in an Octane HTTP
                // worker; ordinary optional services may use their defaults.
                if ($parameter->isOptional() && $dependency->getName() !== Request::class) {
                    continue;
                }

                if ($this->isScoped($dependency)) {
                    return [...$path, $dependency->getName()];
                }

                if ($dependency->isInterface() || $dependency->isAbstract()) {
                    continue;
                }

                $file = $dependency->getFileName();

                // Do not guess transitive dependencies through vendor code
                // or PHP extensions.
                if ($file === null || str_contains(str_replace('\\', '/', $file), '/vendor/')) {
                    continue;
                }

                $name = $dependency->getName();

                if (isset($seen[$name]) || count($seen) >= self::MAX_CLASSES) {
                    continue;
                }

                $seen[$name] = true;
                $queue[]     = ['class' => $dependency, 'path' => [...$path, $name]];
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
