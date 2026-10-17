<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ContainerBindingCollector;
use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonAttributeCollector;
use Larastan\Larastan\Rules\Octane\SingletonLifetimeRule;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<SingletonLifetimeRule> */
final class OctaneFactoryRootAuditRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $provider = self::getContainer()->getByType(ReflectionProvider::class);

        return new SingletonLifetimeRule(new ScopedDependencyDetector($provider), $provider);
    }

    /** @return list<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        return [new ContainerBindingCollector(), new SingletonAttributeCollector()];
    }

    public function testFactoryDoesNotInvokeAttributedSingletonRootConstructor(): void
    {
        $this->analyse([__DIR__ . '/data/OctaneFactoryRootAudit.php'], [
            [
                'Singleton ScopeFactoryRootAudit\\RealRoot has a constructor dependency on a scoped service: ScopeFactoryRootAudit\\RealRoot -> Illuminate\\Http\\Request.',
                26,
                'Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.',
            ],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
