<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

class InstallAccessControlCommand extends Command
{
    public $signature = 'access-control:install
                        {--scope-model= : Scope model class, for example App\\Models\\Team}
                        {--scope-key= : Scope foreign key, for example team_id}
                        {--force : Overwrite the published config and migrations when possible}
                        {--config-path= : Internal testing override for the config path}
                        {--migrations-path= : Internal testing override for the migrations path}';

    public $description = 'Install the access control package config and migrations';

    public function handle(): int
    {
        $configPath = $this->configPath();
        $migrationsPath = $this->migrationsPath();
        $scopeModel = $this->resolveScopeModel();
        $scopeKey = $this->resolveScopeForeignKey($scopeModel);

        if (! $this->publishConfig($configPath, $scopeModel, $scopeKey)) {
            return self::FAILURE;
        }

        $published = $this->publishMigrations($migrationsPath);

        $this->info("Access control config written to [{$configPath}].");
        $this->info("Published {$published} migration file(s) to [{$migrationsPath}].");
        $this->newLine();
        $this->line('Next step: run `php artisan migrate` once you are happy with the generated config.');

        return self::SUCCESS;
    }

    protected function resolveScopeModel(): ?string
    {
        $configured = trim((string) $this->option('scope-model'));

        if ($configured !== '') {
            return ltrim($configured, '\\');
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $models = $this->discoverApplicationModels();

        if ($models === []) {
            $custom = trim((string) text(
                label: 'What model should access control use as its scope? (Optional)',
                placeholder: 'E.g. App\\Models\\Team',
                default: '',
                required: false,
            ));

            return $custom !== '' ? ltrim($custom, '\\') : null;
        }

        $selection = (string) search(
            label: 'What model should access control use as its scope? (Optional)',
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

                return ['__none' => 'No scope model', '__custom' => 'Custom model class'] + $filtered;
            },
            required: true,
        );

        if ($selection === '__none' || $selection === '') {
            return null;
        }

        if ($selection === '__custom') {
            $custom = trim((string) text(
                label: 'What scope model class should be used?',
                placeholder: 'E.g. App\\Models\\Team',
                default: '',
                required: true,
            ));

            return ltrim($custom, '\\');
        }

        return ltrim($selection, '\\');
    }

    protected function resolveScopeForeignKey(?string $scopeModel): string
    {
        $configured = trim((string) $this->option('scope-key'));

        if ($configured !== '') {
            return Str::snake($configured);
        }

        $default = $scopeModel !== null
            ? Str::snake(class_basename($scopeModel)).'_id'
            : 'organization_id';

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return Str::snake((string) text(
            label: 'What foreign key should be used on the pivot tables?',
            placeholder: 'E.g. team_id',
            default: $default,
            required: true,
        ));
    }

    protected function publishConfig(string $path, ?string $scopeModel, string $scopeKey): bool
    {
        if (File::exists($path) && ! (bool) $this->option('force')) {
            if (! $this->input->isInteractive() || ! confirm(
                label: "The config file [{$path}] already exists. Overwrite it?",
                default: false,
            )) {
                $this->error("Config file already exists at [{$path}]. Use --force to overwrite it.");

                return false;
            }
        }

        File::ensureDirectoryExists(dirname($path));

        File::put($path, $this->buildConfigContents($scopeModel, $scopeKey));

        return true;
    }

    protected function publishMigrations(string $directory): int
    {
        File::ensureDirectoryExists($directory);

        $published = 0;
        $timestamp = now();

        foreach (File::files(__DIR__.'/../../database/migrations') as $stub) {
            $baseName = str_replace('.stub', '', $stub->getFilename());
            $existing = collect(File::glob($directory.DIRECTORY_SEPARATOR.'*_'.$baseName))
                ->filter()
                ->values();

            if ($existing->isNotEmpty() && ! (bool) $this->option('force')) {
                continue;
            }

            $target = $existing->first();

            if (! is_string($target) || $target === '') {
                $target = $directory.DIRECTORY_SEPARATOR.$timestamp->format('Y_m_d_His').'_'.$baseName;
                $timestamp = $timestamp->addSecond();
            }

            File::copy($stub->getPathname(), $target);
            $published++;
        }

        return $published;
    }

    protected function buildConfigContents(?string $scopeModel, string $scopeKey): string
    {
        $contents = File::get(__DIR__.'/../../config/access_control.php');
        $modelValue = $scopeModel !== null ? ltrim($scopeModel, '\\').'::class' : 'null';

        $contents = str_replace("'model' => null,", "'model' => {$modelValue},", $contents);

        return str_replace("'foreign_key' => 'organization_id',", "'foreign_key' => '{$scopeKey}',", $contents);
    }

    protected function configPath(): string
    {
        $custom = trim((string) $this->option('config-path'));

        if ($custom !== '') {
            return $custom;
        }

        return config_path('access_control.php');
    }

    protected function migrationsPath(): string
    {
        $custom = trim((string) $this->option('migrations-path'));

        if ($custom !== '') {
            return $custom;
        }

        return database_path('migrations');
    }

    /**
     * @return array<string, string>
     */
    protected function discoverApplicationModels(): array
    {
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
}
