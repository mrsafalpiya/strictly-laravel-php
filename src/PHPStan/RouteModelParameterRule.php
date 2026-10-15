<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Contracts\Routing\UrlRoutable;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;
use Spatie\LaravelData\Contracts\BaseData;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/**
 * Problem P-126: only an InputData's `authorize()` and `rules()` receive the route's bound models for their
 * model-typed parameters (Workarounds\LaravelData\RouteModelArguments). laravel-data calls any other Data class's
 * through the container, so `rules(Comment $comment)` on a query class or a plain Data class gets a new, empty Comment
 * and checks the wrong object without a word. Reported on the class that declares the method.
 *
 * @implements Rule<InClassNode>
 */
final class RouteModelParameterRule implements Rule
{
    private const array METHODS = ['authorize', 'rules'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $data = $node->getClassReflection();

        if (! $data->implementsInterface(BaseData::class) || $data->isSubclassOf(InputData::class)) {
            return [];
        }

        $errors = [];

        foreach (self::METHODS as $name) {
            if (! $data->hasNativeMethod($name) || $data->getNativeMethod($name)->getDeclaringClass()->getName() !== $data->getName()) {
                continue;
            }

            foreach ($data->getNativeMethod($name)->getVariants()[0]->getParameters() as $parameter) {
                foreach (TypeCombinator::removeNull($parameter->getType())->getObjectClassReflections() as $model) {
                    if (! $this->isModel($model)) {
                        continue;
                    }

                    $errors[] = RuleErrorBuilder::message(sprintf(
                        "%s::%s() takes %s \$%s, which is a new, empty %s there: only an InputData's authorize() and rules() receive the route's bound models.",
                        $data->getName(), $name, $model->getName(), $parameter->getName(), $model->getNativeReflection()->getShortName(),
                    ))
                        ->identifier('strictly.routeModelParameter')
                        ->line($node->getOriginalNode()->getMethod($name)?->getStartLine() ?? $node->getOriginalNode()->getStartLine())
                        ->tip($data->isSubclassOf(QueryData::class)
                            ? 'Read the model from request()->route() and narrow it with instanceof: a query class\'s rules() get no route models.'
                            : sprintf(
                                "Make %s extend %s if it is a request's input. Otherwise read the model from request()->route() and narrow it with instanceof.",
                                $data->getName(), InputData::class,
                            ))
                        ->build();
                }
            }
        }

        return $errors;
    }

    /** A model, or any other class Laravel's implicit route binding resolves. */
    private function isModel(ClassReflection $class): bool
    {
        return $class->getName() === UrlRoutable::class || $class->implementsInterface(UrlRoutable::class);
    }
}
