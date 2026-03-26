<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Commands;

use Illuminate\Console\Command;

class AccessControlCommand extends Command
{
    public $signature = 'access-control';

    public $description = 'Inspect the access control package installation';

    public function handle(): int
    {
        $this->comment('Access control package is installed.');

        return self::SUCCESS;
    }
}
