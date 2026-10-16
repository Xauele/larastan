<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonBindingScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<SingletonBindingScopeRule> */
final class SingletonBindingScopeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonBindingScopeRule(new ScopedDependencyDetector());
    }

    public function testNativeServiceContainerLifetimes(): void
    {
        if (! class_exists('Illuminate\Container\Attributes\Scoped') || ! class_exists('Illuminate\Container\Attributes\Singleton')) {
            self::markTestSkipped('Native lifetime attributes are not available in Laravel 11.');
        }
        $this->analyse([__DIR__.'/data/CaptiveScopeBindings.php'], [
            ['Singleton ScopeCompatibility\\ReportService captures a scoped dependency: ScopeCompatibility\\ReportService -> ScopeCompatibility\\Repository -> ScopeCompatibility\\TenantContext.', 81, 'Register the owner as scoped(), or resolve the current context when handling each request/job.'],
            ['Singleton ScopeCompatibility\\CurrentRequestService captures a scoped dependency: ScopeCompatibility\\CurrentRequestService -> Illuminate\\Http\\Request.', 82, 'Register the owner as scoped(), or resolve the current context when handling each request/job.'],
            ['Singleton ScopeCompatibility\\ReportImplementation captures a scoped dependency: ScopeCompatibility\\ReportImplementation -> ScopeCompatibility\\TenantContext.', 83, 'Register the owner as scoped(), or resolve the current context when handling each request/job.'],
            ['Singleton ScopeCompatibility\\ReportImplementation captures a scoped dependency: ScopeCompatibility\\ReportImplementation -> ScopeCompatibility\\TenantContext.', 98, 'Register the owner as scoped(), or resolve the current context when handling each request/job.'],
        ]);
    }

    public function testRequestLifetimeDependencyOnAllSupportedLaravelVersions(): void
    {
        $this->analyse([__DIR__.'/data/CaptiveScopeRequest.php'], [
            ['Singleton ScopeCompatibilityRequest\RequestConsumer captures a scoped dependency: ScopeCompatibilityRequest\RequestConsumer -> Illuminate\Http\Request.', 19, 'Register the owner as scoped(), or resolve the current context when handling each request/job.'],
        ]);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/../phpstan-tests.neon'];
    }
}
