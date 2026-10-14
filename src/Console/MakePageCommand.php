<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use StrictlyLaravel\Inertia\Page;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Creates an Inertia page the Strictly Laravel way (Problems P-009, P-010): a Page class under App\Pages and its page
 * component under resources/js/pages, already in sync, then regenerates the types so `PageProps<'component'>` exists.
 *
 *     php artisan make:page projects/show  →  app/Pages/Projects/ShowPage.php + resources/js/pages/projects/show.tsx
 */
#[AsCommand(name: 'make:page')]
final class MakePageCommand extends Command
{
    protected $signature = 'make:page
        {component : The page component, such as projects/show}
        {--force : Overwrite the files if they exist}';

    protected $description = 'Create an Inertia page: a Page class and its page component';

    public function handle(Filesystem $files): int
    {
        $argument = $this->argument('component');
        $segments = is_string($argument)
            ? array_values(array_filter(preg_split('/[\/\\\\.]+/', $argument) ?: [], fn (string $segment): bool => $segment !== ''))
            : [];

        if ($segments === []) {
            $this->components->error('Name the page component, such as projects/show.');

            return self::INVALID;
        }

        /** @var class-string $class */
        $class = Page::NAMESPACE.implode('\\', array_map(Str::studly(...), $segments)).Page::SUFFIX;
        $component = Page::componentFor($class);

        $this->write($files, app_path('Pages/'.str_replace('\\', '/', Str::after($class, Page::NAMESPACE)).'.php'), $this->pageClass($class));
        $this->write($files, resource_path("js/pages/{$component}.tsx"), $this->pageComponent($component));

        $this->call('strictly:generate');

        $this->components->info("Return the page from a controller or route: return new \\{$class}(title: '…');");

        return self::SUCCESS;
    }

    private function write(Filesystem $files, string $path, string $contents): void
    {
        $relative = Str::after($path, base_path().DIRECTORY_SEPARATOR);

        if ($files->exists($path) && $this->option('force') !== true) {
            $this->components->warn("{$relative} already exists; left it alone (--force overwrites it).");

            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);
        $this->components->info("Created {$relative}");
    }

    private function pageClass(string $class): string
    {
        $namespace = Str::beforeLast($class, '\\');
        $name = class_basename($class);

        return <<<PHP
            <?php

            namespace {$namespace};

            use StrictlyLaravel\\Inertia\\Page;

            final class {$name} extends Page
            {
                public function __construct(
                    public string \$title,
                ) {}
            }

            PHP;
    }

    private function pageComponent(string $component): string
    {
        $function = Str::studly(Str::afterLast($component, '/'));

        return <<<TSX
            import { Head } from '@inertiajs/react';
            import type { PageProps } from '@/generated';

            type Props = PageProps<'{$component}'>;

            export default function {$function}({ title }: Props) {
                return (
                    <>
                        <Head title={title} />
                        <h1>{title}</h1>
                    </>
                );
            }

            TSX;
    }
}
