<?php

declare(strict_types=1);

namespace Tests\Type;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class ModelCastsRuntimeCompatibilityTest extends TestCase
{
    public function testCastsMethodOverridesOlderPropertyEntry(): void
    {
        // Laravel executes casts() at initialization; Larastan does not.
        $model = new DynamicCastPrecedenceModel();

        self::assertSame('string', $model->getCasts()['integer']);
    }
}

final class DynamicCastPrecedenceModel extends Model
{
    protected $casts = ['integer' => 'bool'];

    protected function casts(): array
    {
        return ['integer' => $this->selectCast()];
    }

    private function selectCast(): string
    {
        return 'string';
    }
}
