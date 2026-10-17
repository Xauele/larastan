<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonAttributeScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function class_exists;

/** @extends RuleTestCase<SingletonAttributeScopeRule> */
final class OctaneAdversarialAttributeCaseRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonAttributeScopeRule(new ScopedDependencyDetector());
    }

    public function testMixedCaseNativeLifetimeAttributesHaveIdenticalSemantics(): void
    {
        if (! class_exists('Illuminate\\Container\\Attributes\\Scoped') || ! class_exists('Illuminate\\Container\\Attributes\\Singleton')) {
            self::markTestSkipped('Native scoped/singleton attributes require Laravel 12+.');
        }

        $tip = 'Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.';

        // The imported mixed-case attributes also produce PHPStan's
        // class.nameCase diagnostics; cover their exact real-CLI outcomes
        // separately in compare_octane_cli.py instead of pretending this
        // isolated RuleTestCase is a full PHPStan integration.
        $this->analyse([__DIR__ . '/data/OctaneAdversarialNativeCases.php'], [
            ['Singleton OctaneAdversarialNativeCases\\BaselineSingleton has a constructor dependency on a scoped service: OctaneAdversarialNativeCases\\BaselineSingleton -> OctaneAdversarialNativeCases\\StandardScopedContext.', 38, $tip],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
