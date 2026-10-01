<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

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
            $name = text(
                label: 'What should the enum be named?',
                placeholder: 'E.g. PostPermission',
                default: 'ModelPermission',
                required: true,
            );
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
                $configured = $this->promptForResource($configured);
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

        return confirm(
            label: 'Include deny permissions too?',
            default: true,
        );
    }

    protected function promptForResource(string $default): string
    {
        $models = $this->discoverApplicationModels();

        if ($models === []) {
            return text(
                label: 'What model or resource should these permissions use?',
                placeholder: 'E.g. post',
                default: $default,
                required: true,
            );
        }

        $selected = (string) search(
            label: 'What model should these permissions apply to?',
            placeholder: 'Start typing a model name',
            options: function (string $value) use ($models): array {
                $filtered = collect($models)
                    ->filter(function (string $label, string $class) use ($value): bool {
                        if ($value === '') {
                            return true;
                        }

                        return str_contains(Str::lower($label), Str::lower($value))
                            || str_contains(Str::lower($class), Str::lower($value));
                    })
                    ->take(10)
                    ->all();

                return ['__custom' => 'Custom resource'] + $filtered;
            },
        );

        if ($selected === '__custom') {
            return text(
                label: 'What resource key should these permissions use?',
                placeholder: 'E.g. post',
                default: $default,
                required: true,
            );
        }

        return (string) Str::of(class_basename($selected))
            ->snake()
            ->replace('_', '-')
            ->lower();
    }

    /**
     * @return array<string, string>
     */
    protected function discoverApplicationModels(): array
    {
        if (! function_exists('app_path')) {
            return [];
        }

        $modelsPath = app_path('Models');

        if (! is_dir($modelsPath)) {
            return [];
        }

        $namespace = app()->getNamespace().'Models\\';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modelsPath));
        $models = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($modelsPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $class = $namespace.str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relativePath
            );

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $models[$class] = class_basename($class);
        }

        asort($models);

        return $models;
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
