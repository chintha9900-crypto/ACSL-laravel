<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database the test suite may use (selected in phpunit.xml).
     */
    public const TEST_DATABASE = 'aviationclub_test';

    /**
     * Refuse to boot against anything but the dedicated MySQL test database, so
     * RefreshDatabase can never wipe the development database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $driver = $app['config']->get("database.connections.{$default}.driver");
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($driver !== 'mysql' || $database !== self::TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run: tests must use the MySQL database "%s" (set in phpunit.xml), but the default connection is "%s" using "%s".',
                self::TEST_DATABASE,
                $driver,
                $database,
            ));
        }

        return $app;
    }
}
