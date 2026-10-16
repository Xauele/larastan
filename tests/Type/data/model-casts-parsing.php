<?php

namespace ModelCastsParsing;

use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Stringable;
use function PHPStan\Testing\assertType;

function test(ModelWithCasts $model, OtherModelWithCasts $other): void
{
    assertType('bool', $model->integer);
    assertType(Stringable::class, $model->string);

    // Two casts() definitions in the same file must not be confused.
    assertType('string', $other->integer);
    assertType('int', $other->string);
}

class OtherModelWithCasts extends Model
{
    protected $table = 'model_with_casts';

    protected function casts(): array
    {
        return [
            'integer' => 'string',
            'string' => 'integer',
        ];
    }
}

class ModelWithCasts extends Model
{
    public function foo(): int
    {
        return 5;
    }

    public function casts(): array
    {
        return [
            'integer' => 'bool',
            'string' => AsStringable::class.':argument',
        ];
    }
}
