<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Octane;

use Illuminate\Contracts\Container\Container;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function count;
use function implode;
use function in_array;
use function strtolower;

/**
 * Detects an explicitly registered singleton with a constructor dependency
 * on a request/job-scoped object, including through known application classes.
 *
 * @implements Rule<MethodCall>
 */
final class SingletonBindingScopeRule implements Rule
{
    public function __construct(private ScopedDependencyDetector $detector)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier || $node->isFirstClassCallable()) {
            return [];
        }

        $registrationMethod = strtolower($node->name->name);

        if (! in_array($registrationMethod, ['singleton', 'singletonif', 'bind', 'bindif'], true)) {
            return [];
        }

        // bind() / bindIf() share the same singleton lifetime only when
        // their third argument is a definite literal true. A dynamic flag
        // is deliberately not inferred as true.
        if (in_array($registrationMethod, ['bind', 'bindif'], true) && ! $this->isExplicitlyShared($node->getArgs())) {
            return [];
        }

        if (! (new ObjectType(Container::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $implementation = $this->implementation($node->getArgs());

        if ($implementation === null) {
            return [];
        }

        $class = $this->concreteClass($implementation, $scope);

        if ($class === null || $class->isInterface() || $class->isAbstract() || $this->detector->isSingleton($class)) {
            // #[Singleton] implementations are checked at their constructor;
            // avoid duplicate diagnostics when also registered explicitly.
            return [];
        }

        $path = $this->detector->findCaptiveDependency($class);

        if ($path === null) {
            return [];
        }

        $message = 'Singleton ' . $class->getName() . ' has a constructor dependency on a scoped service: ' . implode(' -> ', $path) . '.';
        $error   = RuleErrorBuilder::message($message)
            ->identifier('larastan.octaneCaptiveScopedDependency')
            ->tip('Register the owner as scoped(), or resolve the current context when handling each request/job.')
            ->build();

        return [$error];
    }

    /**
     * Analyze positional or named 'shared' without executing any user code.
     *
     * @param array<int, Arg> $args
     */
    private function isExplicitlyShared(array $args): bool
    {
        $shared = null;

        foreach ($args as $index => $arg) {
            if ($arg->unpack) {
                return false;
            }

            if ($arg->name?->name === 'shared' || ($arg->name === null && $index === 2)) {
                $shared = $arg->value;
            }
        }

        return $shared instanceof Node\\Expr\\ConstFetch
            && strtolower($shared->name->toString()) === 'true';
    }

    /**
     * Only statically known class bindings are inspected. Closures, aliases,
     * dynamic arguments, and factory results are deliberately left untouched.
     *
     * @param array<int, Arg> $args
     */
    private function implementation(array $args): Expr|null
    {
        $abstract = null;
        $concrete = null;

        foreach ($args as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $name = $arg->name?->name;

            if ($name === 'abstract' || ($name === null && $index === 0)) {
                $abstract = $arg->value;
            } elseif ($name === 'concrete' || ($name === null && $index === 1)) {
                $concrete = $arg->value;
            }
        }

        if ($concrete instanceof Node\Expr\ConstFetch && strtolower($concrete->name->toString()) === 'null') {
            $concrete = null;
        }

        return $concrete ?? $abstract;
    }

    private function concreteClass(Expr $expression, Scope $scope): ClassReflection|null
    {
        if (! $expression instanceof ClassConstFetch || ! $expression->name instanceof Node\Identifier || strtolower($expression->name->name) !== 'class' || ! $expression->class instanceof Name) {
            return null;
        }

        $classes = $scope->resolveTypeByName($expression->class)->getObjectClassReflections();

        return count($classes) === 1 ? $classes[0] : null;
    }
}
