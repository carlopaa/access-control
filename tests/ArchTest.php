<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('commands extend Illuminate Console Command')
    ->expect('Aapolrac\AccessControl\Commands')
    ->toExtend('Illuminate\Console\Command');

arch('middleware implements Illuminate middleware interface')
    ->expect('Aapolrac\AccessControl\Middleware')
    ->toHaveMethod('handle');

arch('contracts are interfaces')
    ->expect('Aapolrac\AccessControl\Contracts')
    ->toBeInterfaces();

arch('concerns are traits')
    ->expect('Aapolrac\AccessControl\Concerns')
    ->toBeTraits();

arch('service provider extends spatie package service provider')
    ->expect('Aapolrac\AccessControl\AccessControlServiceProvider')
    ->toExtend('Spatie\LaravelPackageTools\PackageServiceProvider');

arch('no static state in middleware classes')
    ->expect('Aapolrac\AccessControl\Middleware')
    ->not->toExtend('Illuminate\Console\Command');

arch('support classes use strict types')
    ->expect('Aapolrac\AccessControl\Support')
    ->toUseStrictTypes();

arch('commands use strict types')
    ->expect('Aapolrac\AccessControl\Commands')
    ->toUseStrictTypes();

arch('middleware use strict types')
    ->expect('Aapolrac\AccessControl\Middleware')
    ->toUseStrictTypes();
