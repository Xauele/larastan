<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonAttributeScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function class_exists;

/** @extends RuleTestCase<SingletonAttributeScopeRule> */
final class SingletonAttributeScopeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonAttributeScopeRule(new ScopedDependencyDetector());
    }

    public function testNativeContainerAttributes(): void
    {
        if (! class_exists('Illuminate\Container\Attributes\Scoped') || ! class_exists('Illuminate\Container\Attributes\Singleton')) {
            self::markTestSkipped('Native lifetime attributes are not available in Laravel 11.');
        }

        $this->analyse([__DIR__ . '/data/CaptiveScopeAttributes.php'], [
            ['Singleton ScopeAttributeCompatibility\\BadDirectSingleton captures a scoped dependency: ScopeAttributeCompatibility\\BadDirectSingleton -> ScopeAttributeCompatibility\\TenantContext.', 26, 'Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.'],
            ['Singleton ScopeAttributeCompatibility\\BadIndirectSingleton captures a scoped dependency: ScopeAttributeCompatibility\\BadIndirectSingleton -> ScopeAttributeCompatibility\\Repository -> ScopeAttributeCompatibility\\TenantContext.', 34, 'Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.'],
            ['Singleton ScopeAttributeCompatibility\\BadRequestSingleton captures a scoped dependency: ScopeAttributeCompatibility\\BadRequestSingleton -> Illuminate\\Http\\Request.', 42, 'Remove #[Singleton] or pass request-scoped information at call time instead of retaining it.'],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
