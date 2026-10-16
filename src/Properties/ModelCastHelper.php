<?php

declare(strict_types=1);

namespace Larastan\Larastan\Properties;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Stringable as IlluminateStringable;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use ReflectionException;
use stdClass;
use Stringable;

use function array_combine;
use function array_key_exists;
use function array_map;
use function array_merge;
use function class_exists;
use function count;
use function explode;
use function str_replace;

class ModelCastHelper
{
    // Internal marker for known cast keys with unresolved values.
    private const UNKNOWN_CAST = '__larastan_unknown_cast__';

    /** @var array<string, array<string, string>> */
    private array $modelCasts = [];

    /** @var array<string, Type> */
    private array $parsedCastsMethods = [];

    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private Parser $parser,
        private bool $parseModelCastsMethod,
        private ScopeFactory $scopeFactory,
    ) {
    }

    public function getReadableType(string $cast, Type $originalType): Type
    {
        if ($cast === self::UNKNOWN_CAST) {
            return new MixedType();
        }

        $cast = $this->parseCast($cast);

        $attributeType = match ($cast) {
            'int', 'integer', 'timestamp' => $originalType->isInteger()->yes() ? $originalType : new IntegerType(),
            'real', 'float', 'double' => new FloatType(),
            'decimal' => TypeCombinator::intersect(new StringType(), new AccessoryNumericStringType()),
            'string' => new StringType(),
            'bool', 'boolean' => new BooleanType(),
            'object' => new ObjectType(stdClass::class),
            'array', 'json' => new ArrayType(new BenevolentUnionType([new IntegerType(), new StringType()]), new MixedType()),
            'collection' => new ObjectType(Collection::class),
            'date', 'datetime' => $this->getDateType(),
            'immutable_date', 'immutable_datetime' => new ObjectType(CarbonImmutable::class),
            AsArrayObject::class, AsEncryptedArrayObject::class => new ObjectType(ArrayObject::class),
            AsCollection::class, AsEncryptedCollection::class => new BenevolentUnionType([
                new GenericObjectType(Collection::class, [new BenevolentUnionType([new IntegerType(), new StringType()]), new MixedType()]),
                new NullType(),
            ]),
            AsStringable::class => new ObjectType(IlluminateStringable::class),
            default => null,
        };

        if ($attributeType) {
            return $attributeType;
        }

        if (! $this->reflectionProvider->hasClass($cast)) {
            return $originalType;
        }

        $classReflection = $this->reflectionProvider->getClass($cast);

        if ($classReflection->isEnum()) {
            return new ObjectType($cast);
        }

        if ($classReflection->is(Castable::class)) {
            $methodReflection = $classReflection->getNativeMethod('castUsing');
            $castUsingReturn  = $methodReflection->getVariants()[0]->getReturnType();

            if ($castUsingReturn->getObjectClassReflections() !== []) {
                $classReflection = $castUsingReturn->getObjectClassReflections()[0];
            }
        }

        if ($classReflection->is(CastsAttributes::class)) {
            $methodReflection = $classReflection->getNativeMethod('get');

            return $methodReflection->getVariants()[0]->getReturnType();
        }

        if ($classReflection->is(CastsInboundAttributes::class)) {
            return $originalType;
        }

        return new MixedType();
    }

    public function getWriteableType(string $cast, Type $originalType): Type
    {
        if ($cast === self::UNKNOWN_CAST) {
            return new MixedType();
        }

        $cast = $this->parseCast($cast);

        $attributeType = match ($cast) {
            'int', 'integer', 'timestamp' => $originalType->isInteger()->yes() ? $originalType : new IntegerType(),
            'real', 'float', 'double' => new FloatType(),
            'decimal' => TypeCombinator::union(TypeCombinator::intersect(new StringType(), new AccessoryNumericStringType()), new IntegerType(), new FloatType()),
            'string' => new StringType(),
            'bool', 'boolean' => TypeCombinator::union(new BooleanType(), new ConstantIntegerType(0), new ConstantIntegerType(1)),
            'object' => new ObjectType(stdClass::class),
            'array', 'json' => new ArrayType(new BenevolentUnionType([new IntegerType(), new StringType()]), new MixedType()),
            'collection' => new ObjectType(Collection::class),
            'date', 'datetime' => $this->getDateType(),
            'immutable_date', 'immutable_datetime' => new ObjectType(CarbonImmutable::class),
            AsArrayObject::class, AsCollection::class,
            AsEncryptedArrayObject::class, AsEncryptedCollection::class => new MixedType(),
            AsStringable::class => TypeCombinator::union(new StringType(), new ObjectType(Stringable::class)),
            default => null,
        };

        if ($attributeType) {
            return $attributeType;
        }

        if (! $this->reflectionProvider->hasClass($cast)) {
            return $originalType;
        }

        $classReflection = $this->reflectionProvider->getClass($cast);

        if ($classReflection->isEnum()) {
            return new ObjectType($cast);
        }

        if ($classReflection->is(Castable::class)) {
            $methodReflection = $classReflection->getNativeMethod('castUsing');
            $castUsingReturn  = $methodReflection->getVariants()[0]->getReturnType();

            if ($castUsingReturn->getObjectClassReflections() !== []) {
                $classReflection = $castUsingReturn->getObjectClassReflections()[0];
            }
        }

        if (
            $classReflection->is(CastsAttributes::class)
            || $classReflection->is(CastsInboundAttributes::class)
        ) {
            $methodReflection = $classReflection->getNativeMethod('set');
            $parameters       = $methodReflection->getVariants()[0]->getParameters();

            $valueParameter = Arr::first($parameters, static fn (ParameterReflection $parameterReflection) => $parameterReflection->getName() === 'value');

            if ($valueParameter) {
                return $valueParameter->getType();
            }
        }

        return new MixedType();
    }

    public function getDateType(): Type
    {
        $dateClass = class_exists(Date::class)
            ? Date::now()::class
            : IlluminateCarbon::class;

        if ($dateClass === IlluminateCarbon::class) {
            return new ObjectType(Carbon::class);
        }

        return new ObjectType($dateClass);
    }

    private function parseCast(string $cast): string
    {
        foreach (explode(':', $cast) as $part) {
            // If the cast is prefixed with `encrypted:` we need to skip to the next
            if ($part === 'encrypted') {
                continue;
            }

            return $part;
        }

        return $cast;
    }

    public function hasCastForProperty(ClassReflection $modelClassReflection, string $propertyName): bool
    {
        $modelCasts = $this->getModelCasts($modelClassReflection);

        return array_key_exists($propertyName, $modelCasts);
    }

    public function getCastForProperty(ClassReflection $modelClassReflection, string $propertyName): string|null
    {
        $modelCasts = $this->getModelCasts($modelClassReflection);

        return $modelCasts[$propertyName] ?? null;
    }

    /**
     * @return array<string, string>
     *
     * @throws ShouldNotHappenException
     * @throws MissingMethodFromReflectionException
     */
    private function getModelCasts(ClassReflection $modelClassReflection): array
    {
        $className = $modelClassReflection->getName();

        if (array_key_exists($className, $this->modelCasts)) {
            return $this->modelCasts[$className];
        }

        try {
            $modelInstance = ModelHelper::newInstanceWithoutConstructor($modelClassReflection);
        } catch (ReflectionException) {
            throw new ShouldNotHappenException();
        }

        if (
            $modelClassReflection->hasTraitUse(HasUniqueStringIds::class)
            || $modelClassReflection->hasTraitUse(HasUuids::class)
            || $modelClassReflection->hasTraitUse(HasUlids::class)
        ) {
            $modelInstance->usesUniqueIds = true;
        }

        $modelCasts = $modelInstance->getCasts();

        $castsMethodReturnType = $modelClassReflection->getMethod(
            'casts',
            new OutOfClassScope(),
        )->getVariants()[0]->getReturnType();

        // Constant-array PHPDoc already provides the precise casts. Parse only
        // when native `casts(): array` gives PHPStan insufficient information.
        // Models inheriting the framework's empty casts() have nothing to parse.
        if (
            $this->parseModelCastsMethod
            && ! $castsMethodReturnType->isConstantArray()->yes()
            && $modelClassReflection->getNativeMethod('casts')->getDeclaringClass()->getName() !== Model::class
        ) {
            $parsed = $this->parseCastsMethod($modelClassReflection);

            // Laravel merges casts() OVER $casts. If an array key is known
            // but the value is dynamic, the old $casts entry is not a safe
            // fallback. Preserve precise keys; widen uncertain ones to mixed.
            if ($this->hasLiteralCasts($parsed)) {
                $castsMethodReturnType = $parsed;
            } elseif ($parsed->isConstantArray()->yes()) {
                $arrays = $parsed->getConstantArrays();

                if (count($arrays) === 1 && $arrays[0]->getOptionalKeys() === []) {
                    $keys   = $arrays[0]->getKeyTypes();
                    $values = $arrays[0]->getValueTypes();

                    foreach ($keys as $index => $key) {
                        if (! $this->isLiteralString($key)) {
                            continue;
                        }

                        $name  = $key->getConstantStrings()[0]->getValue();
                        $value = $values[$index];

                        $modelCasts[$name] = $this->isLiteralString($value)
                            ? str_replace('\\\\', '\\', $value->getConstantStrings()[0]->getValue())
                            : self::UNKNOWN_CAST;
                    }
                }
            }
        }

        if ($castsMethodReturnType->isConstantArray()->yes()) {
            $modelCasts = array_merge(
                $modelCasts,
                array_combine(
                    array_map(static fn ($key) => $key->getValue(), $castsMethodReturnType->getKeyTypes()), // @phpstan-ignore-line
                    array_map(static function (Type $value) {
                        if ($value->isConstantValue()->yes()) {
                            return str_replace('\\\\', '\\', (string) $value->getValue()); // @phpstan-ignore-line
                        }

                        return $value->describe(VerbosityLevel::value());
                    }, $castsMethodReturnType->getValueTypes()), // @phpstan-ignore-line
                ),
            );
        }

        $this->modelCasts[$className] = $modelCasts;

        return $modelCasts;
    }

    /**
     * PHPStan's constant-array type guarantees the shape, not the values.
     * Cast names must be literal strings before they can override getCasts().
     */
    private function hasLiteralCasts(Type $type): bool
    {
        if (! $type->isConstantArray()->yes()) {
            return false;
        }

        $arrays = $type->getConstantArrays();

        if (count($arrays) !== 1 || $arrays[0]->getOptionalKeys() !== []) {
            return false;
        }

        foreach ($arrays[0]->getKeyTypes() as $key) {
            if (! $this->isLiteralString($key)) {
                return false;
            }
        }

        foreach ($arrays[0]->getValueTypes() as $value) {
            if (! $this->isLiteralString($value)) {
                return false;
            }
        }

        return true;
    }

    private function isLiteralString(Type $type): bool
    {
        // Use PHPStan's native type API, not instanceof checks on Type.
        return $type->isString()->yes()
            && $type->isConstantScalarValue()->yes()
            && count($type->getConstantStrings()) === 1;
    }

    private function parseCastsMethod(ClassReflection $modelClassReflection): Type
    {
        $castsMethod    = $modelClassReflection->getNativeMethod('casts');
        $declaringClass = $castsMethod->getDeclaringClass();
        $className      = $declaringClass->getName();

        if (isset($this->parsedCastsMethods[$className])) {
            return $this->parsedCastsMethods[$className];
        }

        $fileName = $declaringClass->getFileName();

        if ($fileName === null) {
            return $this->parsedCastsMethods[$className] = new NullType();
        }

        $stmts = $this->parser->parseFile($fileName);

        // Look for the declaring class, not just the first casts() method in
        // the file. A file can define several models with different casts.
        foreach ($stmts as $statement) {
            $namespace = $statement instanceof Node\Stmt\Namespace_ ? $statement->name?->toString() : null;
            $classes   = $statement instanceof Node\Stmt\Namespace_ ? $statement->stmts : [$statement];

            foreach ($classes as $class) {
                if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
                    continue;
                }

                $candidate = $namespace === null ? $class->name->toString() : $namespace . '\\' . $class->name->toString();

                if ($candidate !== $className) {
                    continue;
                }

                foreach ($class->getMethods() as $method) {
                    if ($method->name->toString() !== $castsMethod->getName()) {
                        continue;
                    }

                    // Even if the last statement returns a literal array, an
                    // earlier branch may return different casts. Never guess.
                    $returns = (new NodeFinder())->findInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);

                    if (count($returns) !== 1) {
                        return $this->parsedCastsMethods[$className] = new NullType();
                    }

                    // Only a direct literal return is safe to infer; delegated
                    // expressions retain their declared return type.
                    foreach ($method->stmts ?? [] as $bodyStatement) {
                        if ($bodyStatement instanceof Node\Stmt\Return_ && $bodyStatement->expr instanceof Array_) {
                            $scope = $this->scopeFactory->create(ScopeContext::create($fileName));

                            return $this->parsedCastsMethods[$className] = $scope->getType($bodyStatement->expr);
                        }
                    }

                    return $this->parsedCastsMethods[$className] = new NullType();
                }
            }
        }

        return $this->parsedCastsMethods[$className] = new NullType();
    }
}
