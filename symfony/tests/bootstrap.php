<?php

declare(strict_types=1);

use App\Kernel;
use App\Tests\Support\TestDatabaseGuard;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Bootstrap PHPUnit : provisionne la base de test dédiée `budget_test` (suffixe
 * `_test` défini dans `config/packages/doctrine.yaml`, environnement `test`) et
 * remet son schéma `public` à zéro une seule fois, avant la suite. Cela isole les
 * tests de la base de dev `budget` et rend le contrôle « schéma public sain »
 * (`DatabaseConnectionTest`) déterministe, même si `budget` est migrée.
 *
 * Tolérant à un serveur Postgres absent : les tests purs unitaires (ex.
 * Architecture) doivent tourner sans base, donc toute erreur de connexion ou de
 * provisioning est journalisée sans faire échouer le bootstrap.
 */
(static function (): void {
    try {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        // Crée `budget_test` si absente, via la logique native de Doctrine (elle
        // ouvre sa propre connexion de maintenance au serveur).
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->run(
            new ArrayInput([
                'command' => 'doctrine:database:create',
                '--if-not-exists' => true,
            ]),
            new NullOutput(),
        );

        $registry = $container->get('doctrine');
        if (!$registry instanceof ManagerRegistry) {
            return;
        }

        $connection = $registry->getConnection();
        if (!$connection instanceof Connection) {
            return;
        }

        // Garde-fou défensif (R1) : ne jamais exécuter le DROP destructeur si la
        // base ciblée n'est pas une base `_test`. Protège la base de dev `budget`
        // au cas où `dbname_suffix` repasserait à '' (CLAUDE.md : aucune action
        // destructrice hors base de test). On interrompt proprement, sans exception.
        $databaseName = $connection->getDatabase();
        if (!TestDatabaseGuard::isTestDatabase($databaseName)) {
            fwrite(
                STDERR,
                '[bootstrap] Reset du schéma public ignoré : la base ciblée '
                . '(' . ($databaseName ?? 'inconnue') . ') ne se termine pas par '
                . '"_test". Aucune action destructrice hors base de test.' . PHP_EOL,
            );
            $connection->close();
            $kernel->shutdown();

            return;
        }

        // Remise à zéro du schéma `public` de `budget_test` : garantit un état
        // vide déterministe avant la suite (contrôle « schéma public sain »).
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->close();

        $kernel->shutdown();
    } catch (\Throwable $exception) {
        fwrite(
            STDERR,
            '[bootstrap] Provisioning de budget_test ignoré (serveur Postgres absent '
            . 'ou inaccessible) : ' . $exception->getMessage() . PHP_EOL,
        );
    }
})();
