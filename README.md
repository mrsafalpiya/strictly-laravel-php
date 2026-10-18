# mrsafalpiya/strictly-laravel

The Composer package of [Strictly Laravel](https://github.com/mrsafalpiya/strictly-laravel), which adds end-to-end type safety to a Laravel + Inertia + React app built on the official React starter kit. You write PHP, and the TypeScript types are generated from it. The app's `composer ci:check` then fails when React reads a prop, form field, route, query key, translation or broadcast payload that the server doesn't send.

This package has the Data class base types, the type generator, the PHPStan rules, Contract recording and the `strictly:*` artisan commands. It needs the npm package [`@mrsafalpiya/strictly-laravel`](https://www.npmjs.com/package/@mrsafalpiya/strictly-laravel) at the same version.

## Install

You need PHP 8.4, Laravel 13, Node 26 and pnpm.

```bash
laravel new my-app --react --pest --pnpm
cd my-app
composer require mrsafalpiya/strictly-laravel
composer require laravel/boost --dev
pnpm add @mrsafalpiya/strictly-laravel
php artisan strictly:install
composer ci:check
```

`strictly:install` edits the app's tooling and converts the starter kit's own pages, controllers and types. It prints one row per change and never overwrites a file it doesn't recognise.

To update, follow [`UPGRADE.md`](https://github.com/mrsafalpiya/strictly-laravel/blob/main/packages/laravel/UPGRADE.md).

## Source and issues

The source of this package is in `packages/laravel` of the [strictly-laravel monorepo](https://github.com/mrsafalpiya/strictly-laravel). Open issues and pull requests there.

## License

MIT. See [LICENSE](LICENSE).
