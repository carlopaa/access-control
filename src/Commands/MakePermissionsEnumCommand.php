<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakePermissionsEnumCommand extends Command
{
    public $signature = 'access-control:make-enum
                        {name? : The enum class name}
                        {--resource= : Permission resource key, defaults to the class name}
                        {--namespace=App\\Enums : PHP namespace for the generated enum}
                        {--path= : Directory where the enum should be written}
                        {--deny : Include deny permissions}
                        {--force : Overwrite the enum file if it already exists}';

    public $description = 'Generate a backed enum for access control permissions';

    public function handle(): int
    {
        $className = $this->resolveClassName();
        $resource = $this->resolveResourceKey($className);
        $namespace = trim((string) $this->option('namespace'), '\\');
        $directory = $this->resolveDirectory();
        $filePath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$className.'.php';

        if (is_file($filePath) && ! (bool) $this->option('force')) {
            $this->error("Enum already exists at [{$filePath}]. Use --force to overwrite it.");

            return self::FAILURE;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Unable to create directory [{$directory}].");

            return self::FAILURE;
        }

        file_put_contents(
            $filePath,
            $this->buildEnumContents(
                namespace: $namespace,
                className: $className,
                resource: $resource,
                includeDeny: $this->shouldIncludeDeny(),
            )
        );

        $this->info("Permission enum created at [{$filePath}].");

        return self::SUCCESS;
    }

    protected function resolveClassName(): string
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $name = trim((string) $this->ask('What should the enum be called?', 'ModelPermission'));
        }

        return $this->qualifyClassName($name);
    }

    protected function qualifyClassName(string $name): string
    {
        $trimmed = trim($name);

        if (! str_ends_with($trimmed, 'Permission')) {
            $trimmed .= 'Permission';
        }

        return Str::studly($trimmed);
    }

    protected function resolveResourceKey(string $className): string
    {
        $configured = trim((string) $this->option('resource'));

        if ($configured === '') {
            $configured = (string) Str::of($className)
                ->beforeLast('Permission')
                ->snake()
                ->replace('_', '-')
                ->lower();

            if (trim((string) $this->argument('name')) === '') {
                $configured = trim((string) $this->ask('What model or resource should these permissions use?', $configured));
            }
        }

        return Str::of($configured)
            ->replace('\\', ' ')
            ->snake(' ')
            ->replace(' ', '-')
            ->replace('_', '-')
            ->lower()
            ->value();
    }

    protected function resolveDirectory(): string
    {
        $custom = trim((string) $this->option('path'));

        if ($custom !== '') {
            return $custom;
        }

        return app_path('Enums');
    }

    protected function shouldIncludeDeny(): bool
    {
        if ((bool) $this->option('deny')) {
            return true;
        }

        if (trim((string) $this->argument('name')) !== '') {
            return false;
        }

        return (bool) $this->confirm('Include deny permissions too?', true);
    }

    protected function buildEnumContents(
        string $namespace,
        string $className,
        string $resource,
        bool $includeDeny,
    ): string {
        $actions = ['view', 'create', 'update', 'delete'];
        $anyActions = ['view-any', 'create-any', 'update-any', 'delete-any'];
        $cases = array_merge(
            $this->buildCases($resource, $actions, false),
            $this->buildCases($resource, $anyActions, false),
            $includeDeny ? array_merge(
                $this->buildCases($resource, $actions, true),
                $this->buildCases($resource, $anyActions, true),
            ) : [],
        );
        $caseBlock = implode(PHP_EOL, $cases);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

enum {$className}: string
{
{$caseBlock}
}
PHP;
    }

    protected function buildCases(string $resource, array $actions, bool $deny): array
    {
        return collect($actions)
            ->map(function (string $action) use ($resource, $deny): string {
                $name = strtoupper(str_replace('-', '_', $action));
                $prefix = $deny ? 'DENY_' : 'ALLOW_';
                $value = $resource.':'.$action.($deny ? ':deny' : '');

                return "    case {$prefix}{$name} = '{$value}';";
            })
            ->all();
    }
}
