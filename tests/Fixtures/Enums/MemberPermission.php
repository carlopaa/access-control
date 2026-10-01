<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures\Enums;

enum MemberPermission: string
{
    case ViewAny = 'member:view-any';
    case Update = 'member:update';
}
