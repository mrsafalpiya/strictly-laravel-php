<?php

namespace StrictlyLaravel\Install;

/**
 * The app's JS package manager, detected from its lockfile, as the Laravel installer leaves exactly one behind.
 */
enum PackageManager: string
{
    case Npm = 'npm';
    case Pnpm = 'pnpm';
    case Yarn = 'yarn';
    case Bun = 'bun';

    public static function detect(string $basePath): self
    {
        return match (true) {
            file_exists($basePath.'/pnpm-lock.yaml') => self::Pnpm,
            file_exists($basePath.'/yarn.lock') => self::Yarn,
            file_exists($basePath.'/bun.lock'), file_exists($basePath.'/bun.lockb') => self::Bun,
            default => self::Npm,
        };
    }

    /** The command that runs a package.json script. */
    public function run(string $script): string
    {
        return "{$this->value} run {$script}";
    }
}
