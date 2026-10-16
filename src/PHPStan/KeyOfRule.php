<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use ReflectionNamedType;
use Spatie\LaravelData\Attributes\Validation\Exists as ExistsAttribute;
use Spatie\LaravelData\Contracts\BaseData;
use StrictlyLaravel\Data\KeyOf;
use StrictlyLaravel\Data\RouteKeyOf;
use Throwable;

/**
 * Problem P-128: a Data property that holds a model's key declares the model, #[KeyOf(User::class)], so it is generated
 * as that model's key (`Key<'User', 'id', number>`) and another model's key, or another field of the same model, doesn't
 * fit. Without it the key is a plain number in TypeScript, and an input's `exists` rule only checks that the value is
 * a key of *some* row: the request can update the wrong rows.
 *
 * A property of an input, query or output Data class holds a key when it is named like one (`…Id`, `…Ids`, `…_id`,
 * `…_ids`) or validated by an `exists` rule (`exists:users,id`, `Rule::exists()`, laravel-data's #[Exists], in the
 * class's rules() for the property or its items). It must declare #[KeyOf] (or #[RouteKeyOf], its special case for the
 * route key name). A class's own primary key is exempt: a Data class built from a model (`fromModel(Ticket $ticket)`)
 * brands it by itself. A value that is no model's key, such as another service's id, takes a suppression with a reason.
 *
 * @implements Rule<InClassNode>
 */
final readonly class KeyOfRule implements Rule
{
    /** The rule objects `Rule::exists()` and laravel-data build. */
    private const array EXISTS_RULES = ['Illuminate\Validation\Rules\Exists', ExistsAttribute::class];

    private const string MODEL = 'Illuminate\Database\Eloquent\Model';

    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $class->isClass() || $class->isAnonymous() || ! $class->implementsInterface(BaseData::class)) {
            return [];
        }

        $validated = $this->existsValidated($node->getOriginalNode()->stmts, $scope);
        $ownKey = $this->ownPrimaryKey($class);
        $errors = [];

        foreach ($this->properties($node->getOriginalNode()->stmts) as $name => [$attributes, $line]) {
            if ($name === $ownKey || $this->declaresKey($attributes, $scope)) {
                continue;
            }

            $reason = match (true) {
                preg_match('/(Id|Ids|_id|_ids)$/', $name) === 1 => 'is named like a model\'s key',
                isset($validated[$name]) || $this->hasExistsAttribute($attributes, $scope) => 'is validated by an exists rule',
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s::$%s %s but declares no model, so TypeScript takes any value for it: another model\'s key, or another field\'s, fits.',
                $class->getName(), $name, $reason,
            ))
                ->identifier('strictly.keyOf')
                ->line($line)
                ->tip(sprintf(
                    'Declare the model it is a key of: #[KeyOf(User::class)] (%s) for its primary key, #[KeyOf(Issue::class, \'number\')] for another field. A value that is no model\'s key, such as another service\'s id, takes @phpstan-ignore strictly.keyOf (the reason).',
                    KeyOf::class,
                ))
                ->build();
        }

        return $errors;
    }

    /**
     * The class's own public properties, promoted or declared, with their attributes and line.
     *
     * @param  array<Node\Stmt>  $statements
     * @return array<string, array{list<AttributeGroup>, int}>
     */
    private function properties(array $statements): array
    {
        $properties = [];

        foreach ($statements as $statement) {
            if ($statement instanceof ClassMethod && $statement->name->toLowerString() === '__construct') {
                foreach ($statement->params as $param) {
                    if ($param->isPromoted() && $param->isPublic() && $param->var instanceof Variable && is_string($param->var->name)) {
                        $properties[$param->var->name] = [array_values($param->attrGroups), $param->getStartLine()];
                    }
                }
            }

            if ($statement instanceof Property && $statement->isPublic() && ! $statement->isStatic()) {
                foreach ($statement->props as $property) {
                    $properties[$property->name->toString()] = [array_values($statement->attrGroups), $property->getStartLine()];
                }
            }
        }

        return $properties;
    }

    /**
     * The properties whose value, or items (`ids.*`), the class's rules() validate with an `exists` rule. A nested key
     * (`tasks.*.ownerId`) belongs to the nested class.
     *
     * @param  array<Node\Stmt>  $statements
     * @return array<string, true>
     */
    private function existsValidated(array $statements, Scope $scope): array
    {
        $rules = array_find($statements, fn (Node\Stmt $statement): bool => $statement instanceof ClassMethod && $statement->isStatic() && $statement->name->toLowerString() === 'rules');
        $validated = [];

        if (! $rules instanceof ClassMethod) {
            return [];
        }

        foreach ((new NodeFinder)->findInstanceOf($rules->stmts ?? [], Return_::class) as $return) {
            if ($return->expr === null) {
                continue;
            }

            try {
                $type = $scope->getType($return->expr);
            } catch (Throwable) {
                continue; // an expression that only makes sense inside the method
            }

            foreach ($type->getConstantArrays() as $array) {
                foreach ($array->getKeyTypes() as $index => $key) {
                    $property = is_string($key->getValue()) && preg_match('/^(\w+)(\.\*)?$/', $key->getValue(), $match) === 1 ? $match[1] : null;

                    if ($property !== null && $this->containsExists($array->getValueTypes()[$index])) {
                        $validated[$property] = true;
                    }
                }
            }
        }

        return $validated;
    }

    /** Whether a property's rules (a rule, a `|`-separated string or a list of them) include an `exists` rule. */
    private function containsExists(Type $rules): bool
    {
        foreach ($rules instanceof UnionType ? $rules->getTypes() : [$rules] as $rule) {
            if ($rule->isArray()->yes() && $this->containsExists($rule->getIterableValueType())) {
                return true;
            }

            foreach ($rule->getConstantStrings() as $string) {
                if (preg_match('/(^|\|)\s*exists:/i', $string->getValue()) === 1) {
                    return true;
                }
            }

            foreach (self::EXISTS_RULES as $exists) {
                if ((new ObjectType($exists))->isSuperTypeOf($rule)->yes()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<AttributeGroup>  $groups
     */
    private function declaresKey(array $groups, Scope $scope): bool
    {
        return $this->hasAttribute($groups, $scope, [KeyOf::class, RouteKeyOf::class]);
    }

    /**
     * @param  list<AttributeGroup>  $groups
     */
    private function hasExistsAttribute(array $groups, Scope $scope): bool
    {
        return $this->hasAttribute($groups, $scope, [ExistsAttribute::class]);
    }

    /**
     * @param  list<AttributeGroup>  $groups
     * @param  list<string>  $classes
     */
    private function hasAttribute(array $groups, Scope $scope, array $classes): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array(strtolower($scope->resolveName($attribute->name)), array_map(strtolower(...), $classes), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The primary key name of the model the class is built from (the one model its static `from…()` methods take
     * first, as StrictlyLaravel\Routes\RouteKeys reads it), whose own key the class brands by itself. Null for a class
     * built from no model, or from several.
     */
    private function ownPrimaryKey(ClassReflection $class): ?string
    {
        $models = [];

        foreach ($class->getNativeReflection()->getMethods() as $method) {
            $type = $method->isStatic() && $method->isPublic() && str_starts_with($method->getName(), 'from')
                ? ($method->getParameters()[0] ?? null)?->getType()
                : null;

            if ($type instanceof ReflectionNamedType && $this->reflection->hasClass($type->getName())) {
                $model = $this->reflection->getClass($type->getName());

                if ($model->isSubclassOf(self::MODEL)) {
                    $models[$model->getName()] = $model;
                }
            }
        }

        if (count($models) !== 1) {
            return null;
        }

        $model = array_values($models)[0];
        $primaryKey = $model->getNativeReflection()->getDefaultProperties()['primaryKey'] ?? 'id';

        return is_string($primaryKey) ? $primaryKey : null;
    }
}
