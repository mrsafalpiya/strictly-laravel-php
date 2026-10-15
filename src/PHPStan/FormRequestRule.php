<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/**
 * Problem P-112: a controller action takes an input class, never a FormRequest. A FormRequest validates and reads keys
 * no type describes: a typed form sends only the fields of its action's input class, so it has none for this action,
 * and the input Contracts only check a form endpoint's keys. A vendor action's FormRequest is declared in
 * `config/strictly.php` instead (P-025); vendor code isn't analysed.
 *
 * @implements Rule<ClassMethod>
 */
final readonly class FormRequestRule implements Rule
{
    private const string FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $controller = ControllerAction::of($node, $scope);

        if ($controller === null) {
            return [];
        }

        $errors = [];

        foreach ($node->params as $param) {
            $type = $param->type instanceof NullableType ? $param->type->type : $param->type;

            if (! $type instanceof Name || ! $this->reflection->hasClass($scope->resolveName($type))) {
                continue;
            }

            $request = $this->reflection->getClass($scope->resolveName($type));

            if (! $request->isSubclassOf(self::FORM_REQUEST)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s::%s() takes %s, a FormRequest, where nothing types its payload: a typed form sends only the fields of its action\'s input class.',
                $controller->getName(), $node->name->toString(), $request->getName(),
            ))
                ->identifier('strictly.formRequest')
                ->line($param->getStartLine())
                ->tip(sprintf(
                    'Take an input class instead (a final Data class extending %s, with the FormRequest\'s rules() and authorize()): it types the route\'s form and its validation errors. A GET\'s query string goes in a query class (extending %s) instead.',
                    InputData::class, QueryData::class,
                ))
                ->build();
        }

        return $errors;
    }
}
