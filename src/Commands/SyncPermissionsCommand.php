<?php

namespace Aapolrac\Rbac\Commands;

use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    public $signature = 'rbac:sync
                        {--only-missing : Skip permissions that already exist in the database}';

    public $description = 'Seed permissions from the enum classes configured in config/rbac.php';

    public function handle(): int
    {
        $permissionModel = config('rbac.models.permission');
        $enumClasses = (array) config('rbac.permissions.enum_classes', []);

        if (empty($enumClasses)) {
            $this->warn('No enum classes found in config rbac.permissions.enum_classes — nothing to sync.');

            return self::SUCCESS;
        }

        $onlyMissing = (bool) $this->option('only-missing');
        $synced = 0;

        foreach ($enumClasses as $enumClass) {
            if (! enum_exists($enumClass)) {
                $this->warn("Skipping {$enumClass}: not a valid enum.");

                continue;
            }

            foreach ($enumClass::cases() as $case) {
                $name = strtolower((string) $case->value);
                $description = ucfirst(strtolower(str_replace(['_', ':'], ' ', $name)));

                if ($onlyMissing && $permissionModel::where('name', $name)->exists()) {
                    continue;
                }

                $permissionModel::updateOrCreate(
                    ['name' => $name],
                    ['description' => $description]
                );

                $synced++;
            }
        }

        $this->info("Synced {$synced} permission(s) from ".count($enumClasses).' enum class(es).');

        return self::SUCCESS;
    }
}
