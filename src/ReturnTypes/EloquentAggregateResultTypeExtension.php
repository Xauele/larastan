<?php

declare(strict_types=1);

namespace Larastan\Larastan\ReturnTypes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Larastan\Larastan\Support\CollectionHelper;
use Larastan\Larastan\Types\RelationOf\RelationOfType;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\FloatType;
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
 * Infer extra Eloquent aggregate properties only at query retrieval.
 *
 * The builder's TModel must not be widened: make(), create(), and similar
 * operations also return TModel but do not select aggregate attributes.
 *
 * @internal
 */
final class EloquentAggregateResultTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const AGGREGATES = [
        'withCount' => 'count',
        'withExists' => 'exists',
        'withMin' => 'min',
        'withMax' => 'max',
        'withAvg' => 'avg',
        'withSum' => 'sum',
    ];

    public function __construct(private CollectionHelper $collectionHelper)
    {
    }

    public function getClass(): string
    {
        return Builder::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['first', 'firstOrFail', 'sole', 'get'], true);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type|null
    {
        $builder = $scope->getType($methodCall->var);

        if (! (new ObjectType(Builder::class))->isSuperTypeOf($builder)->yes()) {
            return null;
        }

        $modelType = $builder->getTemplateType(Builder::class, 'TModel');
        $models    = $modelType->getObjectClassNames();

        if (count($models) !== 1 || ! (new ObjectType(Model::class))->isSuperTypeOf($modelType)->yes()) {
            return null;
        }

        $attributes = [];
        $query      = $methodCall->var;

        // Analyze the syntactic query chain without mutating Builder<TModel>.
        // Properties become available only on hydrated query results.
        while ($query instanceof MethodCall) {
            if ($query->name instanceof Identifier && array_key_exists($query->name->name, self::AGGREGATES)) {
                foreach ($this->resolveAggregateProperties($query, $modelType, $scope) as $name => $type) {
                    $attributes[$name] = $type;
                }
            }

            $query = $query->var;
        }

        if ($query instanceof StaticCall && $query->name instanceof Identifier && array_key_exists($query->name->name, self::AGGREGATES)) {
            foreach ($this->resolveAggregateProperties($query, $modelType, $scope) as $name => $type) {
                $attributes[$name] = $type;
            }
        }

        if ($attributes === []) {
            return null;
        }

        $resultModel = TypeCombinator::intersect($modelType, new ObjectShapeType($attributes, []));

        return match ($methodReflection->getName()) {
            'get' => $this->collectionHelper->determineCollectionClass($models[0], $resultModel),
            'first' => TypeCombinator::addNull($resultModel),
            default => $resultModel,
        };
    }

    /** @return array<string, Type> */
    private function resolveAggregateProperties(MethodCall|StaticCall $call, Type $modelType, Scope $scope): array
    {
        if (! $call->name instanceof Identifier) {
            return [];
        }

        $arguments = [];

        foreach ($call->getArgs() as $position => $argument) {
            if ($argument->unpack) {
                return [];
            }

            $arguments[$argument->name?->toString() ?? $position] = $scope->getType($argument->value);
        }

        $relationType = $arguments['relations'] ?? $arguments['relation'] ?? $arguments[0] ?? null;

        if ($relationType === null) {
            return [];
        }

        $operation = self::AGGREGATES[$call->name->toString()];
        $column    = '*';

        if (! in_array($operation, ['count', 'exists'], true)) {
            $columnType = $arguments['column'] ?? $arguments[1] ?? null;

            if ($columnType === null || count($columnType->getConstantStrings()) !== 1) {
                return [];
            }

            $column = $columnType->getConstantStrings()[0]->getValue();
        }

        $relations = $this->constantRelationNames($relationType);

        if ($relations === null) {
            return [];
        }

        // Laravel supports the withCount('posts', 'accounts') spelling.
        if ($operation === 'count' && $relationType->isString()->yes()) {
            foreach ($arguments as $position => $argumentType) {
                if (! is_int($position) || $position === 0) {
                    continue;
                }

                $otherNames = $this->constantRelationNames($argumentType);

                if ($otherNames === null) {
                    return [];
                }

                foreach ($otherNames as $name) {
                    $relations[] = $name;
                }
            }
        }

        $properties = [];

        foreach ($relations as $rawName) {
            $segments = explode(' ', $rawName);
            $name     = $rawName;
            $alias    = null;

            if (count($segments) === 3 && strtolower($segments[1]) === 'as') {
                [$name, , $alias] = $segments;
            }

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

        return $properties;
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
