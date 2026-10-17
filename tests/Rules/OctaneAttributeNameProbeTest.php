<?php

declare(strict_types=1);

namespace Tests\Rules;

use Larastan\Larastan\Rules\Octane\ScopedDependencyDetector;
use Larastan\Larastan\Rules\Octane\SingletonAttributeScopeRule;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Testing\RuleTestCase;

use function array_map;
use function count;
use function getenv;
use function implode;
use function str_contains;

/** @implements Rule<InClassNode> */
final class OctaneAttributeNameProbeRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! str_contains($class->getName(), 'OctaneAdversarialNativeCases\\')) {
            return [];
        }

        $native = array_map(static fn ($a): string => $a->getName(), $class->getNativeReflection()->getAttributes());
        $phpstan = array_map(static fn ($a): string => $a->getName(), $class->getAttributes());
        $detector = new ScopedDependencyDetector();
        $singleton = $detector->isSingleton($class) ? 'yes' : 'no';
        $cap = $detector->findCaptiveDependency($class);
        $nativeRule = new SingletonAttributeScopeRule($detector);
        $warnings = $nativeRule->processNode($node, $scope);

        return [
            RuleErrorBuilder::message(
                'ATTRIBUTE_PROBE ' . $class->getName() .
                ' native=' . implode('|', $native) .
                ' phpstan=' . implode('|', $phpstan) .
                ' singleton=' . $singleton .
                ' captured=' . ($cap === null ? 'none' : implode('>', $cap)) .
                ' ruleWarnings=' . count($warnings),
            )->build(),
        ];
    }
}

/** @extends RuleTestCase<OctaneAttributeNameProbeRule> */
final class OctaneAttributeNameProbeTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new OctaneAttributeNameProbeRule();
    }

    public function testProbeRawAttributesOnlyWhenExplicitlyRequested(): void
    {
        if (getenv('OCTANE_ATTR_PROBE') !== '1') {
            self::markTestSkipped('Diagnostic probe only; not part of normal CI.');
        }

        $this->analyse([__DIR__ . '/data/OctaneAdversarialNativeCases.php'], []);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
