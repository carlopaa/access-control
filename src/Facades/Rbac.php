<?php

namespace Aapolrac\Rbac\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Aapolrac\Rbac\Rbac
 */
class Rbac extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aapolrac\Rbac\Rbac::class;
    }
}
