<?php

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Base class for tests that need the real MySQL schema.
 *
 * The migrations use MySQL-only features (CHECK constraints, generated columns),
 * so these tests run on a dedicated MySQL database, `aviationclub_test`, selected
 * in phpunit.xml (never the development database `aviationclub`). The first test
 * that touches the database runs `migrate:fresh` on it; each test then runs in a
 * transaction that is rolled back.
 *
 * One-time setup, run by a MySQL administrator (the application user is not
 * granted or expected to have CREATE DATABASE):
 *
 *   CREATE DATABASE aviationclub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *   GRANT ALL PRIVILEGES ON aviationclub_test.* TO 'aviationclub_app'@'localhost';
 *
 * Run with: php artisan test
 */
abstract class MysqlTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Explain a missing or inaccessible test database instead of a bare PDO error.
     */
    protected function beforeRefreshingDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Cannot connect to the MySQL test database "'.self::TEST_DATABASE.'". '
                .'Create it and grant the application user access (see the docblock of '.self::class.'). '
                .'Original error: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
