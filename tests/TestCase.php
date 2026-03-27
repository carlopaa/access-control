<?php

namespace Aapolrac\AccessControl\Tests;

use Aapolrac\AccessControl\AccessControlServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Aapolrac\\AccessControl\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [
            AccessControlServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('auth.providers.users.model', Fixtures\User::class);
    }

    protected function setUpDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        foreach (glob(__DIR__.'/../database/migrations/*.stub') as $migration) {
            $instance = include $migration;
            $instance->up();
        }
    }
}
