<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonBindingScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<SingletonBindingScopeRule> */
final class OctaneAdversarialBindingRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonBindingScopeRule(new ScopedDependencyDetector());
    }

    public function testBindWithSharedTrueIsAlsoSingletonAndTransientControlsRemainQuiet(): void
    {
        $tip = 'Register the owner as scoped(), or resolve the current context when handling each request/job.';

        $this->analyse([__DIR__ . '/data/OctaneAdversarialNativeCases.php'], [
            ['Singleton OctaneAdversarialNativeCases\\SharedViaPositionalBind has a constructor dependency on a scoped service: OctaneAdversarialNativeCases\\SharedViaPositionalBind -> Illuminate\\Http\\Request.', 86, $tip],
            ['Singleton OctaneAdversarialNativeCases\\SharedViaNamedBind has a constructor dependency on a scoped service: OctaneAdversarialNativeCases\\SharedViaNamedBind -> Illuminate\\Http\\Request.', 85, $tip],
            ['Singleton OctaneAdversarialNativeCases\\SharedImplementation has a constructor dependency on a scoped service: OctaneAdversarialNativeCases\\SharedImplementation -> Illuminate\\Http\\Request.', 84, $tip],
            ['Singleton OctaneAdversarialNativeCases\\BaselineExplicitSingleton has a constructor dependency on a scoped service: OctaneAdversarialNativeCases\\BaselineExplicitSingleton -> Illuminate\\Http\\Request.', 89, $tip],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
