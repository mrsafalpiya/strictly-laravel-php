<?php

namespace StrictlyLaravel\Boost;

use Illuminate\Console\Events\CommandStarting;
use JsonException;

/**
 * Makes Laravel Boost install the Strictly Laravel guidance (Problem P-146): the core guideline in
 * resources/boost/guidelines and one skill per workflow in resources/boost/skills, which Boost reads from the installed
 * package, so they update with it.
 *
 * Boost offers a third-party package's guidance only when the app's boost.json lists the package under `packages`, and
 * the Base Kit gitignores boost.json, so every clone starts without it. Before `boost:install` and `boost:update` run,
 * this adds the package to that list (creating boost.json for `boost:install`), so `boost:install` preselects the
 * guidance, and `boost:install --no-interaction` and `boost:update` include it. The guidance is always on (ADR-0007):
 * a developer who deselects it gets it back on the next run. Boost's generated agent files (CLAUDE.md, AGENTS.md,
 * .claude/skills, …) are gitignored by the Base Kit too, so no guidance is copied into the app's committed files.
 */
final class BoostGuidance
{
    /** The name Boost lists the guidance under: the Composer package's. */
    public const string PACKAGE = 'mrsafalpiya/strictly-laravel';

    public function __construct(private readonly string $boostJson) {}

    public function beforeCommand(CommandStarting $event): void
    {
        match ($event->command) {
            'boost:install' => $this->select(createConfig: true),
            // Without a boost.json, Boost asks the developer to run boost:install first; leave that to it.
            'boost:update' => $this->select(createConfig: false),
            default => null,
        };
    }

    /**
     * Add the package to boost.json's `packages`. Returns whether the file changed. A boost.json Boost can't read either
     * is left alone, so Boost reports it.
     */
    public function select(bool $createConfig): bool
    {
        if (! is_file($this->boostJson)) {
            if (! $createConfig) {
                return false;
            }

            return $this->write(['packages' => [self::PACKAGE]]);
        }

        $contents = file_get_contents($this->boostJson);

        try {
            $config = json_decode($contents === false ? '' : $contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($config) || array_is_list($config) && $config !== []) {
            return false;
        }

        $packages = $config['packages'] ?? [];

        if (! is_array($packages) || in_array(self::PACKAGE, $packages, true)) {
            return false;
        }

        $config['packages'] = [...array_values($packages), self::PACKAGE];
        ksort($config);

        return $this->write($config);
    }

    /** @param  array<mixed>  $config */
    private function write(array $config): bool
    {
        // Boost's own format: pretty-printed, unescaped slashes, keys sorted.
        file_put_contents($this->boostJson, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        return true;
    }
}
