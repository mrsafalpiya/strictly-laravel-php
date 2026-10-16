<?php

namespace StrictlyLaravel\PHPStan;

/**
 * The messages of the extension's rules, by identifier, `%s` standing for each part that varies. The installer reads
 * them to tell an `ignoreErrors` entry that ignores a rule by its message (P-190, Install\PhpstanIgnores). Every rule
 * test checks the messages it expects against them (tests/PHPStan/StrictlyRuleTestCase.php), so a rule whose message
 * changes fails its test until its template here changes too.
 */
final class RuleMessages
{
    /** @var array<string, list<string>> */
    public const array TEMPLATES = [
        'strictly.authorizeInInput' => [
            '%s::%s() authorizes with %s after %s was validated, so an unauthorized user gets a 422 listing the fields instead of a 403.',
        ],
        'strictly.broadcastData' => [
            'Broadcast event %s sends a payload that has no generated type.',
            '%s::broadcastWith() replaces the payload broadcastData() declares.',
            '%s::broadcastData() must declare the one Data class its payload is as its return type.',
        ],
        'strictly.broadcastsOn' => [
            'Broadcast event %s declares no channel it is sent on.',
        ],
        'strictly.closureRoute' => [
            "The closure route %s takes %s, %s, but Wayfinder types a route function's query option only from a controller action: no route function can send its keys.",
            "The closure route %s takes %s, %s, but Wayfinder types a route function's payload only from a controller action: no typed form can send it.",
            "The closure route %s returns %s, but Wayfinder types a route function's response only from a controller action: useRouteHttp() types its result unknown.",
        ],
        'strictly.dataClassFinal' => [
            'Data class %s must be final.',
        ],
        'strictly.dataNamespace' => [
            '%s %s is in the %s namespace but is not a Data class.',
        ],
        'strictly.dataShape' => [
            "%s has an array shape (%s) in its type, inside which Contract Coverage can't see.",
            "%s holds items of a union type (%s), whose members Contract Coverage can't tell apart.",
            "%s holds items that are lists or maps themselves (%s), inside which Contract Coverage can't see.",
        ],
        'strictly.formRequest' => [
            "%s::%s() takes %s, a FormRequest, where nothing types its payload: a typed form sends only the fields of its action's input class.",
        ],
        'strictly.inertiaFlash' => [
            '%s flashes data that has no generated type.',
        ],
        'strictly.inertiaRender' => [
            '%s renders a page whose props have no generated type.',
        ],
        'strictly.inputData' => [
            "%s::%s() takes %s, which is validated from the request but doesn't extend %s.",
        ],
        'strictly.inputRead' => [
            '%s::%s() reads input from the request with %s instead of from its query class, which falls back to its defaults on an invalid value: the raw value is unchecked.',
            "%s::%s() reads input from the request with %s, where nothing types it: a typed form sends only the fields of its action's input class.",
            "A closure route reads input from the request with %s, where nothing types it: a typed form sends only the fields of a controller action's input class.",
        ],
        'strictly.jsonResponse' => [
            "%s returns %s, which Laravel sends as JSON that nothing types: useRouteHttp() types the route's response unknown, and no Contract checks it.",
            "%s returns %s, a nullable or union type, which declares no response: useRouteHttp() types the route's response unknown, and no Contract checks it.",
        ],
        'strictly.keyOf' => [
            "%s::$%s %s but declares no model, so TypeScript takes any value for it: another model's key, or another field's, fits.",
        ],
        'strictly.nestedInputData' => [
            "%s::$%s nests %s, whose rules() replace the rules inferred from its types because it doesn't extend %s.",
        ],
        'strictly.presenceData' => [
            "%s returns an array or a bool, which doesn't tell a private channel from a presence one.",
            "%s returns its members' data as an array.",
            "%s returns a Data class its callback doesn't declare as its return type.",
        ],
        'strictly.queryDefault' => [
            "%s::$%s has no default, so a query string without \"%s\" can't be parsed: the request fails with a 500.",
            '%s::$%s is nullable, so it must default to null: a route function leaves a null query value out of the URL, and the server would read the default instead.',
            '%s::$%s is a list, so it must default to []: a route function leaves an empty list out of the URL, and the server would read the default instead.',
        ],
        'strictly.queryRead' => [
            "%s::%s() reads the query string from the request with %s, where nothing types it: a route function sends only the keys of its action's query class.",
            "A closure route reads the query string from the request with %s, where nothing types it: a route function sends only the keys of a controller action's query class.",
        ],
        'strictly.routeModelParameter' => [
            "%s::%s() takes %s $%s, which is a new, empty %s there: only an InputData's authorize() and rules() receive the route's bound models.",
        ],
        'strictly.translationDynamic' => [
            '%s() is passed a translation key built at runtime.',
            '%s is passed params built at runtime.',
        ],
        'strictly.translationKey' => [
            "Translation key '%s' names a group of messages, not one.",
            "Translation key '%s' isn't defined in locale %s.",
        ],
        'strictly.translationParams' => [
            "Translation '%s' needs the param '%s'.",
            "Translation '%s' takes no param '%s'.",
        ],
        'strictly.translationPlural' => [
            "Translation '%s' is pluralised: use %s().",
            "Translation '%s' isn't pluralised: use %s().",
        ],
    ];
}
