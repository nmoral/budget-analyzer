<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests d'intégration touchant réellement le Postgres de la stack (pas de mock).
 * Nécessitent que la stack tourne (`make up`, Postgres sain).
 *
 * C3.1 : SELECT 1 via la connexion DBAL -> 1.
 * C3.2 : le schéma public ne contient aucune table applicative (0).
 */
final class DatabaseConnectionTest extends KernelTestCase
{
    public function testSelectOneReturnsOne(): void
    {
        self::assertSame(1, $this->fetchInt('SELECT 1 AS ok'));
    }

    public function testPublicSchemaHasNoApplicationTable(): void
    {
        self::assertSame(
            0,
            $this->fetchInt(
                "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'",
            ),
        );
    }

    private function fetchInt(string $sql): int
    {
        $value = $this->connection()->executeQuery($sql)->fetchOne();
        if (!is_numeric($value)) {
            self::fail('Expected a numeric scalar result from: ' . $sql);
        }

        return (int) $value;
    }

    private function connection(): Connection
    {
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        if (!$connection instanceof Connection) {
            self::fail('The DBAL default connection service is not available.');
        }

        return $connection;
    }
}
