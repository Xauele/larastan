<?php

declare(strict_types=1);

namespace Tests\Type;

use PHPUnit\Framework\TestCase;

final class ModelCastsRuntimeCompatibilityTest extends TestCase
{
    public function testCastsMethodOverridesOlderPropertyEntry(): void
    {
        // Laravel executes casts() at initialization; Larastan does not.
        $model = new DynamicCastPrecedenceModel();

        self::assertSame('string', $model->getCasts()['integer']);
    }

    public function testExplicitStringCastWinsOverAutomaticTimestampHandling(): void
    {
        $model = new DynamicCastPrecedenceModel();
        $model->setRawAttributes(['created_at' => '2026-01-01 12:34:56']);

        self::assertSame('2026-01-01 12:34:56', $model->created_at);
    }
}
