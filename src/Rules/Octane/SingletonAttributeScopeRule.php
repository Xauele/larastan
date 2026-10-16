<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Octane;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function implode;

/**
 * Covers Laravel's attribute-first container bindings without a service provider.
 *
 * @implements Rule<InClassNode>
 */
final class SingletonAttributeScopeRule implements Rule
{
    public function __construct(private ScopedDependencyDetector $detector)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $this->detector->isSingleton($class)) {
            return [];
        }

        $path = $this->detector->findCaptiveDependency($class);

        if ($path === null) {
            return [];
        }

        $message = 'Singleton ' . $class->getName() . ' captures a scoped dependency: ' . implode(' -> ', $path) . '.';
        $error   = RuleErrorBuilder::message($message)
            ->identifier('larastan.octaneCaptiveScopedDependency')
            ->tip('Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.')
            ->build();

        return [$error];
    }
}
