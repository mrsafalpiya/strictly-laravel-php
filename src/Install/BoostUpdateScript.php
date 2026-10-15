<?php

namespace StrictlyLaravel\Install;

use RuntimeException;
use stdClass;

/**
 * Makes `composer update` run `php artisan boost:update` (Problem P-146), as `laravel new` does for an app it installs
 * Laravel Boost in. Boost copies the guidelines and skills into the agent files it generates (CLAUDE.md, AGENTS.md,
 * .claude/skills, …), so this keeps them in step with the installed Strictly Laravel guidance after every update.
 */
final class BoostUpdateScript
{
    public const string COMMAND = '@php artisan boost:update --ansi';

    /**
     * Returns whether the file changed. An app whose post-update-cmd already runs boost:update in any form keeps it.
     */
    public static function ensure(string $composerJson): bool
    {
        return ComposerScripts::rewrite($composerJson, function (stdClass $scripts) use ($composerJson): stdClass {
            $commands = $scripts->{'post-update-cmd'} ?? [];

            if (is_string($commands)) {
                $commands = [$commands];
            }

            if (! is_array($commands)) {
                throw new RuntimeException("The post-update-cmd script of {$composerJson} is not a list of commands. Add \"".self::COMMAND.'" to it by hand.');
            }

            foreach ($commands as $command) {
                if (is_string($command) && str_contains($command, 'boost:update')) {
                    return $scripts;
                }
            }

            $scripts->{'post-update-cmd'} = [...array_values($commands), self::COMMAND];

            return $scripts;
        });
    }
}
