<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Aapolrac\AccessControl\AccessControl
 */
class AccessControl extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aapolrac\AccessControl\AccessControl::class;
    }
}
