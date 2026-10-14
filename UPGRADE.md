# Upgrading Strictly Laravel

Strictly Laravel is two packages released together under one version: `mrsafalpiya/strictly-laravel` (Composer) and `@mrsafalpiya/strictly-laravel` (npm). An existing app picks up a release like this.

## Every update

1. **Update both packages to the same version.** `composer update mrsafalpiya/strictly-laravel` and `pnpm update @mrsafalpiya/strictly-laravel` (or your package manager's equivalent). While their versions differ, the Vite plugin prints a `[strictly]` warning in `composer dev` and in every build.
2. **Run `php artisan strictly:install` again.** It applies every hook a release added since you installed, restores any hook the app lost, and converts any Base Kit file a newer release knows how to convert, if that file still holds its upstream content. Anything else it leaves alone: files Kit Conversion already converted (by any release), Base Kit files you edited, and your `config/strictly.php`. It prints one row per hook and per Base Kit file. A hook it can't apply reads `not applied`, followed by what to do by hand; nothing is forced.
3. **Make the changes listed under [Releases](#releases)** for every release after the one you're upgrading from, up to and including the one you're upgrading to.
4. **Run `composer ci:check`.** Its first step, `ci:check:install`, runs `php artisan strictly:install --check`. That command changes nothing. It fails, and names each hook, while the installer would still change something or can't apply a hook, so you can't miss step 2, or a change from step 3 that has a check.

Running `strictly:install` on an app that is fully up to date changes nothing: every row reads `unchanged`.

### Config keys

`config/strictly.php` is yours: the installer publishes it once and never edits it again. The package merges its own defaults under yours (`mergeConfigFrom`), so your app gets any top-level key a release adds with its default value, without a change to your file. A release that needs a value from you lists it below, with a check.

## Releases

Newest first. Each entry lists the app-side changes the release needs. Each change says how to apply it and which check fails until it is applied.

### 0.1.0

The first release. Nothing to change.

## The release-check convention (for Overlay maintainers)

Every release that needs a change in existing apps adds an entry under [Releases](#releases) before it is tagged. Each change in the entry names the Problem it serves and how to apply it, and it gets a **release check**: something in `ci:check` that fails until the change is applied. Use the first of these that fits:

1. **The installer can make the change.** Make it a hook in `StrictlyLaravel\Console\InstallCommand::hooks()`. The entry then just says "applied by `strictly:install`". No other check is needed, because `ci:check:install` fails on any hook that hasn't been applied.
2. **Only the app's developer can make the change, but the installer can tell whether it's made.** Make it a hook that changes nothing. When the change isn't there, the hook throws an exception that says what to do and links its entry here (`see vendor/mrsafalpiya/strictly-laravel/UPGRADE.md#<version>`). The installer reports that hook as `not applied`, so `ci:check:install` fails until the developer makes the change. This is how a vite.config.ts the installer can't patch is handled today (scenario `vite-plugin-unpatchable-fails-install-check`).
3. **The change is in app code.** Make the check a rule in the earliest tool that can express it (PHPStan, then oxlint, then `tsc`, then a test), with a message that links the entry.
4. **Nothing can check it.** Say so in the entry ("No check: …") and say why.

A change to a file under `kit/` reaches only apps whose copy of that file still holds its upstream content. An app that already holds an earlier conversion keeps it (its hash is in `earlierConversions`, ADR-0005). If the new conversion matters to existing apps, list it under the release as a change to make by hand, with a release check from the list above.

Each release check is proven like any other change: a harness scenario where the change is missing fails with the check's message, and one where it is applied passes (see `docs/agents/developing-the-overlay.md`).
