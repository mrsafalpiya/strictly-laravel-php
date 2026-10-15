<?php

namespace StrictlyLaravel\PHPStan;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use StrictlyLaravel\Translations\Translations;

/**
 * Problem P-137: a translation call in PHP (`__()`, `trans()`, `trans_choice()`, `Lang::get()`, `Lang::choice()` and
 * the Translator's `get()` and `choice()`) is checked as the client's `t()` and `choice()` are (Problems P-055, P-056,
 * P-136), against the default locale's messages and Laravel's own (TranslationCatalog):
 *
 * - its key is defined (`strictly.translationKey`). Text that no lang file defines and that isn't shaped like a
 *   `group.item` key (`__('Profile updated.')`) renders itself, as Laravel's translator does, so it is accepted
 *   (Problem P-163). A package's namespaced key (`billing::invoices.title`) isn't read, so it isn't checked;
 * - its replacements are exactly the message's `:params` (`strictly.translationParams`), named as the translator
 *   fills them (`:key`, `:Key` and `:KEY` by `key`). `trans_choice()` fills `:count` from the number, so it may be
 *   left out, and a closure replacement fills a `<tag>`, not a `:param`;
 * - a pluralised message (Translations::isPlural()) goes through a choice call, which picks its form, and any other
 *   message through a get call, which renders the message whole (`strictly.translationPlural`);
 * - a key or replacements PHPStan can't resolve to literals (a union of literals is fine) can't be checked, so the call
 *   is reported until a suppression gives the reason it is right (`strictly.translationDynamic`, ADR-0007), just as
 *   `t()` takes no `string` key.
 *
 * A call's result depends on the lang files, so the file is re-analysed when they change, even from the result cache.
 *
 * @implements Rule<CallLike>
 */
final class TranslationRule implements Rule
{
    /** The translation functions, and whether each picks a pluralised message's form. */
    private const array FUNCTIONS = ['__' => false, 'trans' => false, 'trans_choice' => true];

    /** The Lang facade, by its class and its global alias. */
    private const array LANG_FACADE = ['Illuminate\Support\Facades\Lang', 'Lang'];

    private const string TRANSLATOR = 'Illuminate\Contracts\Translation\Translator';

    /** A key shaped like a lang file's (`projects.flash.saved`, `admin/users.title`), as opposed to text. */
    private const string GROUP_KEY = '/^[^\s.]+(\.[^\s.]+)+$/';

    public function __construct(private readonly TranslationCatalog $catalog) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = $this->translationCall($node, $scope);

        if ($call === null || $node->isFirstClassCallable()) {
            return [];
        }

        [$function, $choice] = $call;
        $alternative = $this->alternative($function, $choice);
        $arguments = $this->arguments($node, $choice);

        if ($arguments === null) {
            return [];
        }

        [$keyExpr, $replaceExpr] = $arguments;
        $keyType = $scope->getType($keyExpr);
        $keys = array_map(fn (ConstantStringType $key): string => $key->getValue(), $keyType->getConstantStrings());

        if ($keys === []) {
            return [
                RuleErrorBuilder::message("{$function}() is passed a translation key built at runtime.")
                    ->identifier('strictly.translationDynamic')
                    ->tip('Its key and params can\'t be checked. Pass a literal key, or a union of them such as a match over an enum, or suppress with @phpstan-ignore strictly.translationDynamic (why every key it builds exists).')
                    ->build(),
            ];
        }

        $this->dependOnLangFiles($scope);
        $given = $this->givenParams($replaceExpr, $scope);
        $messages = $this->catalog->messages();
        $locale = $this->catalog->defaultLocale();
        $errors = [];

        if ($given === null) {
            $label = count($keys) === 1 ? "{$function}('{$keys[0]}')" : "{$function}()";
            $errors[] = RuleErrorBuilder::message("{$label} is passed params built at runtime.")
                ->identifier('strictly.translationDynamic')
                ->tip('They can\'t be checked against its message\'s :params. Pass an array literal, or suppress with @phpstan-ignore strictly.translationDynamic (why they match).')
                ->build();
        }

        foreach ($keys as $key) {
            // A package's namespaced key: its lang files aren't read.
            if (str_contains($key, '::')) {
                continue;
            }

            $message = $messages[$key] ?? null;

            if ($message === null && isset($this->catalog->groups()[$key])) {
                // __() returns the group's messages as an array; trans_choice() has no one message to pick a form of.
                if ($choice) {
                    $errors[] = RuleErrorBuilder::message("Translation key '{$key}' names a group of messages, not one.")
                        ->identifier('strictly.translationKey')
                        ->tip("{$function}() picks a form of one message: name a key inside the group.")
                        ->build();
                }

                continue;
            }

            if ($message === null) {
                if (preg_match(self::GROUP_KEY, $key) === 1) {
                    $errors[] = RuleErrorBuilder::message("Translation key '{$key}' isn't defined in locale {$locale}.")
                        ->identifier('strictly.translationKey')
                        ->tip("Add it to lang/{$locale}/<group>.php or lang/{$locale}.json, or fix the key.")
                        ->build();

                    continue;
                }

                // Text no lang file defines: the translator renders it as it is.
                $message = $key;
            }

            if (Translations::isPlural($message) && ! $choice) {
                $errors[] = RuleErrorBuilder::message("Translation '{$key}' is pluralised: use {$alternative}().")
                    ->identifier('strictly.translationPlural')
                    ->tip("{$function}() renders every form of it, separated by |.")
                    ->build();
            } elseif (! Translations::isPlural($message) && $choice) {
                $errors[] = RuleErrorBuilder::message("Translation '{$key}' isn't pluralised: use {$alternative}().")
                    ->identifier('strictly.translationPlural')
                    ->tip("It has no | forms for {$function}() to pick from.")
                    ->build();
            }

            foreach ($given ?? [] as $params) {
                array_push($errors, ...$this->paramErrors($key, $message, $params, $function, $choice));
            }
        }

        // A union of replacement arrays reports a param each of them lacks once.
        $unique = [];

        foreach ($errors as $error) {
            $unique[$error->getMessage()] ??= $error;
        }

        return array_values($unique);
    }

    /**
     * The function's name as the messages show it, and whether it picks a pluralised message's form; null for any
     * other call.
     *
     * @return array{string, bool}|null
     */
    private function translationCall(Node $node, Scope $scope): ?array
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $name = $node->name->toLowerString();

            return array_key_exists($name, self::FUNCTIONS) ? [$name, self::FUNCTIONS[$name]] : null;
        }

        if (! ($node instanceof StaticCall || $node instanceof MethodCall) || ! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toLowerString();

        if ($method !== 'get' && $method !== 'choice') {
            return null;
        }

        if ($node instanceof StaticCall) {
            return $node->class instanceof Name && in_array($scope->resolveName($node->class), self::LANG_FACADE, true)
                ? ["Lang::{$method}", $method === 'choice']
                : null;
        }

        return (new ObjectType(self::TRANSLATOR))->isSuperTypeOf($scope->getType($node->var))->yes()
            ? ["Translator::{$method}", $method === 'choice']
            : null;
    }

    /** The call that renders the other kind of message: trans_choice() for __(), Lang::get() for Lang::choice(). */
    private function alternative(string $function, bool $choice): string
    {
        return match (true) {
            str_contains($function, '::') => str_replace($choice ? '::choice' : '::get', $choice ? '::get' : '::choice', $function),
            default => $choice ? '__' : 'trans_choice',
        };
    }

    /**
     * The key and replacement arguments (`__($key, $replace)`, `trans_choice($key, $number, $replace)`), by position
     * or name; null when the call unpacks its arguments.
     *
     * @return array{Expr, Expr|null}|null
     */
    private function arguments(CallLike $call, bool $choice): ?array
    {
        $key = null;
        $replace = null;
        $replacePosition = $choice ? 2 : 1;

        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $name = $arg->name?->toString();

            if ($name === 'key' || ($name === null && $position === 0)) {
                $key = $arg->value;
            } elseif ($name === 'replace' || ($name === null && $position === $replacePosition)) {
                $replace = $arg->value;
            }
        }

        return $key === null ? null : [$key, $replace];
    }

    /**
     * The names of the params each array the replacements may be passes, without the closures that fill a `<tag>`;
     * null when they aren't literal arrays.
     *
     * @return list<list<string>>|null
     */
    private function givenParams(?Expr $replace, Scope $scope): ?array
    {
        if ($replace === null) {
            return [[]];
        }

        $arrays = $scope->getType($replace)->getConstantArrays();

        if ($arrays === []) {
            return null;
        }

        $closure = new ObjectType(Closure::class);
        $given = [];

        foreach ($arrays as $array) {
            $params = [];

            foreach ($array->getKeyTypes() as $index => $keyType) {
                if (! $closure->isSuperTypeOf($array->getValueTypes()[$index])->yes()) {
                    $params[] = (string) $keyType->getValue();
                }
            }

            $given[] = $params;
        }

        return $given;
    }

    /**
     * @param  list<string>  $given
     * @return list<IdentifierRuleError>
     */
    private function paramErrors(string $key, string $message, array $given, string $function, bool $choice): array
    {
        $expected = Translations::params($message);
        $errors = [];
        $tip = $this->paramsTip($expected, $function, $choice);

        foreach (array_diff($expected, $given, $choice ? ['count'] : []) as $param) {
            $errors[] = RuleErrorBuilder::message("Translation '{$key}' needs the param '{$param}'.")
                ->identifier('strictly.translationParams')
                ->tip($tip)
                ->build();
        }

        foreach (array_diff($given, $expected) as $param) {
            $errors[] = RuleErrorBuilder::message("Translation '{$key}' takes no param '{$param}'.")
                ->identifier('strictly.translationParams')
                ->tip($tip)
                ->build();
        }

        return $errors;
    }

    /**
     * @param  list<string>  $expected
     */
    private function paramsTip(array $expected, string $function, bool $choice): string
    {
        if ($expected === []) {
            return 'Its message takes no params.';
        }

        $placeholders = array_map(fn (string $param): string => ":{$param}", $expected);
        $last = array_pop($placeholders);
        $list = $placeholders === [] ? $last : implode(', ', $placeholders)." and {$last}";
        $count = $choice && in_array('count', $expected, true) ? "; {$function}() fills :count from the number" : '';

        return "Its message takes {$list}{$count}.";
    }

    /** Re-analyse the file when the default locale's lang files change, even when the code didn't. */
    private function dependOnLangFiles(Scope $scope): void
    {
        if ($scope instanceof DependencyTracker) {
            $langPath = $this->catalog->langPath();
            $locale = $this->catalog->defaultLocale();
            $scope->trackDirectoryDependency("{$langPath}/{$locale}", '*.php');
            $scope->trackFileDependency("{$langPath}/{$locale}.json");
        }
    }
}
