<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Aapolrac\AccessControl\Concerns\HasAccessControl;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasAccessControl;

    protected $guarded = [];
}
