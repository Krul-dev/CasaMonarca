<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->ensureSafeTestDatabaseConfig($app);

        return $app;
    }

    private function ensureSafeTestDatabaseConfig(Application $app): void
    {
        if (! $app->environment('testing')) {
            return;
        }

        $connection = (string) $app['config']->get('database.default');

        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(sprintf(
                'Tests must use a dedicated MySQL-compatible database. Current DB_CONNECTION is [%s].',
                $connection !== '' ? $connection : 'undefined',
            ));
        }

        $database = (string) $app['config']->get(sprintf('database.connections.%s.database', $connection));

        if (
            $database === '' ||
            (! str_ends_with($database, '_test') && ! str_starts_with($database, 'test_'))
        ) {
            throw new RuntimeException(sprintf(
                'Unsafe test database configuration. Point tests to a dedicated MySQL schema such as [casamonarca_api_test]; current DB_DATABASE is [%s].',
                $database !== '' ? $database : 'undefined',
            ));
        }
    }
}
