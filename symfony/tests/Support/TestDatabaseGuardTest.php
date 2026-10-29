<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * R1 : le reset destructeur du schéma `public` ne doit s'exécuter que sur une base
 * `_test`. Test pur (sans Postgres), conforme à C5.
 */
final class TestDatabaseGuardTest extends TestCase
{
    public function testAcceptsDatabaseWhoseNameEndsWithTestSuffix(): void
    {
        self::assertTrue(TestDatabaseGuard::isTestDatabase('budget_test'));
        self::assertTrue(TestDatabaseGuard::isTestDatabase('foo_test'));
    }

    public function testRejectsDevDatabase(): void
    {
        self::assertFalse(TestDatabaseGuard::isTestDatabase('budget'));
    }

    public function testRejectsNullDatabaseName(): void
    {
        self::assertFalse(TestDatabaseGuard::isTestDatabase(null));
    }
}
