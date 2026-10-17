<?php

declare(strict_types=1);

namespace Larastan\Larastan\ReturnTypes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Larastan\Larastan\Types\RelationOf\RelationOfType;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_key_exists;
use function count;
use function explode;
use function in_array;
use function is_int;
use function preg_replace;
use function sprintf;
use function strtolower;

/**
 * Augments an Eloquent builder's model type with attributes actually selected
 * by statically known relationship aggregate calls. Nothing is added globally
 * to the model's class reflection.
 *
 * @internal
 */
final class EloquentAggregateModelPropertiesExtension implements DynamicMethodReturnTypeExtension
{
    private const METHODS = [
        'withCount' => 'count',
        'withExists' => 'exists',
        'withMin' => 'min',
        'withMax' => 'max',
        'withAvg' => 'avg',
        'withSum' => 'sum',
    ];

    public function getClass(): string
    {
        return Builder::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return array_key_exists($methodReflection->getName(), self::METHODS);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type|null
    {
        $receiverType = $scope->getType($methodCall->var);

        // Query the type API rather than inspecting a concrete GenericObjectType:
        // intersections with accessories remain valid after earlier aggregates.
        $reflections = $receiverType->getObjectClassReflections();

        if (count($reflections) !== 1 || ! $reflections[0]->is(Builder::class) || $reflections[0]->getActiveTemplateTypeMap()->count() !== 1) {
            return null;
        }

        $builderReflection = $reflections[0];
        $modelType         = $receiverType->getTemplateType(Builder::class, 'TModel');

        if (! (new ObjectType(Model::class))->isSuperTypeOf($modelType)->yes()) {
            return null;
        }

        $arguments = [];

        foreach ($methodCall->getArgs() as $index => $argument) {
            if ($argument->unpack) {
                return null;
            }

            $arguments[$argument->name?->toString() ?? $index] = $scope->getType($argument->value);
        }

        $relations = $arguments['relations'] ?? $arguments['relation'] ?? $arguments[0] ?? null;

        if ($relations === null) {
            return null;
        }

        $operation = self::METHODS[$methodReflection->getName()];
        $column    = '*';

        if (! in_array($operation, ['count', 'exists'], true)) {
            $columnType = $arguments['column'] ?? $arguments[1] ?? null;

            if ($columnType === null || count($columnType->getConstantStrings()) !== 1) {
                return null;
            }

            $column = $columnType->getConstantStrings()[0]->getValue();
        }

        $relationNames = $this->constantRelationNames($relations);

        if ($relationNames === null) {
            return null;
        }

        // withCount('posts', 'accounts') is variadic only for scalar strings.
        if ($operation === 'count' && $relations->isString()->yes()) {
            foreach ($arguments as $position => $argumentType) {
                if (! is_int($position) || $position === 0) {
                    continue;
                }

                $otherNames = $this->constantRelationNames($argumentType);

                if ($otherNames === null) {
                    return null;
                }

                foreach ($otherNames as $name) {
                    $relationNames[] = $name;
                }
            }
        }

        $properties = [];

        foreach ($relationNames as $rawName) {
            $segments = explode(' ', $rawName);
            $name     = $rawName;
            $alias    = null;

            if (count($segments) === 3 && strtolower($segments[1]) === 'as') {
                [$name, , $alias] = $segments;
            }

            // Laravel aggregates invoke this relation directly; no eager-load
            // dotted paths, column selections, or malformed alias syntax.
            if ($name === '' || $name !== explode('.', $name, 2)[0] || $name !== explode(':', $name, 2)[0] || preg_replace('/\s/', '', $name) !== $name) {
                continue;
            }

            if ((new RelationOfType($modelType, new ConstantStringType($name)))->resolveRelationType() === null) {
                continue;
            }

            $defaultName = preg_replace(
                '/[^[:alnum:][:space:]_]/u',
                '',
                sprintf('%s %s %s', $name, $operation, strtolower($column)),
            );

            if ($defaultName === null) {
                continue;
            }

            $property = $alias ?? Str::snake($defaultName);

            if ($property === '') {
                continue;
            }

            $properties[$property] = match ($operation) {
                'count' => IntegerRangeType::createAllGreaterThanOrEqualTo(0),
                'exists' => new BooleanType(),
                default => TypeCombinator::union(new IntegerType(), new FloatType(), new StringType(), new NullType()),
            };
        }

        if ($properties === []) {
            return null;
        }

        $enriched = TypeCombinator::intersect($modelType, new ObjectShapeType($properties, []));

        return new GenericObjectType(
            $builderReflection->getName(),
            [$enriched],
            null,
            null,
            [],
        );
    }

    /** @return list<string>|null */
    private function constantRelationNames(Type $relations): array|null
    {
        $strings = $relations->getConstantStrings();

        if (count($strings) === 1) {
            return [$strings[0]->getValue()];
        }

        $arrays = $relations->getConstantArrays();

        if (count($arrays) !== 1) {
            return null;
        }

        $names  = [];
        $values = $arrays[0]->getValueTypes();

        foreach ($arrays[0]->getKeyTypes() as $index => $key) {
            $nameType  = $key->isString()->yes() ? $key : $values[$index];
            $constants = $nameType->getConstantStrings();

            if (count($constants) !== 1) {
                return null;
            }

            $names[] = $constants[0]->getValue();
        }

        return $names;
    }
}
