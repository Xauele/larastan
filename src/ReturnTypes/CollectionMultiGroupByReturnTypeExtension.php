<?php

declare(strict_types=1);

namespace Larastan\Larastan\ReturnTypes;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function in_array;

/**
 * Reconstruct the nested collection structure returned by groupBy([key1, key2, ...]).
 *
 * The generic Collection::groupBy() stub cannot express a depth depending on
 * the number of array elements. Only exact, non-optional constant arrays of
 * supported selectors are analyzed; dynamic inputs retain the framework type.
 *
 * @internal
 */
final class CollectionMultiGroupByReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const MAX_GROUPS = 8;

    public function getClass(): string
    {
        return Collection::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'groupBy';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type|null
    {
        $groupBy = null;
        $preserveKeys = null;

        foreach ($methodCall->getArgs() as $index => $argument) {
            if ($argument->unpack) {
                return null;
            }

            $name = $argument->name?->name;

            if ($name === 'groupBy' || ($name === null && $index === 0)) {
                $groupBy = $argument->value;
            } elseif ($name === 'preserveKeys' || ($name === null && $index === 1)) {
                $preserveKeys = $argument->value;
            }
        }

        if ($groupBy === null) {
            return null;
        }

        $groupByType = $scope->getType($groupBy);

        if (! $groupByType instanceof ConstantArrayType || $groupByType->getOptionalKeys() !== []) {
            return null;
        }

        $groups = $groupByType->getValueTypes();
        $depth = count($groups);

        if ($depth < 2 || $depth > self::MAX_GROUPS) {
            return null;
        }

        foreach ($groups as $group) {
            if (! $group->isString()->yes() && ! $group->isCallable()->yes()) {
                return null;
            }
        }

        $receiver = $scope->getType($methodCall->var);

        if (! $receiver instanceof GenericObjectType) {
            return null;
        }

        // A custom collection may override groupBy() with different semantics.
        $collectionClass = $receiver->getClassName();

        if (! in_array($collectionClass, [Collection::class, EloquentCollection::class], true)) {
            return null;
        }

        $keyType = $receiver->getTemplateType(Collection::class, 'TKey');
        $valueType = $receiver->getTemplateType(Collection::class, 'TValue');

        if ($keyType instanceof ErrorType || $valueType instanceof ErrorType) {
            return null;
        }

        $leafKeyType = new IntegerType();

        if ($preserveKeys !== null) {
            $preserveType = $scope->getType($preserveKeys);

            if (! $preserveType->isBoolean()->yes()) {
                return null;
            }

            $constants = $preserveType->getConstantBooleans();

            if (count($constants) === 1 && $constants[0] instanceof ConstantBooleanType) {
                $leafKeyType = $constants[0]->getValue() ? $keyType : new IntegerType();
            } else {
                $leafKeyType = TypeCombinator::union(new IntegerType(), $keyType);
            }
        }

        // At runtime Eloquent::groupBy() yields Eloquent leaf collections, but
        // mapping collections instead of models converts outer levels into
        // Support collections. Use the common Support parent for those levels.
        $leafCollection = $collectionClass === EloquentCollection::class
            ? EloquentCollection::class
            : Collection::class;

        $result = new GenericObjectType($leafCollection, [$leafKeyType, $valueType]);
        $groupKeyType = new BenevolentUnionType([new IntegerType(), new StringType()]);

        for ($level = 0; $level < $depth; $level++) {
            $result = new GenericObjectType(Collection::class, [$groupKeyType, $result]);
        }

        return $result;
    }
}
