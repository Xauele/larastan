<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonBindingScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function class_exists;

/** @extends RuleTestCase<SingletonBindingScopeRule> */
final class OctaneBindingRegistrationRegressionTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonBindingScopeRule(new ScopedDependencyDetector());
    }

    public function testNativeScopedRegistrationAndExplicitSingletonOverride(): void
    {
        if (! class_exists('Illuminate\\Container\\Attributes\\Scoped')) {
            self::markTestSkipped('Native lifetime attributes are not available in Laravel 11.');
        }

        $this->analyse([__DIR__ . '/data/CaptiveScopeRegistrationOverrides.php'], [
            [
                'Singleton ScopeCompatibilityRegistrationOverrides\\PlainCapturingSingleton has a constructor dependency on a scoped service: ScopeCompatibilityRegistrationOverrides\\PlainCapturingSingleton -> ScopeCompatibilityRegistrationOverrides\\PlainContext.',
                34,
                'Register the owner as scoped(), or resolve the current context when handling each request/job.',
            ],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
