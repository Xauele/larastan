<?php

namespace ModelCastsParsing;

use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Stringable;
use function PHPStan\Testing\assertType;

function test(ModelWithCasts $model, OtherModelWithCasts $other, ConditionalModelWithCasts $conditional, DynamicValueModelWithCasts $dynamic, PartiallyDynamicModelWithCasts $partial, PhpDocUnknownCastsModel $annotated, PhpDocOptionalCastsModel $optional, SelfConstantCastsModel $self, ChildInheritedCastsModel $inherited): void
{
    assertType('bool', $model->integer);
    assertType(Stringable::class, $model->string);

    // Two casts() definitions in the same file must not be confused.
    assertType('string', $other->integer);
    assertType('int', $other->string);

    // Conditional arrays cannot be inferred safely; retain schema types.
    assertType('int', $conditional->integer);

    // casts() overrides $casts: an unresolved override is mixed, not bool.
    assertType('mixed', $dynamic->integer);
    assertType('mixed', $dynamic->toArray()['integer']);
    assertType('string', $dynamic->string);

    // Resolve known entries independently from dynamic values.
    assertType('mixed', $partial->integer);
    assertType(Stringable::class, $partial->string);

    // A PHPDoc array shape is not necessarily a literal cast value.
    assertType('mixed', $annotated->integer);

    // Optional casts() keys can override a conflicting $casts value.
    assertType('mixed', $optional->integer);

    // Evaluate class constants in the model declaration, not file scope.
    assertType('bool', $self->integer);
    assertType('bool', $inherited->integer);
}

class SelfConstantCastsModel extends Model
{
    private const BOOLEAN_CAST = 'bool';

    protected $table = 'model_with_casts';

    protected function casts(): array
    {
        return ['integer' => self::BOOLEAN_CAST];
    }
}

class BaseInheritedCastsModel extends Model
{
    protected $table = 'model_with_casts';

    protected function casts(): array
    {
        return ['integer' => 'bool'];
    }
}

class ChildInheritedCastsModel extends BaseInheritedCastsModel
{
}

class PhpDocOptionalCastsModel extends Model
{
    protected $table = 'model_with_casts';

    protected $casts = ['integer' => 'bool'];

    /** @return array{integer?: string} */
    protected function casts(): array
    {
        if ($this->getConnectionName() === 'another') {
            return ['integer' => 'string'];
        }

        return [];
    }
}

class PhpDocUnknownCastsModel extends Model
{
    protected $table = 'model_with_casts';

    /** @return array{integer: string} */
    protected function casts(): array
    {
        return ['integer' => $this->selectCast()];
    }

    private function selectCast(): string
    {
        return 'string';
    }
}

class PartiallyDynamicModelWithCasts extends Model
{
    protected $table = 'model_with_casts';

    protected $casts = ['integer' => 'bool'];

    protected function casts(): array
    {
        return [
            'integer' => $this->selectCast(),
            'string' => AsStringable::class,
        ];
    }

    private function selectCast(): string
    {
        return 'string';
    }
}

class DynamicValueModelWithCasts extends Model
{
    protected $table = 'model_with_casts';

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

class ConditionalModelWithCasts extends Model
{
    protected $table = 'model_with_casts';

    protected function casts(): array
    {
        if ($this->getConnectionName() === 'another') {
            return ['integer' => 'string'];
        }

        return ['integer' => 'bool'];
    }
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
