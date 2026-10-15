---
name: strictly-add-translation
description: "Adds translated text the Strictly Laravel way: the message in the default locale's lang file, the same key and :params in every other locale, and t() or, for a pluralised message, choice() from @/generated/translations in React. Use when adding or changing any user-facing text, a lang file, a locale, a placeholder in a message, or a message that depends on a count."
---
# Add a translation

The worked example adds the heading of a projects list in English (the default locale, `app.locale`) and German, with a count that is pluralised. The default locale's lang files are the Source of Truth: `t()` and `choice()` take exactly their keys, and each message's `:params`.

## 1. Add the message to the default locale

A PHP file `lang/en/<group>.php` (nested arrays become dotted keys: `projects.index.title`) or `lang/en.json` (the key is the English text). A placeholder is `:name`; `:Name` and `:NAME` are filled by the same param. A message whose wording depends on a count is pluralised, its forms separated by `|` as `trans_choice()` takes them: `'project|projects'` (the locale's plural rule picks the form), or with ranges, `'{0} None|{1} One|[2,*] :count'`:

```php title="lang/en/projects.php"
return [
    'index' => [
        'title' => 'Projects',
        'count' => ':count project, :archived archived|:count projects, :archived archived',
        'empty' => 'No projects yet.',
    ],
];
```

## 2. Add the same key, with the same params, to every other locale

```php title="lang/de/projects.php"
return [
    'index' => [
        'title' => 'Projekte',
        'count' => ':count Projekt, :archived archiviert|:count Projekte, :archived archiviert',
        'empty' => 'Noch keine Projekte.',
    ],
];
```

`tests/Feature/LocaleParityTest.php` fails while any locale lacks a key or a `:param` of the default locale's, or has one it doesn't, and while a locale's message is pluralised where the default locale's isn't, or the other way round. The number of forms may differ: each locale has its own plural rule.

## 3. Render it with `t()`, or `choice()` when it is pluralised

Import `t` and `choice` from `@/generated/translations`. A message with params takes them as the second argument of `t()`; one without takes none. A pluralised message takes `choice(key, count, params)`: it picks the form for `count` as `trans_choice()` does and fills `:count`, and `params` fills the other `:params` (a `count` param replaces the number shown, such as a formatted one). Param values are strings or numbers:

```tsx title="resources/js/components/projects/projects-heading.tsx"
import { choice, t } from '@/generated/translations';

export function ProjectsHeading({
    count,
    archived,
}: {
    count: number;
    archived: number;
}) {
    return (
        <header>
            <h1>{t('projects.index.title')}</h1>
            <p>
                {count === 0
                    ? t('projects.index.empty')
                    : choice('projects.index.count', count, { archived })}
            </p>
        </header>
    );
}
```

A template-literal key works when every member is a key: `` t(`statuses.${status}`) `` with `status` an enum value. In PHP (flash messages, mails) use `__('projects.index.title')` and, for a pluralised message, `trans_choice('projects.index.count', $count, ['archived' => $archived])`. PHPStan checks them as `tsc` checks `t()` and `choice()`: the key must be in the default locale's lang files (or Laravel's own, such as `validation.required`), the replacements must be exactly the message's `:params` (`trans_choice()` fills `:count` from the number), and a pluralised message goes through `trans_choice()`, any other through `__()`. The same goes for `trans()`, `Lang::get()`, `Lang::choice()` and the Translator's `get()` and `choice()`. Text that no lang file defines (`__('Profile updated.')`) renders as it is. A key built at runtime can't be checked: pass a literal key, or a union of them (`__($archived ? 'projects.flash.archived' : 'projects.flash.restored')`), or suppress `strictly.translationDynamic` with the reason every key it builds exists. A message that isn't pluralised can't hold a literal `|`, which Laravel always reads as a form separator: keep the `|` outside the message.

Nothing to run: `composer dev` regenerates the keys when a lang file is saved. Done when `composer ci:check` passes.

## When a check fails

- `ci:check:tsc` fails with `resources/js/components/throttle-notice.tsx(4,18): error TS2345: Argument of type '"auth.throtle"' is not assignable to parameter of type 'keyof TranslationKeys'.`: the key isn't in the default locale's lang files. Add it there, or fix the spelling.
- `ci:check:tsc` fails with `resources/js/components/throttle-notice.tsx(4,37): error TS2561: Object literal may only specify known properties, but 'second' does not exist in type 'TranslationParams<"seconds">'. Did you mean to write 'seconds'?`: pass the message's own `:params`.
- `ci:check:tsc` fails with `resources/js/components/throttle-notice.tsx(4,16): error TS2554: Expected 2 arguments, but got 1.`: the message has params; pass them.
- `ci:check:tsc` fails with ``resources/js/components/issue-count.tsx(4,18): error TS2345: Argument of type '"issues.count"' is not assignable to parameter of type '"issues.count is a plural message: use choice()"'.``: the message is pluralised; render it with `choice('issues.count', count)`.
- `ci:check:tsc` fails with ``resources/js/components/issue-count.tsx(4,23): error TS2345: Argument of type '"issues.title"' is not assignable to parameter of type '"issues.title is not a plural message: use t()"'.``: the message has no `|` forms; render it with `t()`.
- `ci:check:test` fails with `Translation key 'projects.flash.saevd' isn't defined in locale en.`: the key passed to `__()` (or `trans_choice()`, `Lang::get()`, …) isn't in the default locale's lang files. Add it there, or fix the spelling.
- `ci:check:test` fails with `Translation 'projects.flash.saved' needs the param 'key'.`: pass the message's own `:params` as the replacements, no more and no fewer.
- `ci:check:test` fails with `de: auth.password is missing`: add the key to that locale.
- `ci:check:test` fails with `de: auth.throttle lacks :seconds`: that locale's message must use the same params.
- `ci:check:test` fails with `de: issues.count isn't plural, but the en message is, so choice() can't pick its form`: give that locale's message `|` forms too.
