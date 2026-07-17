<?php

namespace Tests\Unit;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MigrantRegistryDemoSeeder;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    public function test_default_seeder_does_not_create_local_admin_outside_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        (new DatabaseSeeder)->run();

        $this->addToAssertionCount(1);
    }

    public function test_migrant_demo_seeder_is_blocked_outside_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only in the local environment');

        (new MigrantRegistryDemoSeeder)->run();
    }
}
