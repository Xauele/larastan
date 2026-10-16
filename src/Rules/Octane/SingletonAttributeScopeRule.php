<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Octane;

use PhpParser\Node;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function implode;

/**
 * Covers Laravel's attribute-first container bindings without a service provider.
 *
 * @implements Rule<InClassMethodNode>
 */
final class SingletonAttributeScopeRule implements Rule
{
    public function __construct(private ScopedDependencyDetector $detector)
    {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof InClassMethodNode || $node->getMethodReflection()->getName() !== '__construct') {
            return [];
        }

        $class = $node->getClassReflection();

        if ($class === null || ! $this->detector->isSingleton($class)) {
            return [];
        }

        $path = $this->detector->findCaptiveDependency($class);

        if ($path === null) {
            return [];
        }

        return [RuleErrorBuilder::message('Singleton '.$class->getName().' captures a scoped dependency: '.implode(' -> ', $path).'.')
            ->identifier('larastan.octaneCaptiveScopedDependency')
            ->tip('Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.')
            ->build()];
    }
}
