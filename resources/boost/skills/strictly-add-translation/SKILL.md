---
name: strictly-add-translation
description: "Adds translated text the Strictly Laravel way: the message in the default locale's lang file, the same key and :params in every other locale, and t() from @/generated/translations in React. Use when adding or changing any user-facing text, a lang file, a locale, or a placeholder in a message."
---
# Add a translation

The worked example adds the heading of a projects list in English (the default locale, `app.locale`) and German. The default locale's lang files are the Source of Truth: `t()` takes exactly their keys, and each message's `:params`.

## 1. Add the message to the default locale

A PHP file `lang/en/<group>.php` (nested arrays become dotted keys: `projects.index.title`) or `lang/en.json` (the key is the English text). A placeholder is `:name`; `:Name` and `:NAME` are filled by the same param:

```php title="lang/en/projects.php"
return [
    'index' => [
        'title' => 'Projects',
        'count' => ':count projects, :archived archived',
        'empty' => 'No projects yet.',
    ],
];
```

## 2. Add the same key, with the same params, to every other locale

```php title="lang/de/projects.php"
return [
    'index' => [
        'title' => 'Projekte',
        'count' => ':count Projekte, :archived archiviert',
        'empty' => 'Noch keine Projekte.',
    ],
];
```

`tests/Feature/LocaleParityTest.php` fails while any locale lacks a key or a `:param` of the default locale's, or has one it doesn't.

## 3. Render it with `t()`

Import `t` from `@/generated/translations`. A message with params takes them as the second argument; one without takes none. Param values are strings or numbers:

```tsx title="resources/js/components/projects/projects-heading.tsx"
import { t } from '@/generated/translations';

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
                    : t('projects.index.count', { count, archived })}
            </p>
        </header>
    );
}
```

A template-literal key works when every member is a key: `` t(`statuses.${status}`) `` with `status` an enum value. In PHP (flash messages, mails) use `__('projects.index.title')` as usual. A pluralised `|` message renders whole in `t()`: write separate keys instead.

Nothing to run: `composer dev` regenerates the keys when a lang file is saved. Done when `composer ci:check` passes.

## When a check fails

- `ci:check:tsc` fails with `resources/js/components/throttle-notice.tsx(4,18): error TS2345: Argument of type '"auth.throtle"' is not assignable to parameter of type 'keyof TranslationKeys'.`: the key isn't in the default locale's lang files. Add it there, or fix the spelling.
- `ci:check:tsc` fails with `resources/js/components/throttle-notice.tsx(4,37): error TS2561: Object literal may only specify known properties, but 'second' does not exist in type 'TranslationParams<"seconds">'. Did you mean to write 'seconds'?`: pass the message's own `:params`.
- `ci:check:tsc` fails with `resources/js/components/throttle-notice.tsx(4,16): error TS2554: Expected 2 arguments, but got 1.`: the message has params; pass them.
- `ci:check:test` fails with `de: auth.password is missing`: add the key to that locale.
- `ci:check:test` fails with `de: auth.throttle lacks :seconds`: that locale's message must use the same params.
